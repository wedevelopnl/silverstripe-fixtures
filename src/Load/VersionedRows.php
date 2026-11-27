<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Load;

use WeDevelop\Fixtures\Schema\ClassSchema;

/**
 * The stage and version rows of a versioned record: one version, which is published or not. Reached only for a
 * versioned class, so only when silverstripe/versioned is installed.
 */
final class VersionedRows
{
    public const int VERSION = 1;

    /**
     * @param array<string, array<string, mixed>> $draftRows by table, each with the record's ID
     */
    public static function add(Plan $plan, ClassSchema $schema, array $draftRows, bool $published): void
    {
        $draftRows[$schema->baseTable]['Version'] = self::VERSION;
        // Without stages, write() counts every version as written to both stages
        $wasPublished = $published || !$schema->staged;
        foreach ($draftRows as $table => $row) {
            $plan->add($table, $row);
            if ($published) {
                $plan->add($table . '_Live', $row);
            }

            $version = ['RecordID' => $row['ID'], ...array_diff_key($row, ['ID' => true]), 'Version' => self::VERSION];
            if ($table === $schema->baseTable) {
                $version = [
                    ...$version,
                    'WasPublished' => (int) $wasPublished,
                    'WasDraft' => 1,
                    'WasDeleted' => 0,
                    'AuthorID' => 0,
                    'PublisherID' => 0,
                ];
            }

            $plan->add($table . '_Versions', $version);
        }
    }
}
