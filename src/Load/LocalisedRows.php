<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Load;

use WeDevelop\Fixtures\Schema\ClassSchema;

/**
 * The localised rows of a record, one per locale it states, as Fluent writes them for a record saved in that
 * locale. Reached only for a localised class, so only when tractorcow/silverstripe-fluent is installed.
 */
final class LocalisedRows
{
    private const string SUFFIX = '_Localised';

    /**
     * @param array<string, array{array<string, mixed>, array<string, string>}> $draftRows by table: each row with the
     *                                                                              record's ID, and the specs of
     *                                                                              its raw values
     */
    public static function add(Plan $plan, ClassSchema $schema, FixtureRecord $record, array $draftRows): void
    {
        $id = $plan->idOf($record->key());
        foreach ($record->locales as $locale => $values) {
            foreach ($schema->localisedTables ?? [] as $table => $localised) {
                // Every table, even one the record states nothing of: a first write in a locale forces every field
                // as changed, so Fluent localises each table of the chain. A composite converts to columns of
                // which Fluent may localise only some, so the row keeps its localised columns once converted. The
                // locale's values come last: saved in that locale, they are not written over by the record's composite.
                $columns = array_fill_keys(['RecordID', 'Locale', ...$localised], true);
                $localeValues = array_intersect_key($values, $columns);
                [$row, $specs] = $draftRows[$table];
                self::addRow(
                    $plan,
                    $schema,
                    $table . self::SUFFIX,
                    ['RecordID' => $id, 'Locale' => $locale, ...array_diff_key($row, $localeValues), ...$localeValues],
                    [...$specs, ...array_intersect_key($schema->specs, $localeValues)],
                    $columns,
                    $record->published === true,
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, string> $specs
     * @param array<string, true> $columns
     */
    private static function addRow(Plan $plan, ClassSchema $schema, string $table, array $row, array $specs, array $columns, bool $published): void
    {
        $plan->add($table, $row, $specs, $columns);
        if ($schema->localisedStages) {
            $plan->add($table . '_Versions', [...$row, 'Version' => VersionedRows::VERSION], $specs, [...$columns, 'Version' => true]);
            if ($published) {
                $plan->add($table . '_Live', $row, $specs, $columns);
            }
        }
    }
}
