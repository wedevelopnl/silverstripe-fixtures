<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Load;

use WeDevelop\Fixtures\Schema\ClassSchema;
use WeDevelop\Fixtures\Schema\FieldKind;

/**
 * Owns the invariant that nothing is written from an invalid set: it lists every problem, each naming the file,
 * the record and the field. What only the site can answer it first names in a lookup request, then judges from
 * the lookups.
 */
final readonly class Validator
{
    /**
     * The columns that decide where a file is in the store. A file is placed once, by the published values, so a
     * draft value would leave the draft row naming a file the store does not have there.
     */
    private const array FILE_PLACE = ['Name', 'ParentID', 'File', 'FileFilename', 'FileHash', 'FileVariant'];

    /**
     * @param array<string, ClassSchema|null> $schemas by class as written, of every record: null for a class that
     *                                                names no record class
     * @param bool $translatable whether the translation module, tractorcow/silverstripe-fluent, is installed
     */
    public function __construct(private array $schemas, private bool $translatable)
    {
    }

    /**
     * What the set needs looked up: nothing for a set without values per language, files or source files.
     *
     * @param list<FixtureRecord> $records
     */
    public function needs(array $records): LookupRequest
    {
        $locales = false;
        $files = [];
        $sources = [];
        foreach ($records as $record) {
            $locales = $locales || $record->locales !== [];
            $schema = $this->schemas[$record->class] ?? null;
            if ($schema === null || !$schema->isFile) {
                continue;
            }

            if ($record->source !== null) {
                $sources[] = $record->source;
            }

            $name = $record->fields['Name'] ?? null;
            if (is_string($name) && Reference::parse($record->fields['Parent'] ?? null) === null) {
                $files[] = [$name, $record->parentId()];
            }
        }

        return new LookupRequest($locales && $this->translatable, $files, $sources);
    }

    /**
     * @param list<FixtureRecord> $records
     * @param Lookups $lookups the answers to needs() for the same records
     *
     * @return list<string>
     */
    public function problems(array $records, Lookups $lookups): array
    {
        $keys = [];
        foreach ($records as $record) {
            $keys[$record->key()] = $record;
        }

        $problems = [];
        $places = [];
        $pairs = [];
        $localeCodes = $this->localeCodes($records, $lookups);
        foreach ($records as $record) {
            $schema = $this->schemas[$record->class] ?? null;
            if ($schema === null) {
                $problems[] = sprintf('%s: unknown class "%s"', $record->label(), $record->class);
                continue;
            }

            $recordProblems = [...$this->recordProblems($record, $schema, $keys, $localeCodes, $lookups), ...$this->joinPairProblems($record, $schema, $keys, $pairs)];
            if ($schema->isFile) {
                $recordProblems = [...$recordProblems, ...$this->samePlaceProblems($record, $places), ...$this->parentCycleProblems($record, $keys)];
            }

            foreach ($recordProblems as $problem) {
                $problems[] = $record->label() . ': ' . $problem;
            }
        }

        return $problems;
    }

    /**
     * @param array<string, FixtureRecord> $keys every record of the load, by reference name
     * @param array<string, true> $localeCodes
     *
     * @return list<string>
     */
    private function recordProblems(FixtureRecord $record, ClassSchema $schema, array $keys, array $localeCodes, Lookups $lookups): array
    {
        $problems = [];
        foreach ($record->fields as $field => $value) {
            $problem = $this->fieldProblem($schema, $field, $value, $keys);
            if ($problem !== null) {
                $problems[] = $problem;
            }
        }

        if ($record->source !== null) {
            $problems = [...$problems, ...$this->sourceProblems($record, $schema, $lookups)];
        }

        if ($schema->isFile) {
            $problems = [...$problems, ...$this->existingFileProblems($record, $lookups)];
        }

        if ($record->locales !== []) {
            $problems = [...$problems, ...$this->localeProblems($record, $schema, $localeCodes)];
        }

        if ($record->published !== null && !$schema->versioned) {
            $problems[] = sprintf('"@published": %s has no published state', $record->class);
        } elseif ($record->published === true && !$schema->staged) {
            $problems[] = sprintf('"@published": %s is versioned without stages and has no published state', $record->class);
        }

        if ($record->draft !== []) {
            $problems = [...$problems, ...$this->draftProblems($record, $schema, $keys)];
        }

        return $problems;
    }

    /**
     * Draft values are plain values of the record's own fields: join rows have no stages to differ on, and a
     * reference stays out of the draft change, as it does out of `@locales`.
     *
     * @param array<string, FixtureRecord> $keys
     *
     * @return list<string>
     */
    private function draftProblems(FixtureRecord $record, ClassSchema $schema, array $keys): array
    {
        $problems = [];
        if (!$schema->versioned) {
            $problems[] = sprintf('"@draft": %s has no published state', $record->class);
        } elseif (!$schema->staged) {
            $problems[] = sprintf('"@draft": %s is versioned without stages and has no published state', $record->class);
        } elseif ($record->published !== true) {
            $problems[] = '"@draft" needs a published record';
        }

        if ($record->locales !== []) {
            $problems[] = '"@draft" cannot be combined with "@locales"';
        }

        foreach ($record->draft as $field => $value) {
            $kind = $schema->kind($field);
            if (in_array($kind, [FieldKind::HasOne, FieldKind::ManyMany], true) || ($kind === FieldKind::Value && $this->isReference($value))) {
                $problems[] = sprintf('"@draft": "%s" cannot hold a reference', $field);
            } elseif ($schema->isFile && in_array($field, self::FILE_PLACE, true)) {
                $problems[] = sprintf('"@draft": "%s" cannot rename or move a file', $field);
            } elseif (($problem = $this->fieldProblem($schema, $field, $value, $keys)) !== null) {
                $problems[] = '"@draft": ' . $problem;
            }
        }

        return $problems;
    }

    /**
     * The locale codes Fluent finds a Locale for: those of the load's Locale records and of the database. Fluent
     * writes the found Locale's own code, so only the exact code yields the rows write() leaves.
     *
     * @param list<FixtureRecord> $records
     *
     * @return array<string, true>
     */
    private function localeCodes(array $records, Lookups $lookups): array
    {
        $codes = $lookups->locales ?? [];
        foreach ($records as $record) {
            if (($this->schemas[$record->class] ?? null)?->isLocale === true) {
                $codes[] = $record->fields['Locale'] ?? null;
            }
        }

        return array_fill_keys(array_filter($codes, is_string(...)), true);
    }

    /**
     * @param array<string, true> $localeCodes
     *
     * @return list<string>
     */
    private function localeProblems(FixtureRecord $record, ClassSchema $schema, array $localeCodes): array
    {
        if ($schema->localisedTables === null) {
            return [$this->translatable
                ? sprintf('"@locales": %s is not translated', $record->class)
                : '"@locales": translations need the translation module, tractorcow/silverstripe-fluent'];
        }

        $translated = array_merge(...array_values($schema->localisedTables));
        $problems = [];
        foreach ($record->locales as $locale => $values) {
            if (!isset($localeCodes[$locale])) {
                $problems[] = sprintf('"@locales": no Locale "%s" in this load or the database', $locale);
            }

            foreach ($values as $field => $value) {
                if (!in_array($field, $translated, true)) {
                    $problems[] = sprintf('"@locales": "%s" is not translated (%s)', $field, $locale);
                } elseif ($this->isReference($value)) {
                    $problems[] = sprintf('"@locales": "%s" cannot hold a reference (%s)', $field, $locale);
                }
            }
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private function sourceProblems(FixtureRecord $record, ClassSchema $schema, Lookups $lookups): array
    {
        if (!$schema->isFile) {
            return [sprintf('"@source": %s is not a file class', $record->class)];
        }

        $problems = [];
        if (!($lookups->sources[(string) $record->source] ?? false)) {
            $problems[] = sprintf('"@source": no file at %s', $record->source);
        }

        if (!is_string($record->fields['Name'] ?? null)) {
            $problems[] = '"Name" is required for a file with "@source"';
        }

        return $problems;
    }

    /**
     * A file or folder may not take the place of one the site has: the load would share its file on disk, and
     * a reset would leave the site's record pointing at a file the fixture replaced.
     *
     * @return list<string>
     */
    private function existingFileProblems(FixtureRecord $record, Lookups $lookups): array
    {
        $name = $record->fields['Name'] ?? null;
        if (!is_string($name) || Reference::parse($record->fields['Parent'] ?? null) !== null) {
            // A record of this load as its parent is new, so nothing can be in its place
            return [];
        }

        $parentId = $record->parentId();
        if (!in_array($name, $lookups->existingFiles[$parentId] ?? [], true)) {
            return [];
        }

        return [sprintf(
            'the site already has a file or folder "%s%s"; give the test data a folder of its own',
            $lookups->folderNames[$parentId] ?? '',
            $name,
        )];
    }

    /**
     * Two records of one load at one place would share one file in the store, the later overwriting the earlier.
     *
     * @param array<string, string> $places reference name by folder and name, of the file records before this one
     *
     * @return list<string>
     */
    private function samePlaceProblems(FixtureRecord $record, array &$places): array
    {
        $name = $record->fields['Name'] ?? null;
        if (!is_string($name)) {
            return [];
        }

        $place = (Reference::parse($record->fields['Parent'] ?? null) ?? $record->parentId()) . '/' . $name;
        if (isset($places[$place])) {
            return [sprintf('"%s" is also the name of %s in the same folder', $name, $places[$place])];
        }

        $places[$place] = $record->key();

        return [];
    }

    /**
     * A folder chain that leads back to the record gives it no path in the store. A record below such a chain is
     * left to the records in it, which are each reported.
     *
     * @param array<string, FixtureRecord> $keys
     *
     * @return list<string>
     */
    private function parentCycleProblems(FixtureRecord $record, array $keys): array
    {
        $chain = [$record->key()];
        $key = $record->key();
        while (($key = Reference::parse($keys[$key]->fields['Parent'] ?? null)) !== null && isset($keys[$key])) {
            if ($key === $record->key()) {
                return [sprintf('"Parent" leads back to the record itself: %s', implode(' => ', [...$chain, $key]))];
            }

            if (in_array($key, $chain, true)) {
                return [];
            }

            $chain[] = $key;
        }

        return [];
    }

    /**
     * write() keeps one join row per pair of records, so a pair stated again, in the same list or from the other
     * side of the relation, must state the same extra field values.
     *
     * @param array<string, FixtureRecord> $keys
     * @param array<string, array{array<mixed>, string}> $pairs extra field values and where they were first stated,
     *                                                       by join table and pair
     *
     * @return list<string>
     */
    private function joinPairProblems(FixtureRecord $record, ClassSchema $schema, array $keys, array &$pairs): array
    {
        $problems = [];
        foreach ($record->fields as $field => $value) {
            $items = $schema->kind($field) === FieldKind::ManyMany ? ManyManyItem::listOf($value) : null;
            if ($items === null) {
                continue;
            }

            $component = $schema->joins[$field];
            foreach ($items as $item) {
                $reference = Reference::parse($item->reference);
                if ($reference === null || !isset($keys[$reference])) {
                    continue;
                }

                $sides = [$component['parentField'] => $record->key(), $component['childField'] => $reference];
                ksort($sides);
                $pair = serialize([$component['join'], $sides]);
                $extras = $item->extras;
                ksort($extras);
                if (!isset($pairs[$pair])) {
                    $pairs[$pair] = [$extras, sprintf('%s "%s"', $record->key(), $field)];
                } elseif ($pairs[$pair][0] !== $extras) {
                    $problems[] = sprintf('"%s" pairs it with %s again, with other extra field values than %s', $field, $reference, $pairs[$pair][1]);
                }
            }
        }

        return $problems;
    }

    /**
     * @param array<string, FixtureRecord> $keys
     */
    private function fieldProblem(ClassSchema $schema, string $field, mixed $value, array $keys): ?string
    {
        return match ($schema->kind($field)) {
            FieldKind::Value => $this->valueProblem($schema, $field, $value),
            FieldKind::HasOne => $this->referenceProblem($field, $value, $keys),
            FieldKind::ManyMany => $this->manyManyProblem($schema, $field, $value, $keys),
            FieldKind::ManyManyThrough => sprintf('"%s" is a many_many through; declare records of its join class instead', $field),
            FieldKind::HasMany => sprintf('"%s" is set on the record that holds the has_one', $field),
            FieldKind::Generated => sprintf('"%s" is a generated column and cannot be written', $field),
            FieldKind::Fixed => sprintf('"%s" is set by the loader', $field),
            FieldKind::Unknown => sprintf('unknown value "%s"', $field),
        };
    }

    /**
     * The framework resolves a reference in any field, this loader only in a relation: elsewhere it would be
     * written as the text of the reference.
     */
    private function valueProblem(ClassSchema $schema, string $field, mixed $value): ?string
    {
        if (!$this->isReference($value)) {
            return null;
        }

        $relation = substr($field, 0, -2);
        if (str_ends_with($field, 'ID') && $schema->kind($relation) === FieldKind::HasOne) {
            return sprintf('"%s" cannot hold a reference; set the has_one instead: %s: =>Class.identifier', $field, $relation);
        }

        return sprintf('"%s" cannot hold a reference; only a has_one or many_many takes one', $field);
    }

    private function isReference(mixed $value): bool
    {
        return is_string($value) && str_starts_with($value, '=>');
    }

    /**
     * @param array<string, FixtureRecord> $keys
     */
    private function referenceProblem(string $field, mixed $value, array $keys): ?string
    {
        $key = Reference::parse($value);
        if ($key === null) {
            return sprintf('"%s" must be a reference like =>Class.identifier', $field);
        }

        return isset($keys[$key]) ? null : sprintf('refers to %s, which no file of this load holds', $key);
    }

    /**
     * @param array<string, FixtureRecord> $keys
     */
    private function manyManyProblem(ClassSchema $schema, string $field, mixed $value, array $keys): ?string
    {
        $items = ManyManyItem::listOf($value);
        if ($items === null) {
            return sprintf('"%s" must be a list of references, like [=>Class.a, =>Class.b]', $field);
        }

        $extraFields = $schema->joins[$field]['extraFields'];
        foreach ($items as $item) {
            $unknown = array_diff_key($item->extras, $extraFields);
            if ($unknown !== []) {
                return sprintf('"%s" has no extra field "%s"', $field, array_key_first($unknown));
            }

            $problem = $this->referenceProblem($field, $item->reference, $keys);
            if ($problem !== null) {
                return $problem;
            }
        }

        return null;
    }
}
