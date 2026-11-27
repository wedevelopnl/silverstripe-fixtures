<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Reset;

use SilverStripe\Core\ClassInfo;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataObjectSchema;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\Queries\SQLDelete;
use SilverStripe\ORM\Queries\SQLSelect;
use WeDevelop\Fixtures\FixtureLedger;

/**
 * Deletes the records the ledger holds and the records of the configured classes, from every table of their
 * chain, stages, versions and localised tables, then empties the ledger. DML only: a schema statement would end
 * an open test transaction.
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

        $deleted = [];
        foreach ($this->families as $baseTable => $condition) {
            if (!$condition->isEmpty()) {
                $deleted = [...$deleted, ...$this->deleteFamily($baseTable, $condition)];
            }
        }

        foreach ($this->joinTables as $table => $condition) {
            $this->delete($table, 'ID', $condition);
        }

        SQLDelete::create('"' . $ledgerTable . '"')->execute();

        return array_values(array_unique($deleted));
    }

    private function addLedgerEntry(string $table, int $first, int $last): void
    {
        $class = $this->schema->tableClass($table);
        if ($class !== null && $this->schema->baseDataTable($class) === $table) {
            ($this->families[$table] ??= new Condition())->addRange($first, $last);
        } else {
            ($this->joinTables[$table] ??= new Condition())->addRange($first, $last);
        }
    }

    /**
     * @param class-string<DataObject> $class
     */
    private function addResetClass(string $class): void
    {
        $baseTable = $this->schema->baseDataTable($class);
        $classNames = array_values(ClassInfo::subclassesFor($class));
        $placeholders = DB::placeholders($classNames);
        foreach ($this->existing($baseTable, ['', '_Live', '_Versions']) as $table => $column) {
            /** @var list<int|string> $ids */
            $ids = DB::prepared_query(
                sprintf('SELECT DISTINCT "%s" FROM "%s" WHERE "ClassName" IN (%s)', $column, $table, $placeholders),
                $classNames,
            )->column();
            ($this->families[$baseTable] ??= new Condition())->addIds(array_map(intval(...), $ids));
        }
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
        foreach ($this->existing($baseTable, ['', '_Live', '_Versions']) as $table => $column) {
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
