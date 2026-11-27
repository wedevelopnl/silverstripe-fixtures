<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Schema;

use SilverStripe\Core\ClassInfo;
use SilverStripe\ORM\DataObject;
use WeDevelop\Fixtures\Load\FixtureRecord;

/**
 * Class schemas for one load. Built per load: a test class can change the schema between loads.
 */
final class SchemaMap
{
    /**
     * @var array<string, ClassSchema>
     */
    private array $schemas = [];

    /**
     * @param list<FixtureRecord> $records
     *
     * @return array<string, ClassSchema|null> by class as written: null for one that names no DataObject class
     */
    public function forRecords(array $records): array
    {
        $schemas = [];
        foreach ($records as $record) {
            if (!array_key_exists($record->class, $schemas)) {
                $class = $this->resolve($record->class);
                $schemas[$record->class] = $class === null ? null : $this->forClass($class);
            }
        }

        return $schemas;
    }

    /**
     * The record class a fixture names, or null when it names no DataObject class.
     *
     * @return class-string<DataObject>|null
     */
    private function resolve(string $declared): ?string
    {
        $declared = ltrim($declared, '\\');
        if (!ClassInfo::exists($declared)) {
            return null;
        }

        $class = ClassInfo::class_name($declared);

        return is_a($class, DataObject::class, true) && $class !== DataObject::class ? $class : null;
    }

    /**
     * @param class-string<DataObject> $class
     */
    private function forClass(string $class): ClassSchema
    {
        return $this->schemas[$class] ??= ClassSchema::of($class);
    }
}
