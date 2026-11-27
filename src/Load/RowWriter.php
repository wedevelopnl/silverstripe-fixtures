<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Load;

use SilverStripe\ORM\Queries\SQLInsert;

/**
 * Inserts a plan's rows with multi-row inserts, one per table and column set.
 */
final class RowWriter
{
    /**
     * Stays below the 65,535 placeholders MySQL accepts in one prepared statement.
     */
    private const int MAX_PARAMETERS = 60000;

    /**
     * @param array<string, list<array<string, mixed>>> $rows database values by table
     */
    public function write(array $rows): void
    {
        foreach (self::batches($rows) as [$table, $batch]) {
            SQLInsert::create('"' . $table . '"')->addRows($batch)->execute();
        }
    }

    /**
     * The inserts the rows take: one per table and column set, split where it would pass the parameter limit.
     * The query builder fills a column a row leaves out with NULL, which would override the column's default, so
     * rows that state different columns go in separate inserts.
     *
     * @param array<string, list<array<string, mixed>>> $rows
     *
     * @return list<array{string, list<array<string, mixed>>}> table and rows with quoted columns, per insert
     */
    public static function batches(array $rows): array
    {
        $batches = [];
        foreach ($rows as $table => $tableRows) {
            $groups = [];
            foreach ($tableRows as $row) {
                $groups[implode(',', array_keys($row))][] = $row;
            }

            foreach ($groups as $group) {
                foreach (array_chunk($group, max(1, intdiv(self::MAX_PARAMETERS, count($group[0])))) as $chunk) {
                    $batches[] = [(string) $table, self::quoted($chunk)];
                }
            }
        }

        return $batches;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>> the rows with quoted columns
     */
    private static function quoted(array $rows): array
    {
        $quoted = [];
        foreach ($rows as $row) {
            $quoted[] = array_combine(array_map(static fn (string $column): string => '"' . $column . '"', array_keys($row)), $row);
        }

        return $quoted;
    }
}
