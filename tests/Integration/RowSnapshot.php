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
     * write() and publish leave a version row per write, the loader one by design: no edit history. Keeps the
     * latest version row of each record and locale, and takes the Version column out of every row, since the
     * numbers differ by that history.
     *
     * @param array<string, list<array<string, mixed>>> $snapshot
     *
     * @return array{array<string, list<array<string, mixed>>>, list<mixed>} the rows, and the Version values they held
     */
    private function latestVersionOnly(array $snapshot): array
    {
        $versions = [];
        foreach ($snapshot as $table => $rows) {
            if (str_ends_with($table, '_Versions')) {
                $latest = [];
                foreach ($rows as $row) {
                    $group = json_encode([$row['RecordID'], $row['Locale'] ?? null]);
                    if (!isset($latest[$group]) || $row['Version'] > $latest[$group]['Version']) {
                        $latest[$group] = $row;
                    }
                }

                $rows = array_values($latest);
            }

            foreach ($rows as $index => $row) {
                if (array_key_exists('Version', $row)) {
                    $versions[] = $row['Version'];
                    unset($rows[$index]['Version']);
                }
            }

            usort($rows, static fn (array $a, array $b): int => json_encode($a) <=> json_encode($b));
            $snapshot[$table] = $rows;
        }

        return [$snapshot, array_values(array_unique($versions))];
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
