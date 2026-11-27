<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Tests\Integration;

use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;

/**
 * Reads tables as plain rows with record IDs replaced by names, so rows written through write() and
 * rows written by the loader compare equal when they describe the same records.
 */
trait RowSnapshot
{
    /**
     * @param list<string> $tables
     * @param array<string, int> $ids record ID by name
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function snapshot(array $tables, array $ids): array
    {
        $names = array_flip($ids);
        $recordTables = DataObject::getSchema()->getTableNames();
        $snapshot = [];
        foreach ($tables as $table) {
            // Draft and live rows carry the record ID; every other table numbers its own rows
            $ownsIds = in_array(preg_replace('/_Live$/', '', $table), $recordTables, true);
            $rows = [];
            foreach (DB::query(sprintf('SELECT * FROM "%s"', $table)) as $row) {
                if (!$ownsIds) {
                    // An auto-increment ID of a join, version or localised row identifies nothing
                    unset($row['ID']);
                }

                foreach ($row as $column => $value) {
                    if (str_ends_with((string) $column, 'ID') && is_numeric($value) && isset($names[(int) $value])) {
                        $row[$column] = $names[(int) $value];
                    }
                }

                $rows[] = $row;
            }

            usort($rows, static fn (array $a, array $b): int => json_encode($a) <=> json_encode($b));
            $snapshot[$table] = $rows;
        }

        return $snapshot;
    }

    /**
     * @param list<string> $tables
     */
    private function emptyTables(array $tables): void
    {
        foreach ($tables as $table) {
            DB::query(sprintf('DELETE FROM "%s"', $table));
        }
    }
}
