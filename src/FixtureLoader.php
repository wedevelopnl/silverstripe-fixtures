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
use WeDevelop\Fixtures\Load\FixtureReader;
use WeDevelop\Fixtures\Load\PathResolver;
use WeDevelop\Fixtures\Load\RowPlanner;
use WeDevelop\Fixtures\Load\RowWriter;
use WeDevelop\Fixtures\Load\Validator;
use WeDevelop\Fixtures\Reset\LedgerReset;
use WeDevelop\Fixtures\Schema\SchemaMap;

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
     * @param string ...$paths module resource (`vendor/module:path`), absolute or base-relative fixture paths;
     *                         references resolve across all of them
     *
     * @throws InvalidFixtureException listing every problem; nothing is written then
     */
    public function load(string ...$paths): LoadedFixtures
    {
        $files = [];
        $problems = [];
        foreach ($paths as $path) {
            $resolved = PathResolver::resolve($path);
            if ($resolved === null) {
                $problems[] = $path . ': no fixture file at this path';
            } else {
                $files[$path] = $resolved;
            }
        }

        $set = (new FixtureReader())->read($files);
        $schemas = new SchemaMap();
        $problems = [...$problems, ...$set->problems, ...(new Validator($schemas))->validate($set->records)];
        if ($problems !== []) {
            throw new InvalidFixtureException($problems);
        }

        $plan = (new RowPlanner($schemas))->plan($set->records);
        $this->connection()->withTransaction(static fn () => (new RowWriter())->write($plan));
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
            $deleted = (new LedgerReset())->reset($classes);
        });
        $this->flushCaches([...$classes, ...$deleted]);
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
        foreach (array_unique($classes) as $class) {
            if (is_a($class, DataObject::class, true)) {
                DataObject::singleton($class)->flushCache();
            }
        }
    }
}
