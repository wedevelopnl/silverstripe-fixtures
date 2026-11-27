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

    public function write(Plan $plan): void
    {
        foreach ($plan->rows() as $table => $rows) {
            // The query builder fills a column a row leaves out with NULL, which would override the column's
            // default: rows that state different columns go in separate inserts.
            $groups = [];
            foreach ($rows as $row) {
                $groups[implode(',', array_keys($row))][] = $row;
            }

            foreach ($groups as $group) {
                $this->insert($table, $group);
            }
        }
    }

    /**
     * @param non-empty-list<array<string, mixed>> $rows all stating the same columns
     */
    private function insert(string $table, array $rows): void
    {
        $chunkSize = max(1, intdiv(self::MAX_PARAMETERS, count($rows[0])));
        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            $quoted = array_map(
                static fn (array $row): array => array_combine(
                    array_map(static fn (string $column): string => '"' . $column . '"', array_keys($row)),
                    $row,
                ),
                $chunk,
            );
            SQLInsert::create('"' . $table . '"')->addRows($quoted)->execute();
        }
    }
}
