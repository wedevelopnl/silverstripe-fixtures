<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Reset;

/**
 * Plans a reset in phases around the reads only the database can answer: which records the ledger and the
 * configured classes select, by base table, and then the deletes from every table of their chain, stages,
 * versions and localised tables, with the many_many join rows that point at them. DatabaseReset runs the reads
 * and the deletes.
 *
 * @phpstan-type Join array{join: string, parentBase: string|null, childBase: string|null, parentField: string, childField: string}
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

    /**
     * @var array<string, Condition> the records to delete, by base table
     */
    private array $families = [];

    /**
     * @var array<string, Condition> the rows to delete, by many_many join table
     */
    private array $joinTables = [];

    /**
     * @param array<string, string> $tables existing table names by lower-case name
     */
    public function __construct(private readonly array $tables)
    {
    }

    public function addLedgerEntry(string $table, bool $isBaseTable, int $first, int $last): void
    {
        if ($isBaseTable) {
            $this->family($table)->addRange($first, $last);
        } else {
            ($this->joinTables[$table] ??= new Condition())->addRange($first, $last);
        }
    }

    /**
     * @param list<string> $classNames a reset class and its subclasses
     *
     * @return array<string, list<string>> a WHERE clause selecting their records, and its parameters
     */
    public static function classCondition(array $classNames): array
    {
        return [sprintf('"ClassName" IN (%s)', implode(', ', array_fill(0, count($classNames), '?'))) => $classNames];
    }

    /**
     * @return array<string, string> ID column by existing table of a base table that holds a record's ClassName
     */
    public function recordTables(string $baseTable): array
    {
        return $this->existing($baseTable, self::RECORD_TABLES);
    }

    /**
     * @param list<int> $ids records of the base table to delete
     */
    public function addIds(string $baseTable, array $ids): void
    {
        $this->family($baseTable)->addIds($ids);
    }

    /**
     * The rows of a join that point at a record this reset deletes, on either side; null when it deletes no
     * record of either side's class. A polymorphic side names no class to match the ID against, and is skipped.
     *
     * @param Join $join
     *
     * @return array<string, list<int>>|null a WHERE clause and its parameters
     */
    public function references(array $join): ?array
    {
        $clauses = [];
        $parameters = [];
        foreach (['parent', 'child'] as $side) {
            $base = $join[$side . 'Base'];
            $condition = $base === null ? null : $this->families[$base] ?? null;
            if ($condition !== null && !$condition->isEmpty()) {
                [$sql, $sideParameters] = $condition->toSql($join[$side . 'Field']);
                $clauses[] = $sql;
                $parameters = [...$parameters, ...$sideParameters];
            }
        }

        return $clauses === [] ? null : [implode(' OR ', $clauses) => $parameters];
    }

    /**
     * The statements that find the classes of the records to delete, each family's from its record tables.
     *
     * @return array<string, list<array{string, list<int>}>> statement and parameters, by family base table
     */
    public function classNameReads(): array
    {
        $reads = [];
        foreach ($this->families as $baseTable => $condition) {
            if ($condition->isEmpty()) {
                continue;
            }

            $reads[$baseTable] = [];
            foreach ($this->recordTables($baseTable) as $table => $column) {
                [$sql, $parameters] = $condition->toSql($column);
                $reads[$baseTable][] = [sprintf('SELECT DISTINCT "ClassName" FROM "%s" WHERE %s', $table, $sql), $parameters];
            }
        }

        return $reads;
    }

    /**
     * Every delete in the order it runs: each family from the tables of the classes it has, as a record's rows
     * live only in its own chain; then the join rows the ledger holds; then the join rows that point at a deleted
     * record.
     *
     * @param array<string, list<string>> $classNames the classes classNameReads() found, by family base table
     * @param array<string, list<string>> $classTables the tables of each found class; none for a class removed
     *                                                 from the code base, which has only its base table cleared
     * @param list<Join> $joins the many_many joins that are no through
     *
     * @return list<array{string, array<string, list<int>>}> table and WHERE clause with its parameters
     */
    public function deletes(array $classNames, array $classTables, array $joins): array
    {
        $deletes = [];
        foreach ($this->families as $baseTable => $condition) {
            if ($condition->isEmpty()) {
                continue;
            }

            $tables = [$baseTable];
            foreach ($classNames[$baseTable] ?? [] as $className) {
                $tables = [...$tables, ...$classTables[$className] ?? []];
            }

            foreach (array_unique($tables) as $table) {
                foreach ($this->existing($table, array_keys(self::SUFFIXES)) as $suffixed => $column) {
                    $deletes[] = [$suffixed, $this->where($condition, $column)];
                }
            }
        }

        foreach ($this->joinTables as $table => $condition) {
            $deletes[] = [$table, $this->where($condition, 'ID')];
        }

        foreach ($joins as $join) {
            $references = $this->references($join);
            if ($references !== null && isset($this->tables[strtolower($join['join'])])) {
                $deletes[] = [$join['join'], $references];
            }
        }

        return $deletes;
    }

    private function family(string $baseTable): Condition
    {
        return $this->families[$baseTable] ??= new Condition();
    }

    /**
     * @return array<string, list<int>>
     */
    private function where(Condition $condition, string $column): array
    {
        [$sql, $parameters] = $condition->toSql($column);

        return [$sql => $parameters];
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
}
