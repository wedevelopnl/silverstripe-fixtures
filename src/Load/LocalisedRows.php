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
     * @param array<string, array<string, mixed>> $draftRows by table, each with the record's ID
     */
    public function add(Plan $plan, ClassSchema $schema, FixtureRecord $record, array $draftRows): void
    {
        $id = $plan->idOf($record->key());
        foreach ($record->locales as $locale => $values) {
            $localeValues = [];
            foreach ($values as $field => $value) {
                $localeValues = [...$localeValues, ...$schema->columnValues($field, $value)];
            }

            foreach ($schema->localisedTables ?? [] as $table => $fields) {
                $row = array_intersect_key([...$draftRows[$table], ...$localeValues], array_flip($fields));
                // Fluent localises a subclass table only when the write touched it; the base table always, as it
                // tells in which locales the record exists
                if ($table !== $schema->baseTable && $row === [] && count($draftRows[$table]) === 1) {
                    continue;
                }

                $this->addRow($plan, $schema, $table . self::SUFFIX, ['RecordID' => $id, 'Locale' => $locale, ...$row], $record->published === true);
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function addRow(Plan $plan, ClassSchema $schema, string $table, array $row, bool $published): void
    {
        $plan->add($table, $row);
        // A versioned class carries FluentVersionedExtension, which keeps stage and version tables per locale
        if ($schema->versioned) {
            $plan->add($table . '_Versions', [...$row, 'Version' => VersionedRows::VERSION]);
            if ($published) {
                $plan->add($table . '_Live', $row);
            }
        }
    }
}
