<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Load;

use LogicException;
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
    public function __construct(
        private SchemaMap $schemas,
        private VersionedRows $versionedRows = new VersionedRows(),
    ) {
    }

    /**
     * @param list<FixtureRecord> $records validated
     */
    public function plan(array $records): Plan
    {
        $plan = new Plan();
        $schemas = [];
        foreach ($records as $record) {
            $schema = $this->schemaOf($record);
            $schemas[$record->key()] = $schema;
            $plan->assign($record->key(), $schema->class, $plan->claim($schema->baseTable, fn (): int => $this->floor($this->idSources($schema))));
        }

        $now = DBDatetime::now()->Rfc2822();
        foreach ($records as $record) {
            $this->planRecord($plan, $record, $schemas[$record->key()], $now);
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

    private function planRecord(Plan $plan, FixtureRecord $record, ClassSchema $schema, string $now): void
    {
        $id = $plan->idOf($record->key());
        $rows = array_fill_keys($schema->tables, ['ID' => $id]);
        foreach ($this->values($plan, $record, $schema, $now) as $column => $value) {
            $rows[$schema->columnTables[$column]][$column] = $value;
        }

        if ($schema->versioned) {
            $this->versionedRows->add($plan, $schema, $rows, $record->published === true);
        } else {
            foreach ($rows as $table => $row) {
                $plan->add($table, $row);
            }
        }

        foreach ($record->fields as $field => $value) {
            if ($schema->kind($field) === FieldKind::ManyMany && is_array($value)) {
                $this->planJoinRows($plan, $schema->class, $field, $id, $value);
            }
        }
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
            if ($kind === FieldKind::Column || $kind === FieldKind::Composite) {
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
        if ($schema->hasColumn($field . 'Class')) {
            // A polymorphic has_one also names the class of the record it points to
            $values[$field . 'Class'] = $plan->classOf($key);
        }

        return $values;
    }

    /**
     * @param class-string<DataObject> $class
     * @param array<mixed> $items references, or maps of a reference (`ref`) and extra field values
     */
    private function planJoinRows(Plan $plan, string $class, string $field, int $id, array $items): void
    {
        $schema = DataObject::getSchema();
        /** @var array{join: string, parentField: string, childField: string} $component validated as a many_many */
        $component = $schema->manyManyComponent($class, $field);
        /** @var array<string, string> $extraFields */
        $extraFields = $schema->manyManyExtraFieldsForComponent($class, $field) ?? [];
        $table = $component['join'];
        foreach ($items as $item) {
            $extras = is_array($item) ? array_diff_key($item, ['ref' => true]) : [];
            $row = [
                'ID' => $plan->claim($table, fn (): int => $this->floor([$table => 'ID'])),
                $component['parentField'] => $id,
                $component['childField'] => $plan->idOf((string) Reference::parse(is_array($item) ? $item['ref'] : $item)),
            ];
            foreach ($extras as $name => $value) {
                $row = [...$row, ...ClassSchema::convert($extraFields[$name], (string) $name, $value)];
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
     * @param array<string, string> $sources ID column by table
     */
    private function floor(array $sources): int
    {
        $maxima = [];
        foreach ($sources as $table => $column) {
            $maxima[] = sprintf('COALESCE((SELECT MAX("%s") FROM "%s"), 0)', $column, $table);
        }

        return (int) DB::query('SELECT GREATEST(' . implode(', ', $maxima) . ', 0)')->value();
    }
}
