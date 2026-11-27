<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Schema;

use SilverStripe\Assets\File;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\ManyManyThroughList;
use SilverStripe\Versioned\Versioned;
use TractorCow\Fluent\Extension\FluentExtension;
use TractorCow\Fluent\Extension\FluentVersionedExtension;
use TractorCow\Fluent\Model\Locale;

/**
 * What write() would know about a class, read from the static schema without instantiating a record. Built only by
 * of(), the one place that reads the framework's schema and config for it.
 */
final readonly class ClassSchema
{
    /**
     * @param class-string<DataObject> $class
     * @param list<string> $tables the class's tables, base first
     * @param array<string, string> $fieldTables table of every database column but ID and of every composite field
     * @param array<string, string> $specs field specification of every settable column and composite
     * @param array<string, FieldKind> $kinds what every key that is no plain value stands for
     * @param array<string, array{join: string, parentField: string, childField: string, extraFields: array<string, string>}> $joins
     *                                                     the join table, its columns and the extra field
     *                                                     specifications of every many_many that is no through
     * @param array<string, mixed> $defaults static defaults of columns and composites, leaf class first
     * @param bool $isLocale whether the class is Fluent's Locale, whose records add the locale codes a load knows
     * @param array<string, list<string>>|null $localisedTables localised columns by table; null when the class is
     *                                                     not localised
     * @param bool $localisedStages whether the localised tables have `_Live` and `_Versions` tables, which only
     *                              FluentVersionedExtension adds
     */
    public function __construct(
        public string $class,
        public string $baseTable,
        public array $tables,
        public array $fieldTables,
        public array $specs,
        private array $kinds,
        public array $joins,
        public array $defaults,
        public bool $versioned,
        public bool $staged,
        public bool $isFile,
        public bool $isLocale,
        public ?array $localisedTables,
        public bool $localisedStages,
    ) {
    }

    /**
     * @param class-string<DataObject> $class
     */
    public static function of(string $class): self
    {
        $schema = DataObject::getSchema();
        $tables = [];
        $fieldTables = [];
        foreach (ClassInfo::ancestry($class, true) as $tableClass) {
            $table = (string) $schema->tableName($tableClass);
            $tables[] = $table;
            $fields = [...$schema->databaseFields($tableClass, false), ...$schema->compositeFields($tableClass, false)];
            foreach (array_keys($fields) as $field) {
                $fieldTables[(string) $field] = $table;
            }
        }

        unset($fieldTables['ID']);
        /** @var array<string, string> $specs */
        $specs = array_merge($schema->databaseFields($class), $schema->compositeFields($class));
        $versioned = class_exists(Versioned::class) && $class::has_extension(Versioned::class);
        [$kinds, $joins] = self::relationsOf($class, $versioned);

        return new self(
            $class,
            $schema->baseDataTable($class),
            $tables,
            $fieldTables,
            $specs,
            $kinds,
            $joins,
            self::defaultsOf($class, $specs),
            $versioned,
            $versioned && self::hasStages($class),
            is_a($class, File::class, true),
            is_a($class, Locale::class, true),
            self::localisedTablesOf($class),
            class_exists(FluentVersionedExtension::class) && $class::has_extension(FluentVersionedExtension::class),
        );
    }

    public function kind(string $key): FieldKind
    {
        return $this->kinds[$key]
            ?? (isset($this->fieldTables[$key]) || isset($this->specs[$key]) ? FieldKind::Value : FieldKind::Unknown);
    }

    /**
     * The kind of every key that is no plain value, in the order kind() has always answered them: the fixed and
     * generated columns, then has_one, has_many and belongs_to, then many_many.
     *
     * @param class-string<DataObject> $class
     *
     * @return array{array<string, FieldKind>, array<string, array{join: string, parentField: string, childField: string, extraFields: array<string, string>}>}
     */
    private static function relationsOf(string $class, bool $versioned): array
    {
        $schema = DataObject::getSchema();
        $kinds = ['ID' => FieldKind::Fixed, 'ClassName' => FieldKind::Fixed];
        if ($versioned) {
            $kinds['Version'] = FieldKind::Fixed;
        }

        $kinds += array_fill_keys(array_map(strval(...), array_keys($schema->generatedFields($class))), FieldKind::Generated);
        $kinds += array_fill_keys(self::configKeys($class, 'has_one'), FieldKind::HasOne);
        $kinds += array_fill_keys([...self::configKeys($class, 'has_many'), ...self::configKeys($class, 'belongs_to')], FieldKind::HasMany);
        $joins = [];
        foreach ([...self::configKeys($class, 'many_many'), ...self::configKeys($class, 'belongs_many_many')] as $name) {
            /** @var array{relationClass: string, join: string, parentField: string, childField: string}|null $component */
            $component = isset($kinds[$name]) ? null : $schema->manyManyComponent($class, $name);
            if ($component === null) {
                continue;
            }

            if ($component['relationClass'] === ManyManyThroughList::class) {
                $kinds[$name] = FieldKind::ManyManyThrough;
                continue;
            }

            $kinds[$name] = FieldKind::ManyMany;
            /** @var array<string, string> $extraFields */
            $extraFields = $schema->manyManyExtraFieldsForComponent($class, $name) ?? [];
            $joins[$name] = [
                'join' => $component['join'],
                'parentField' => $component['parentField'],
                'childField' => $component['childField'],
                'extraFields' => $extraFields,
            ];
        }

        return [$kinds, $joins];
    }

    /**
     * @param class-string<DataObject> $class
     *
     * @return list<string> the names a relation config of the class declares, inherited ones included
     */
    private static function configKeys(string $class, string $relation): array
    {
        $declared = Config::inst()->get($class, $relation);

        return array_map(strval(...), array_keys(is_array($declared) ? $declared : []));
    }

    /**
     * Static defaults as populateDefaults() applies them: leaf class first, the first value set wins.
     *
     * @param class-string<DataObject> $class
     * @param array<string, string> $specs
     *
     * @return array<string, mixed>
     */
    private static function defaultsOf(string $class, array $specs): array
    {
        $defaults = [];
        foreach (array_reverse(ClassInfo::ancestry($class)) as $ancestor) {
            $declared = Config::inst()->get($ancestor, 'defaults', Config::UNINHERITED);
            foreach (is_array($declared) ? $declared : [] as $field => $value) {
                $field = (string) $field;
                if (!isset($defaults[$field]) && $value !== null && isset($specs[$field])) {
                    $defaults[$field] = $value;
                }
            }

            if ($ancestor === DataObject::class) {
                break;
            }
        }

        return $defaults;
    }

    /**
     * The tables Fluent localises for the class, asked of its own extension. Reached only when
     * tractorcow/silverstripe-fluent is installed.
     *
     * @param class-string<DataObject> $class
     *
     * @return array<string, list<string>>|null
     */
    private static function localisedTablesOf(string $class): ?array
    {
        if (!class_exists(FluentExtension::class)) {
            return null;
        }

        $record = DataObject::singleton($class);
        $fluent = $record->getExtensionInstance(FluentExtension::class);
        if (!$fluent instanceof FluentExtension) {
            return null;
        }

        $fluent->setOwner($record);
        try {
            /** @var array<string, list<string>> */
            return $fluent->getLocalisedTables();
        } finally {
            $fluent->clearOwner();
        }
    }

    /**
     * @param class-string<DataObject> $class
     */
    private static function hasStages(string $class): bool
    {
        $versioned = DataObject::singleton($class)->getExtensionInstance(Versioned::class);

        return $versioned instanceof Versioned && $versioned->hasStages();
    }
}
