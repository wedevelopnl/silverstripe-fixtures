<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Load;

use WeDevelop\Fixtures\Schema\ClassSchema;

/**
 * The stage and version rows of a versioned record: one version, which is published or not, or a published version
 * with one draft change after it. Reached only for a versioned class, so only when silverstripe/versioned is
 * installed.
 */
final class VersionedRows
{
    public const int VERSION = 1;

    private const int DRAFT_CHANGE = 2;

    /**
     * @param array<string, array<string, mixed>> $rows by table, each with the record's ID
     * @param array<string, array<string, mixed>>|null $draftRows the rows with the record's draft values, as $rows;
     *                                                       null when it has none
     */
    public static function add(Plan $plan, ClassSchema $schema, array $rows, bool $published, ?array $draftRows): void
    {
        if ($draftRows === null) {
            self::addStage($plan, $schema, $rows, '', self::VERSION);
            if ($published) {
                self::addStage($plan, $schema, $rows, '_Live', self::VERSION);
            }

            // Without stages, write() counts every version as written to both stages
            self::addVersion($plan, $schema, $rows, self::VERSION, $published || !$schema->staged);

            return;
        }

        // Validated as published: live holds the published version, draft the change after it
        self::addStage($plan, $schema, $rows, '_Live', self::VERSION);
        self::addVersion($plan, $schema, $rows, self::VERSION, true);
        self::addStage($plan, $schema, $draftRows, '', self::DRAFT_CHANGE);
        self::addVersion($plan, $schema, $draftRows, self::DRAFT_CHANGE, false);
    }

    /**
     * @param array<string, array<string, mixed>> $rows
     */
    private static function addStage(Plan $plan, ClassSchema $schema, array $rows, string $suffix, int $version): void
    {
        $rows[$schema->baseTable]['Version'] = $version;
        foreach ($rows as $table => $row) {
            $plan->add($table . $suffix, $row);
        }
    }

    /**
     * @param array<string, array<string, mixed>> $rows
     */
    private static function addVersion(Plan $plan, ClassSchema $schema, array $rows, int $version, bool $wasPublished): void
    {
        foreach ($rows as $table => $row) {
            $versionRow = ['RecordID' => $row['ID'], ...array_diff_key($row, ['ID' => true]), 'Version' => $version];
            if ($table === $schema->baseTable) {
                $versionRow = [
                    ...$versionRow,
                    'WasPublished' => (int) $wasPublished,
                    'WasDraft' => 1,
                    'WasDeleted' => 0,
                    'AuthorID' => 0,
                    'PublisherID' => 0,
                ];
            }

            $plan->add($table . '_Versions', $versionRow);
        }
    }
}
