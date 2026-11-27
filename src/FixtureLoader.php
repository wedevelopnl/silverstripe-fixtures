<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures;

use LogicException;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\Connect\Database;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\RecursiveStagesService;
use TractorCow\Fluent\Extension\FluentExtension;
use TractorCow\Fluent\Model\Domain;
use TractorCow\Fluent\Model\Locale;
use WeDevelop\Fixtures\Load\FixtureReader;
use WeDevelop\Fixtures\Load\LoadPlanner;
use WeDevelop\Fixtures\Load\LookupFetcher;
use WeDevelop\Fixtures\Load\RowWriter;
use WeDevelop\Fixtures\Load\Validator;
use WeDevelop\Fixtures\Reset\DatabaseReset;
use WeDevelop\Fixtures\Schema\SchemaMap;
use WeDevelop\Fixtures\Schema\ValueConverter;

/**
 * Loads fixture files straight into the database in one transaction, and clears what was loaded. The entry point
 * for test states and for callers outside PHPUnit, such as a browser test module.
 */
class FixtureLoader
{
    use Configurable;
    use Injectable;

    /**
     * Classes whose records every reset removes, subclasses included, besides the records loads wrote. Name the
     * classes tests create records of, and those a cascade delete would reach.
     *
     * @config
     *
     * @var list<class-string<DataObject>>
     */
    private static array $reset_classes = [];

    /**
     * @param string ...$paths absolute or project-root-relative fixture paths, as `YamlFixture` takes them;
     *                         references resolve across all of them
     *
     * @throws InvalidFixtureException listing every problem; nothing is written then
     */
    public function load(string ...$paths): LoadedFixtures
    {
        $files = [];
        $problems = [];
        foreach ($paths as $path) {
            $file = Director::is_absolute($path) ? $path : Director::baseFolder() . '/' . $path;
            if (is_file($file)) {
                $files[$path] = $file;
            } else {
                $problems[] = $path . ': no fixture file at this path';
            }
        }

        $set = (new FixtureReader())->read($files);
        $schemas = (new SchemaMap())->forRecords($set->records);
        $validator = new Validator($schemas, class_exists(FluentExtension::class));
        $lookups = (new LookupFetcher())->fetch($validator->needs($set->records));
        $problems = [...$problems, ...$set->problems, ...$validator->problems($set->records, $lookups)];
        if ($problems !== []) {
            throw new InvalidFixtureException($problems);
        }

        if ($set->records === []) {
            return new LoadedFixtures([]);
        }

        $converter = new ValueConverter();
        $plan = (new LoadPlanner($schemas, $converter))->plan($set->records);
        $rows = $converter->rows($plan);
        $this->connection()->withTransaction(static fn () => (new RowWriter())->write($rows));
        $this->flushCaches($plan->classes());

        $ids = [];
        foreach ($set->records as $record) {
            $ids[$record->class][$record->identifier] = $plan->idOf($record->key());
        }

        return new LoadedFixtures($ids);
    }

    /**
     * Removes every record loaded since the last reset and the records of `reset_classes`.
     *
     * @throws ResetRefusedException outside the dev and test environments; nothing is deleted then
     */
    public function reset(): void
    {
        if (!Director::isDev() && !Director::isTest()) {
            throw new ResetRefusedException();
        }

        /** @var list<class-string<DataObject>> $classes */
        $classes = static::config()->get('reset_classes');
        $deleted = [];
        $this->connection()->withTransaction(static function () use ($classes, &$deleted): void {
            $deleted = (new DatabaseReset())->reset($classes);
        });
        $this->flushCaches([...$classes, ...$deleted]);
    }

    /**
     * Resets and loads the files in one transaction: an invalid file or a database error leaves the data as it was.
     *
     * @throws InvalidFixtureException listing every problem; nothing is cleared or written then
     * @throws ResetRefusedException outside the dev and test environments; nothing is deleted then
     */
    public function reload(string ...$paths): LoadedFixtures
    {
        $loaded = new LoadedFixtures([]);
        $this->connection()->withTransaction(function () use ($paths, &$loaded): void {
            $this->reset();
            $loaded = $this->load(...$paths);
        });

        return $loaded;
    }

    private function connection(): Database
    {
        return DB::get_conn() ?? throw new LogicException('There is no database connection');
    }

    /**
     * No write() ran, so nothing flushed the caches that hold records or version numbers.
     *
     * @param list<string> $classes
     */
    private function flushCaches(array $classes): void
    {
        DataObject::singleton()->flushCache();
        if (class_exists(Locale::class)) {
            // Fluent keeps the locales and domains in memory, as FluentTestState clears them; a load can add some
            Locale::clearCached();
            Domain::clearCached();
        }

        if (class_exists(RecursiveStagesService::class)) {
            // Versioned keeps whether an owner's owned records differ between stages, which a load can change
            RecursiveStagesService::reset();
        }

        // The DataObject singleton returns before its extensions' onFlushCache, where Versioned clears its version
        // numbers and Fluent its records per locale: only a class's own singleton reaches them
        foreach (array_unique($classes) as $class) {
            if (is_a($class, DataObject::class, true)) {
                DataObject::singleton($class)->flushCache();
            }
        }
    }
}
