<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Load;

use LogicException;
use WeDevelop\Fixtures\FixtureLedger;
use WeDevelop\Fixtures\Schema\ClassSchema;
use WeDevelop\Fixtures\Schema\FieldKind;

/**
 * Turns a validated record set into rows per table, the way write() would have written each record, from what the
 * database and the asset store answered: the ID floors, the folders and the files placed. The rows hold raw values
 * with their specs; the converter turns them into database values.
 */
final readonly class RowPlanner
{
    private const array TIMESTAMPS = ['Created' => true, 'LastEdited' => true];

    /**
     * @param array<string, ClassSchema|null> $schemas by class as written, of every record
     */
    public function __construct(private array $schemas, private string $ledgerTable)
    {
    }

    /**
     * Tables whose IDs each table with its own ID sequence must stay above: archived and live-only records keep
     * IDs the draft table no longer holds.
     *
     * @param list<FixtureRecord> $records validated
     *
     * @return array<string, array<string, string>> by table with its own ID sequence: ID column by table holding
     *                                              its IDs
     */
    public function idSources(array $records): array
    {
        $sources = [];
        foreach ($records as $record) {
            $schema = $this->schemaOf($record);
            $sources[$schema->baseTable] = [$schema->baseTable => 'ID'];
            if ($schema->staged) {
                $sources[$schema->baseTable][$schema->baseTable . '_Live'] = 'ID';
            }

            if ($schema->versioned) {
                $sources[$schema->baseTable][$schema->baseTable . '_Versions'] = 'RecordID';
            }

            foreach (array_keys($record->fields) as $field) {
                if ($schema->kind($field) === FieldKind::ManyMany) {
                    $join = $schema->joins[$field]['join'];
                    $sources[$join] = [$join => 'ID'];
                }
            }
        }

        return $sources;
    }

    /**
     * @param list<FixtureRecord> $records validated
     *
     * @return list<int> the site's folders a source file is placed below
     */
    public function folderIds(array $records): array
    {
        $byKey = $this->byKey($records);
        $ids = [];
        foreach ($records as $record) {
            if ($record->source === null) {
                continue;
            }

            while (($parent = Reference::parse($record->fields['Parent'] ?? null)) !== null) {
                $record = $byKey[$parent];
            }

            $id = $record->parentId();
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * Where each source file goes in the asset store: its parent folders' names and its own, public when the
     * record is published or its class has no stages, as AssetControlExtension has it.
     *
     * @param list<FixtureRecord> $records validated
     * @param array<int, string> $folders the path of each folder folderIds() named, by ID
     *
     * @return array<string, array{string, string, bool}> source path, filename and whether public, by reference
     *                                                   name
     */
    public function placements(array $records, array $folders): array
    {
        $byKey = $this->byKey($records);
        $placements = [];
        foreach ($records as $record) {
            if ($record->source !== null) {
                $public = $record->published === true || !$this->schemaOf($record)->staged;
                $placements[$record->key()] = [$record->source, $this->filename($record, $byKey, $folders), $public];
            }
        }

        return $placements;
    }

    /**
     * The Created and LastEdited each record states or has a default for, to convert: only the converted value tells
     * whether DBDatetime stores it as NULL, which write() replaces with the time of the write.
     *
     * @param list<FixtureRecord> $records validated
     *
     * @return array<string, array<string, array{string, mixed}>> spec and raw value by reference name and column
     */
    public function timestamps(array $records): array
    {
        $timestamps = [];
        foreach ($records as $record) {
            $schema = $this->schemaOf($record);
            $stated = array_intersect_key($record->fields, self::TIMESTAMPS) + array_intersect_key($schema->defaults, self::TIMESTAMPS);
            foreach ($stated as $column => $value) {
                $timestamps[$record->key()][$column] = [$schema->specs[$column], $value];
            }
        }

        return $timestamps;
    }

    /**
     * @param list<FixtureRecord> $records validated
     * @param array<string, int> $floors the highest ID each table idSources() named has handed out
     * @param array<string, mixed> $placed the File field's value of each placed file, by reference name
     * @param array<string, array<string, mixed>> $timestamps the database value of each value timestamps() named
     */
    public function plan(array $records, array $floors, string $now, array $placed, array $timestamps): Plan
    {
        $plan = new Plan($floors);
        foreach ($records as $record) {
            $schema = $this->schemaOf($record);
            $plan->assign($record->key(), $schema->class, $plan->claim($schema->baseTable));
        }

        foreach ($records as $record) {
            $key = $record->key();
            $this->planRecord($plan, $record, $this->schemaOf($record), $now, $placed[$key] ?? null, $timestamps[$key] ?? []);
        }

        foreach ($plan->ranges() as $table => [$first, $last]) {
            $plan->add($this->ledgerTable, [
                'ClassName' => FixtureLedger::class,
                'Created' => $now,
                'LastEdited' => $now,
                'TableName' => $table,
                'FirstID' => $first,
                'LastID' => $last,
            ]);
        }

        return $plan;
    }

    private function schemaOf(FixtureRecord $record): ClassSchema
    {
        return $this->schemas[$record->class] ?? throw new LogicException(sprintf('%s was not validated', $record->label()));
    }

    /**
     * @param array<string, mixed> $timestamps the database value of each Created and LastEdited the record states
     */
    private function planRecord(Plan $plan, FixtureRecord $record, ClassSchema $schema, string $now, mixed $file, array $timestamps): void
    {
        $id = $plan->idOf($record->key());
        [$values, $specs] = $this->values($plan, $record, $schema, $now, $timestamps);
        if ($file !== null) {
            $values['File'] = $file;
            $specs['File'] = $schema->specs['File'];
        }

        $rows = $this->rowsByTable($schema, $id, $values, $specs);
        if ($schema->versioned) {
            $draftRows = $record->draft === [] ? null : $this->rowsByTable($schema, $id, ...$this->draftValues($record, $schema, $values, $specs));
            VersionedRows::add($plan, $schema, $rows, $record->published === true, $draftRows);
        } else {
            foreach ($rows as $table => [$row, $rowSpecs]) {
                $plan->add($table, $row, $rowSpecs);
            }
        }

        if ($record->locales !== []) {
            LocalisedRows::add($plan, $schema, $record, $rows);
        }

        foreach ($record->fields as $field => $value) {
            if ($schema->kind($field) === FieldKind::ManyMany) {
                $this->planJoinRows($plan, $schema->joins[$field], $id, $value);
            }
        }
    }

    /**
     * Column values as write() would store them: defaults, stated values over them, then the fixed columns.
     *
     * @param array<string, mixed> $timestamps the database value of each Created and LastEdited the record states
     *
     * @return array{array<string, mixed>, array<string, string>} raw values by field, and the specs of those that
     *                                                         convert
     */
    private function values(Plan $plan, FixtureRecord $record, ClassSchema $schema, string $now, array $timestamps): array
    {
        $values = [];
        $specs = [];
        foreach ($schema->defaults as $field => $default) {
            $values[$field] = $default;
            $specs[$field] = $schema->specs[$field];
        }

        foreach ($record->fields as $field => $value) {
            $kind = $schema->kind($field);
            if ($kind === FieldKind::Value) {
                $values[$field] = $value;
                $specs[$field] = $schema->specs[$field];
            } elseif ($kind === FieldKind::HasOne) {
                foreach ($this->hasOneValues($plan, $schema, $field, $value) as $column => $columnValue) {
                    $values[$column] = $columnValue;
                    unset($specs[$column]);
                }
            }
        }

        $values['ClassName'] = $schema->class;
        unset($specs['ClassName']);
        foreach (array_keys(self::TIMESTAMPS) as $column) {
            // Converted already: a value DBDatetime stores as NULL takes the load's time
            $values[$column] = $timestamps[$column] ?? $now;
            unset($specs[$column]);
        }

        return [$values, $specs];
    }

    /**
     * The record's values with its draft values after them, validated as plain values: an edit saved after
     * publishing writes only what it changes, so a draft value of a column is not written over by its composite.
     *
     * @param array<string, mixed> $values
     * @param array<string, string> $specs
     *
     * @return array{array<string, mixed>, array<string, string>}
     */
    private function draftValues(FixtureRecord $record, ClassSchema $schema, array $values, array $specs): array
    {
        return [
            [...array_diff_key($values, $record->draft), ...$record->draft],
            [...$specs, ...array_intersect_key($schema->specs, $record->draft)],
        ];
    }

    /**
     * @param array<string, mixed> $values by field
     * @param array<string, string> $specs
     *
     * @return array<string, array{array<string, mixed>, array<string, string>}> by table: each row with the
     *                                                                         record's ID, and the specs of its
     *                                                                         raw values
     */
    private function rowsByTable(ClassSchema $schema, int $id, array $values, array $specs): array
    {
        $rows = array_fill_keys($schema->tables, ['ID' => $id]);
        $rowSpecs = array_fill_keys($schema->tables, []);
        foreach ($values as $field => $value) {
            $table = $schema->fieldTables[$field];
            $rows[$table][$field] = $value;
            if (isset($specs[$field])) {
                $rowSpecs[$table][$field] = $specs[$field];
            }
        }

        $withSpecs = [];
        foreach ($rows as $table => $row) {
            $withSpecs[$table] = [$row, $rowSpecs[$table]];
        }

        return $withSpecs;
    }

    /**
     * @return array<string, mixed>
     */
    private function hasOneValues(Plan $plan, ClassSchema $schema, string $field, mixed $reference): array
    {
        $key = (string) Reference::parse($reference);
        $values = [$field . 'ID' => $plan->idOf($key)];
        if (isset($schema->fieldTables[$field . 'Class'])) {
            // A polymorphic has_one also names the class of the record it points to
            $values[$field . 'Class'] = $plan->classOf($key);
        }

        return $values;
    }

    /**
     * @param array{join: string, parentField: string, childField: string, extraFields: array<string, string>} $join
     * @param mixed $value a list of many_many items, as validated
     */
    private function planJoinRows(Plan $plan, array $join, int $id, mixed $value): void
    {
        $table = $join['join'];
        foreach (ManyManyItem::listOf($value) ?? [] as $item) {
            $sides = [
                $join['parentField'] => $id,
                $join['childField'] => $plan->idOf((string) Reference::parse($item->reference)),
            ];
            if (!$plan->claimPair($table, $sides)) {
                // Stated before with the same extra field values, as the validator ensures
                continue;
            }

            $row = ['ID' => $plan->claim($table), ...$sides];
            $specs = [];
            foreach ($item->extras as $name => $extra) {
                $row[(string) $name] = $extra;
                $specs[(string) $name] = $join['extraFields'][$name];
            }

            $plan->add($table, $row, $specs);
        }
    }

    /**
     * The record's path in the asset store: its parent folders' names and its own.
     *
     * @param array<string, FixtureRecord> $byKey
     * @param array<int, string> $folders
     */
    private function filename(FixtureRecord $record, array $byKey, array $folders): string
    {
        $name = $record->fields['Name'] ?? '';
        $name = is_scalar($name) ? (string) $name : '';
        $parent = Reference::parse($record->fields['Parent'] ?? null);
        if ($parent !== null) {
            return $this->filename($byKey[$parent], $byKey, $folders) . '/' . $name;
        }

        return ($folders[$record->parentId()] ?? '') . $name;
    }

    /**
     * @param list<FixtureRecord> $records
     *
     * @return array<string, FixtureRecord>
     */
    private function byKey(array $records): array
    {
        $byKey = [];
        foreach ($records as $record) {
            $byKey[$record->key()] = $record;
        }

        return $byKey;
    }
}
