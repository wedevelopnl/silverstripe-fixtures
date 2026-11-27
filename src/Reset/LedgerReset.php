<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Reset;

use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataObjectSchema;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\ManyManyThroughList;
use SilverStripe\ORM\Queries\SQLDelete;
use SilverStripe\ORM\Queries\SQLSelect;
use WeDevelop\Fixtures\FixtureLedger;

/**
 * Deletes the records the ledger holds and the records of the configured classes, from every table of their
 * chain, stages, versions and localised tables, with the many_many join rows and join records that point at them,
 * then empties the ledger. DML only: a schema statement would end an open test transaction.
 */
final class LedgerReset
{
    /**
     * The tables a record's rows live in, besides its class tables, and the column holding its ID there.
     */
    private const array SUFFIXES = [
        '' => 'ID',
        '_Live' => 'ID',
        '_Versions' => 'RecordID',
        '_Localised' => 'RecordID',
        '_Localised_Live' => 'RecordID',
        '_Localised_Versions' => 'RecordID',
    ];

    /**
     * The tables that hold a record's ClassName: its base table, stage and versions.
     */
    private const array RECORD_TABLES = ['', '_Live', '_Versions'];

    private DataObjectSchema $schema;

    /**
     * @var array<string, string> existing table names by lower-case name
     */
    private array $tables;

    /**
     * @var array<string, Condition> the records to delete, by base table
     */
    private array $families;

    /**
     * @var array<string, Condition> the rows to delete, by many_many join table
     */
    private array $joinTables;

    /**
     * @param list<class-string<DataObject>> $resetClasses
     *
     * @return list<string> classes whose records were deleted
     */
    public function reset(array $resetClasses): array
    {
        $this->schema = DataObject::getSchema();
        /** @var array<string, string> $tables */
        $tables = DB::table_list();
        $this->tables = $tables;
        $ledgerTable = (string) $this->schema->tableName(FixtureLedger::class);

        $this->families = [];
        $this->joinTables = [];
        $ledger = SQLSelect::create(['"TableName"', '"FirstID"', '"LastID"'], '"' . $ledgerTable . '"')->execute();
        /** @var array{TableName: string, FirstID: int|string, LastID: int|string} $entry */
        foreach ($ledger as $entry) {
            $this->addLedgerEntry($entry['TableName'], (int) $entry['FirstID'], (int) $entry['LastID']);
        }

        foreach ($resetClasses as $class) {
            $this->addResetClass($class);
        }

        // Join records first: they are deleted as records, with their own chain
        $joins = $this->joins();
        foreach ($joins as $join) {
            if ($join['relationClass'] === ManyManyThroughList::class) {
                $this->addJoinRecords($join);
            }
        }

        $deleted = [];
        foreach ($this->families as $baseTable => $condition) {
            if (!$condition->isEmpty()) {
                $deleted = [...$deleted, ...$this->deleteFamily($baseTable, $condition)];
            }
        }

        foreach ($this->joinTables as $table => $condition) {
            $this->delete($table, 'ID', $condition);
        }

        foreach ($joins as $join) {
            $references = $this->references($join);
            if ($join['relationClass'] !== ManyManyThroughList::class && $references !== null && isset($this->tables[strtolower($join['join'])])) {
                SQLDelete::create('"' . $join['join'] . '"')->addWhere($references)->execute();
            }
        }

        SQLDelete::create('"' . $ledgerTable . '"')->execute();

        return array_values(array_unique($deleted));
    }

    private function addLedgerEntry(string $table, int $first, int $last): void
    {
        $class = $this->schema->tableClass($table);
        if ($class !== null && $this->schema->baseDataTable($class) === $table) {
            $this->family($table)->addRange($first, $last);
        } else {
            ($this->joinTables[$table] ??= new Condition())->addRange($first, $last);
        }
    }

    /**
     * @param class-string<DataObject> $class
     */
    private function addResetClass(string $class): void
    {
        $classNames = array_values(ClassInfo::subclassesFor($class));
        $this->addRecords(
            $this->schema->baseDataTable($class),
            [sprintf('"ClassName" IN (%s)', DB::placeholders($classNames)) => $classNames],
        );
    }

    /**
     * Every many_many relation, once per join table or join class: a belongs_many_many shares its many_many's.
     *
     * @return list<array{relationClass: string, parentClass: class-string<DataObject>, childClass: class-string<DataObject>, parentField: string, childField: string, join: string}>
     */
    private function joins(): array
    {
        $joins = [];
        foreach (ClassInfo::subclassesFor(DataObject::class, false) as $class) {
            $manyMany = Config::inst()->get($class, 'many_many', Config::UNINHERITED);
            foreach (array_keys(is_array($manyMany) ? $manyMany : []) as $name) {
                /** @var array{relationClass: string, parentClass: class-string<DataObject>, childClass: class-string<DataObject>, parentField: string, childField: string, join: string} $join */
                $join = $this->schema->manyManyComponent($class, (string) $name);
                $joins[$join['join'] . '.' . $join['parentField'] . '.' . $join['childField']] = $join;
            }
        }

        return array_values($joins);
    }

    /**
     * Adds the records of a many_many through join class that point at a record this reset deletes.
     *
     * @param array{parentClass: class-string<DataObject>, childClass: class-string<DataObject>, parentField: string, childField: string, join: string} $join
     */
    private function addJoinRecords(array $join): void
    {
        $references = $this->references($join);
        if ($references !== null) {
            $this->addRecords($this->schema->baseDataTable($join['join']), $references);
        }
    }

    /**
     * Adds the records of a base table that match a WHERE clause in any of their record tables.
     *
     * @param array<string, list<int|string>> $where a WHERE clause and its parameters
     */
    private function addRecords(string $baseTable, array $where): void
    {
        $family = $this->family($baseTable);
        foreach ($this->existing($baseTable, self::RECORD_TABLES) as $table => $column) {
            /** @var list<int|string> $ids */
            $ids = SQLSelect::create('DISTINCT "' . $column . '"', '"' . $table . '"', $where)->execute()->column();
            $family->addIds(array_map(intval(...), $ids));
        }
    }

    private function family(string $baseTable): Condition
    {
        return $this->families[$baseTable] ??= new Condition();
    }

    /**
     * The rows of a join that point at a record this reset deletes, on either side; null when it deletes no
     * record of either side's class. A polymorphic side names no class to match the ID against, and is skipped.
     *
     * @param array{parentClass: class-string<DataObject>, childClass: class-string<DataObject>, parentField: string, childField: string} $join
     *
     * @return array<string, list<int>>|null a WHERE clause and its parameters
     */
    private function references(array $join): ?array
    {
        $clauses = [];
        $parameters = [];
        foreach (['parent', 'child'] as $side) {
            $class = $join[$side . 'Class'];
            $condition = $class === DataObject::class ? null : $this->families[$this->schema->baseDataTable($class)] ?? null;
            if ($condition !== null && !$condition->isEmpty()) {
                [$sql, $sideParameters] = $condition->toSql($join[$side . 'Field']);
                $clauses[] = $sql;
                $parameters = [...$parameters, ...$sideParameters];
            }
        }

        return $clauses === [] ? null : [implode(' OR ', $clauses) => $parameters];
    }

    /**
     * Deletes the selected records of one base table from the tables of the classes they have: a record's rows
     * live only in its own chain, so the tables of other subclasses are skipped.
     *
     * @return list<string> the classes of the deleted records
     */
    private function deleteFamily(string $baseTable, Condition $condition): array
    {
        $found = [];
        foreach ($this->existing($baseTable, self::RECORD_TABLES) as $table => $column) {
            [$sql, $parameters] = $condition->toSql($column);
            /** @var list<string> $classNames */
            $classNames = DB::prepared_query(sprintf('SELECT DISTINCT "ClassName" FROM "%s" WHERE %s', $table, $sql), $parameters)->column();
            $found = [...$found, ...$classNames];
        }

        // A class removed from the code base since has no ancestry, so only the base table is cleared for it
        $classNames = array_values(array_unique($found));
        $tables = [$baseTable];
        foreach ($classNames as $className) {
            foreach (ClassInfo::ancestry($className, true) as $tableClass) {
                $tables[] = (string) $this->schema->tableName($tableClass);
            }
        }

        foreach (array_unique($tables) as $table) {
            foreach ($this->existing($table, array_keys(self::SUFFIXES)) as $suffixed => $column) {
                $this->delete($suffixed, $column, $condition);
            }
        }

        return $classNames;
    }

    /**
     * @param list<string> $suffixes
     *
     * @return array<string, string> ID column by existing table
     */
    private function existing(string $table, array $suffixes): array
    {
        $existing = [];
        foreach ($suffixes as $suffix) {
            if (isset($this->tables[strtolower($table . $suffix)])) {
                $existing[$this->tables[strtolower($table . $suffix)]] = self::SUFFIXES[$suffix];
            }
        }

        return $existing;
    }

    private function delete(string $table, string $column, Condition $condition): void
    {
        [$sql, $parameters] = $condition->toSql($column);
        SQLDelete::create('"' . $table . '"')->addWhere([$sql => $parameters])->execute();
    }
}
