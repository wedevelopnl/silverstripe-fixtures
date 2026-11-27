<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Schema;

use SilverStripe\Assets\File;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataObjectSchema;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\ORM\ManyManyThroughList;
use SilverStripe\Versioned\Versioned;

/**
 * What write() would know about a class, read from the static schema without instantiating a record.
 */
final readonly class ClassSchema
{
    /**
     * @param class-string<DataObject> $class
     * @param list<string> $tables the class's tables, base first
     * @param array<string, string> $columnTables table of every database column but ID
     * @param array<string, string> $specs field specification of every settable column and composite
     * @param array<string, string> $generated
     * @param array<string, mixed> $defaults static defaults of columns and composites, leaf class first
     */
    public function __construct(
        public string $class,
        public string $baseTable,
        public array $tables,
        public array $columnTables,
        private array $specs,
        private array $generated,
        public array $defaults,
        public bool $versioned,
        public bool $staged,
        public bool $isFile,
    ) {
    }

    /**
     * @param class-string<DataObject> $class
     */
    public static function of(string $class): self
    {
        $schema = DataObject::getSchema();
        $tables = [];
        $columnTables = [];
        foreach (ClassInfo::ancestry($class, true) as $tableClass) {
            $table = (string) $schema->tableName($tableClass);
            $tables[] = $table;
            foreach (array_keys($schema->databaseFields($tableClass, false)) as $column) {
                $columnTables[(string) $column] = $table;
            }
        }

        unset($columnTables['ID']);
        /** @var array<string, string> $specs */
        $specs = array_merge($schema->databaseFields($class), $schema->compositeFields($class));
        /** @var array<string, string> $generated */
        $generated = $schema->generatedFields($class);
        $versioned = class_exists(Versioned::class) && $class::has_extension(Versioned::class);

        return new self(
            $class,
            $schema->baseDataTable($class),
            $tables,
            $columnTables,
            $specs,
            $generated,
            self::defaultsOf($class, $specs),
            $versioned,
            $versioned && self::hasStages($class),
            is_a($class, File::class, true),
        );
    }

    public function kind(string $key): FieldKind
    {
        $schema = DataObject::getSchema();

        return match (true) {
            in_array($key, ['ID', 'ClassName'], true), $this->versioned && $key === 'Version' => FieldKind::Fixed,
            isset($this->generated[$key]) => FieldKind::Generated,
            $schema->hasOneComponent($this->class, $key) !== null => FieldKind::HasOne,
            $schema->hasManyComponent($this->class, $key) !== null,
            $schema->belongsToComponent($this->class, $key) !== null => FieldKind::HasMany,
            $this->isManyManyThrough($schema, $key) => FieldKind::ManyManyThrough,
            $schema->manyManyComponent($this->class, $key) !== null => FieldKind::ManyMany,
            isset($this->columnTables[$key]) => FieldKind::Column,
            isset($this->specs[$key]) => FieldKind::Composite,
            default => FieldKind::Unknown,
        };
    }

    public function hasColumn(string $column): bool
    {
        return isset($this->columnTables[$column]);
    }

    /**
     * The database values a column or composite takes for a value, converted by its field type as write() does.
     *
     * @return array<string, mixed>
     */
    public function columnValues(string $key, mixed $value): array
    {
        return self::convert($this->specs[$key], $key, $value);
    }

    /**
     * @return array<string, mixed>
     */
    public static function convert(string $spec, string $name, mixed $value): array
    {
        $field = Injector::inst()->create($spec, $name);
        assert($field instanceof DBField);
        $field->setValue($value);
        $manipulation = [];
        $field->writeToManipulation($manipulation);
        $fields = $manipulation['fields'] ?? [];

        $values = [];
        foreach (is_array($fields) ? $fields : [] as $column => $value) {
            $values[(string) $column] = $value;
        }

        return $values;
    }

    private function isManyManyThrough(DataObjectSchema $schema, string $key): bool
    {
        $component = $schema->manyManyComponent($this->class, $key);

        return is_array($component) && $component['relationClass'] === ManyManyThroughList::class;
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
     * @param class-string<DataObject> $class
     */
    private static function hasStages(string $class): bool
    {
        $versioned = DataObject::singleton($class)->getExtensionInstance(Versioned::class);

        return $versioned instanceof Versioned && $versioned->hasStages();
    }
}
