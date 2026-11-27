<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Load;

use LogicException;
use SilverStripe\Assets\Folder;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\FieldType\DBDatetime;
use WeDevelop\Fixtures\FixtureLedger;
use WeDevelop\Fixtures\Schema\ClassSchema;
use WeDevelop\Fixtures\Schema\FieldKind;
use WeDevelop\Fixtures\Schema\SchemaMap;

/**
 * Turns a validated record set into rows per table, the way write() would have written each record.
 */
final readonly class RowPlanner
{
    public function __construct(private SchemaMap $schemas)
    {
    }

    /**
     * @param list<FixtureRecord> $records validated
     */
    public function plan(array $records): Plan
    {
        $schemas = [];
        $sources = [];
        foreach ($records as $record) {
            $schema = $this->schemaOf($record);
            $schemas[$record->key()] = $schema;
            $sources[$schema->baseTable] = $this->idSources($schema);
            foreach ($this->joinTables($record, $schema) as $table) {
                $sources[$table] = [$table => 'ID'];
            }
        }

        $plan = new Plan($this->floors($sources));
        foreach ($records as $record) {
            $schema = $schemas[$record->key()];
            $plan->assign($record->key(), $schema->class, $plan->claim($schema->baseTable));
        }

        $files = $this->placeFiles($records, $schemas);
        $now = DBDatetime::now()->Rfc2822();
        foreach ($records as $record) {
            $this->planRecord($plan, $record, $schemas[$record->key()], $now, $files[$record->key()] ?? []);
        }

        foreach ($plan->ranges() as $table => [$first, $last]) {
            $plan->add((string) DataObject::getSchema()->tableName(FixtureLedger::class), [
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
        $class = $this->schemas->resolve($record->class)
            ?? throw new LogicException(sprintf('%s was not validated', $record->label()));

        return $this->schemas->forClass($class);
    }

    /**
     * @param array<string, mixed> $fileValues the File field's columns, for a record with a source file
     */
    private function planRecord(Plan $plan, FixtureRecord $record, ClassSchema $schema, string $now, array $fileValues): void
    {
        $id = $plan->idOf($record->key());
        $values = [...$this->values($plan, $record, $schema, $now), ...$fileValues];
        $rows = $this->rowsByTable($schema, $id, $values);

        if ($schema->versioned) {
            $draftRows = $record->draft === [] ? null : $this->rowsByTable($schema, $id, $this->draftValues($record, $schema, $values));
            VersionedRows::add($plan, $schema, $rows, $record->published === true, $draftRows);
        } else {
            foreach ($rows as $table => $row) {
                $plan->add($table, $row);
            }
        }

        if ($record->locales !== []) {
            LocalisedRows::add($plan, $schema, $record, $rows);
        }

        foreach ($record->fields as $field => $value) {
            if ($schema->kind($field) === FieldKind::ManyMany) {
                $this->planJoinRows($plan, $schema->class, $field, $id, $value);
            }
        }
    }

    /**
     * The record's column values with its draft values over them, validated as plain values.
     *
     * @param array<string, mixed> $values by column
     *
     * @return array<string, mixed>
     */
    private function draftValues(FixtureRecord $record, ClassSchema $schema, array $values): array
    {
        foreach ($record->draft as $field => $value) {
            $values = [...$values, ...$schema->columnValues($field, $value)];
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $values by column
     *
     * @return array<string, array<string, mixed>> by table, each with the record's ID
     */
    private function rowsByTable(ClassSchema $schema, int $id, array $values): array
    {
        $rows = array_fill_keys($schema->tables, ['ID' => $id]);
        foreach ($values as $column => $value) {
            $rows[$schema->columnTables[$column]][$column] = $value;
        }

        return $rows;
    }

    /**
     * @return list<string> the join tables of the record's many_many values
     */
    private function joinTables(FixtureRecord $record, ClassSchema $schema): array
    {
        $tables = [];
        foreach (array_keys($record->fields) as $field) {
            if ($schema->kind($field) === FieldKind::ManyMany) {
                /** @var array{join: string} $component validated as a many_many */
                $component = DataObject::getSchema()->manyManyComponent($schema->class, $field);
                $tables[] = $component['join'];
            }
        }

        return $tables;
    }

    /**
     * Places the source files before anything is written; a failed load leaves them in the store, as a reset
     * leaves files alone.
     *
     * @param list<FixtureRecord> $records
     * @param array<string, ClassSchema> $schemas by reference name
     *
     * @return array<string, array<string, mixed>> the File field's columns by reference name
     */
    private function placeFiles(array $records, array $schemas): array
    {
        $byKey = [];
        foreach ($records as $record) {
            $byKey[$record->key()] = $record;
        }

        $files = [];
        foreach ($records as $record) {
            if ($record->source === null) {
                continue;
            }

            $schema = $schemas[$record->key()];
            $placed = FilePlacer::place(
                $record->source,
                $this->filename($record, $byKey),
                // Without stages a file is as public as its record, as AssetControlExtension has it
                $record->published === true || !$schema->staged,
            );
            $files[$record->key()] = $schema->columnValues('File', $placed);
        }

        return $files;
    }

    /**
     * The record's path in the asset store: its parent folders' names and its own.
     *
     * @param array<string, FixtureRecord> $byKey
     */
    private function filename(FixtureRecord $record, array $byKey): string
    {
        $name = $record->fields['Name'] ?? '';
        $name = is_scalar($name) ? (string) $name : '';
        $parent = Reference::parse($record->fields['Parent'] ?? null);
        if ($parent !== null) {
            return $this->filename($byKey[$parent], $byKey) . '/' . $name;
        }

        $parentId = $record->fields['ParentID'] ?? 0;
        $folder = is_numeric($parentId) && $parentId > 0 ? Folder::get()->byID((int) $parentId) : null;

        return ($folder?->getFilename() ?? '') . $name;
    }

    /**
     * Column values as write() would store them: defaults, stated values over them, then the fixed columns.
     *
     * @return array<string, mixed>
     */
    private function values(Plan $plan, FixtureRecord $record, ClassSchema $schema, string $now): array
    {
        $values = [];
        foreach ($schema->defaults as $field => $default) {
            $values = [...$values, ...$schema->columnValues($field, $default)];
        }

        foreach ($record->fields as $field => $value) {
            $kind = $schema->kind($field);
            if ($kind === FieldKind::Value) {
                $values = [...$values, ...$schema->columnValues($field, $value)];
            } elseif ($kind === FieldKind::HasOne) {
                $values = [...$values, ...$this->hasOneValues($plan, $schema, $field, $value)];
            }
        }

        return [
            ...$values,
            'ClassName' => $schema->class,
            'Created' => $values['Created'] ?? $now,
            'LastEdited' => $values['LastEdited'] ?? $now,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function hasOneValues(Plan $plan, ClassSchema $schema, string $field, mixed $reference): array
    {
        $key = (string) Reference::parse($reference);
        $values = [$field . 'ID' => $plan->idOf($key)];
        if (isset($schema->columnTables[$field . 'Class'])) {
            // A polymorphic has_one also names the class of the record it points to
            $values[$field . 'Class'] = $plan->classOf($key);
        }

        return $values;
    }

    /**
     * @param class-string<DataObject> $class
     * @param mixed $value a list of many_many items, as validated
     */
    private function planJoinRows(Plan $plan, string $class, string $field, int $id, mixed $value): void
    {
        $schema = DataObject::getSchema();
        /** @var array{join: string, parentField: string, childField: string} $component validated as a many_many */
        $component = $schema->manyManyComponent($class, $field);
        /** @var array<string, string> $extraFields */
        $extraFields = $schema->manyManyExtraFieldsForComponent($class, $field) ?? [];
        $table = $component['join'];
        foreach (ManyManyItem::listOf($value) ?? [] as $item) {
            $sides = [
                $component['parentField'] => $id,
                $component['childField'] => $plan->idOf((string) Reference::parse($item->reference)),
            ];
            if (!$plan->claimPair($table, $sides)) {
                // Stated before with the same extra field values, as the validator ensures
                continue;
            }

            $row = ['ID' => $plan->claim($table), ...$sides];
            foreach ($item->extras as $name => $extra) {
                $row = [...$row, ...ClassSchema::convert($extraFields[$name], (string) $name, $extra)];
            }

            $plan->add($table, $row);
        }
    }

    /**
     * Tables whose IDs a new record of the class must stay above: archived and live-only records keep IDs the
     * draft table no longer holds.
     *
     * @return array<string, string> ID column by table
     */
    private function idSources(ClassSchema $schema): array
    {
        $sources = [$schema->baseTable => 'ID'];
        if ($schema->staged) {
            $sources[$schema->baseTable . '_Live'] = 'ID';
        }

        if ($schema->versioned) {
            $sources[$schema->baseTable . '_Versions'] = 'RecordID';
        }

        return $sources;
    }

    /**
     * The highest ID each table has handed out, in one query: above every ID its tables hold, and below its
     * auto-increment counter, so an ID a reset freed is never handed out again.
     *
     * @param array<string, array<string, string>> $sources by table with its own ID sequence: ID column by table
     *                                                     holding its IDs
     *
     * @return array<string, int>
     */
    private function floors(array $sources): array
    {
        $columns = [];
        $parameters = [];
        foreach ($sources as $table => $tables) {
            $maxima = ['COALESCE((SELECT "AUTO_INCREMENT" - 1 FROM "information_schema"."TABLES" WHERE "TABLE_SCHEMA" = DATABASE() AND "TABLE_NAME" = ?), 0)'];
            $parameters[] = $table;
            foreach ($tables as $source => $column) {
                $maxima[] = sprintf('COALESCE((SELECT MAX("%s") FROM "%s"), 0)', $column, $source);
            }

            $columns[] = sprintf('GREATEST(%s) AS "%s"', implode(', ', $maxima), $table);
        }

        $row = $this->withLiveStatistics(
            static fn (): array => DB::prepared_query('SELECT ' . implode(', ', $columns), $parameters)->record(),
        );
        $floors = [];
        foreach ($row as $table => $floor) {
            $floors[(string) $table] = is_numeric($floor) ? (int) $floor : 0;
        }

        return $floors;
    }

    /**
     * MySQL 8 serves information_schema's AUTO_INCREMENT from a statistics cache that is a day old by default;
     * MariaDB has no such cache, nor the variable.
     *
     * @template T
     *
     * @param callable(): T $query
     *
     * @return T
     */
    private function withLiveStatistics(callable $query): mixed
    {
        $expiry = DB::query("SHOW VARIABLES LIKE 'information_schema_stats_expiry'")->record()['Value'] ?? null;
        if (!is_numeric($expiry)) {
            return $query();
        }

        DB::query('SET SESSION information_schema_stats_expiry = 0');
        try {
            return $query();
        } finally {
            DB::query('SET SESSION information_schema_stats_expiry = ' . (int) $expiry);
        }
    }
}
