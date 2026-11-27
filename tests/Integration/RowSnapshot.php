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
     * write() and publish leave a version row per write; the loader only the versions a record ends with: the
     * published one, and a draft change after it. Keeps the newest $count version rows of each record and locale and
     * numbers them from 1, in the version rows and in the draft and live rows that name them, so rows compare equal
     * when they hold the same versions in the same order. A draft or live row naming an older version gets none.
     *
     * @param array<string, list<array<string, mixed>>> $snapshot
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function latestVersions(array $snapshot, int $count): array
    {
        $numbers = [];
        foreach ($snapshot as $table => $rows) {
            if (!str_ends_with($table, '_Versions')) {
                continue;
            }

            $versions = [];
            foreach ($rows as $row) {
                $versions[$this->versionOwner($row)][] = $row['Version'];
            }

            foreach ($versions as $owner => $owned) {
                sort($owned);
                $kept = array_slice($owned, -$count);
                $numbers[$table][$owner] = array_combine($kept, range(1, count($kept)));
            }
        }

        foreach ($snapshot as $table => $rows) {
            $versionsTable = str_ends_with($table, '_Versions') ? $table : preg_replace('/_Live$/', '', $table) . '_Versions';
            $renumbered = [];
            foreach ($rows as $row) {
                if (array_key_exists('Version', $row)) {
                    $row['Version'] = $numbers[$versionsTable][$this->versionOwner($row)][$row['Version']] ?? null;
                    if ($row['Version'] === null && $versionsTable === $table) {
                        continue;
                    }
                }

                $renumbered[] = $row;
            }

            usort($renumbered, static fn (array $a, array $b): int => json_encode($a) <=> json_encode($b));
            $snapshot[$table] = $renumbered;
        }

        return $snapshot;
    }

    /**
     * @param array<string, mixed> $row a draft, live or version row
     */
    private function versionOwner(array $row): string
    {
        return (string) json_encode([$row['RecordID'] ?? $row['ID'], $row['Locale'] ?? null]);
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
