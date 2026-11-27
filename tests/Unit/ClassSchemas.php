<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Tests\Unit;

use LogicException;
use SilverStripe\ORM\DataObject;
use WeDevelop\Fixtures\Schema\ClassSchema;
use WeDevelop\Fixtures\Schema\FieldKind;

/**
 * Class schemas built by hand, as ClassSchema::of() would build them for a class, without the framework.
 */
final class ClassSchemas
{
    /**
     * @param class-string<DataObject> $class
     * @param array<string, string> $fields spec of every field besides the fixed columns, in the base table unless
     *                                      $fieldTables places it
     * @param array<string, string> $fieldTables table of a field outside the base table
     * @param array<string, FieldKind> $kinds kind of every key that is no plain value besides ID and ClassName
     * @param array<string, array{join: string, parentField: string, childField: string, extraFields: array<string, string>}> $joins
     * @param array<string, LogicException> $unresolvable
     * @param array<string, mixed> $defaults
     * @param list<string> $tables the class's tables below the base table
     * @param array<string, list<string>>|null $localisedTables
     */
    public static function of(
        string $class,
        string $baseTable,
        array $fields = [],
        array $fieldTables = [],
        array $kinds = [],
        array $joins = [],
        array $unresolvable = [],
        array $defaults = [],
        array $tables = [],
        bool $versioned = false,
        bool $staged = false,
        bool $isFile = false,
        bool $isLocale = false,
        ?array $localisedTables = null,
        bool $localisedStages = false,
    ): ClassSchema {
        $fields = ['ClassName' => 'DBClassName', 'LastEdited' => 'DBDatetime', 'Created' => 'DBDatetime', ...$fields];
        $kinds = ['ID' => FieldKind::Fixed, 'ClassName' => FieldKind::Fixed, ...$kinds];
        if ($versioned) {
            $fields['Version'] = 'Int';
            $kinds['Version'] = FieldKind::Fixed;
        }

        return new ClassSchema(
            $class,
            $baseTable,
            [$baseTable, ...$tables],
            [...array_fill_keys(array_keys($fields), $baseTable), ...$fieldTables],
            $fields,
            $kinds,
            $joins,
            $unresolvable,
            $defaults,
            $versioned,
            $staged,
            $isFile,
            $isLocale,
            $localisedTables,
            $localisedStages,
        );
    }
}
