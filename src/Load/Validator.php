<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Load;

use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use TractorCow\Fluent\Extension\FluentExtension;
use TractorCow\Fluent\Model\Locale;
use WeDevelop\Fixtures\Schema\ClassSchema;
use WeDevelop\Fixtures\Schema\FieldKind;
use WeDevelop\Fixtures\Schema\SchemaMap;

/**
 * Owns the invariant that nothing is written from an invalid set: it lists every problem, each naming the file,
 * the record and the field.
 */
final readonly class Validator
{
    public function __construct(private SchemaMap $schemas)
    {
    }

    /**
     * @param list<FixtureRecord> $records
     *
     * @return list<string>
     */
    public function validate(array $records): array
    {
        $keys = [];
        foreach ($records as $record) {
            $keys[$record->key()] = $record;
        }

        $problems = [];
        $places = [];
        $pairs = [];
        $localeCodes = $this->localeCodes($records);
        foreach ($records as $record) {
            $class = $this->schemas->resolve($record->class);
            if ($class === null) {
                $problems[] = sprintf('%s: unknown class "%s"', $record->label(), $record->class);
                continue;
            }

            $schema = $this->schemas->forClass($class);
            $recordProblems = [...$this->recordProblems($record, $schema, $keys, $localeCodes), ...$this->joinPairProblems($record, $schema, $keys, $pairs)];
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
    private function recordProblems(FixtureRecord $record, ClassSchema $schema, array $keys, array $localeCodes): array
    {
        $problems = [];
        foreach ($record->fields as $field => $value) {
            $problem = $this->fieldProblem($schema, $field, $value, $keys);
            if ($problem !== null) {
                $problems[] = $problem;
            }
        }

        if ($record->source !== null) {
            $problems = [...$problems, ...$this->sourceProblems($record, $schema)];
        }

        if ($schema->isFile) {
            $problems = [...$problems, ...$this->existingFileProblems($record)];
        }

        if ($record->locales !== []) {
            $problems = [...$problems, ...$this->localeProblems($record, $schema, $localeCodes)];
        }

        if ($record->published !== null && !$schema->versioned) {
            $problems[] = sprintf('"@published": %s has no published state', $record->class);
        } elseif ($record->published === true && !$schema->staged) {
            $problems[] = sprintf('"@published": %s is versioned without stages and has no published state', $record->class);
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
    private function localeCodes(array $records): array
    {
        if (!class_exists(Locale::class) || array_filter($records, static fn (FixtureRecord $record): bool => $record->locales !== []) === []) {
            return [];
        }

        $codes = Locale::get()->column('Locale');
        foreach ($records as $record) {
            $class = $this->schemas->resolve($record->class);
            if ($class !== null && is_a($class, Locale::class, true)) {
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
            return [class_exists(FluentExtension::class)
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
    private function sourceProblems(FixtureRecord $record, ClassSchema $schema): array
    {
        if (!$schema->isFile) {
            return [sprintf('"@source": %s is not a file class', $record->class)];
        }

        $problems = [];
        if (PathResolver::resolve((string) $record->source) === null) {
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
    private function existingFileProblems(FixtureRecord $record): array
    {
        $name = $record->fields['Name'] ?? null;
        if (!is_string($name) || Reference::parse($record->fields['Parent'] ?? null) !== null) {
            // A record of this load as its parent is new, so nothing can be in its place
            return [];
        }

        $parentId = (int) (is_numeric($record->fields['ParentID'] ?? null) ? $record->fields['ParentID'] : 0);
        $table = (string) DataObject::getSchema()->baseDataTable(File::class);
        $tables = DB::table_list();
        foreach ([$table, $table . '_Live'] as $stage) {
            if (!isset($tables[strtolower($stage)])) {
                continue;
            }

            $query = sprintf('SELECT COUNT(*) FROM "%s" WHERE "Name" = ? AND "ParentID" = ?', $stage);
            if ((int) DB::prepared_query($query, [$name, $parentId])->value() > 0) {
                $folder = $parentId > 0 ? Folder::get()->byID($parentId)?->getFilename() : '';

                return [sprintf(
                    'the site already has a file or folder "%s%s"; give the test data a folder of its own',
                    $folder,
                    $name,
                )];
            }
        }

        return [];
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

        $parentId = $record->fields['ParentID'] ?? 0;
        $parent = Reference::parse($record->fields['Parent'] ?? null) ?? (is_numeric($parentId) ? (int) $parentId : 0);
        $place = $parent . '/' . $name;
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
            if ($schema->kind($field) !== FieldKind::ManyMany || !is_array($value) || !array_is_list($value)) {
                continue;
            }

            /** @var array{join: string, parentField: string, childField: string} $component validated as a many_many */
            $component = DataObject::getSchema()->manyManyComponent($schema->class, $field);
            foreach ($value as $item) {
                $reference = Reference::parse(is_array($item) ? $item['ref'] ?? null : $item);
                if ($reference === null || !isset($keys[$reference])) {
                    continue;
                }

                $sides = [$component['parentField'] => $record->key(), $component['childField'] => $reference];
                ksort($sides);
                $pair = serialize([$component['join'], $sides]);
                $extras = is_array($item) ? array_diff_key($item, ['ref' => true]) : [];
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
            FieldKind::ManyMany => $this->manyManyProblem($schema->class, $field, $value, $keys),
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
     * @param class-string<DataObject> $class
     * @param array<string, FixtureRecord> $keys
     */
    private function manyManyProblem(string $class, string $field, mixed $value, array $keys): ?string
    {
        if (!is_array($value) || !array_is_list($value)) {
            // The framework also takes a comma-separated string; this loader takes the list form only
            return sprintf('"%s" must be a list of references, like [=>Class.a, =>Class.b]', $field);
        }

        $extraFields = DataObject::getSchema()->manyManyExtraFieldsForComponent($class, $field) ?? [];
        foreach ($value as $item) {
            $extra = is_array($item) ? array_diff_key($item, ['ref' => true]) : [];
            $unknown = array_diff_key($extra, $extraFields);
            if ($unknown !== []) {
                return sprintf('"%s" has no extra field "%s"', $field, array_key_first($unknown));
            }

            $problem = $this->referenceProblem($field, is_array($item) ? $item['ref'] ?? null : $item, $keys);
            if ($problem !== null) {
                return $problem;
            }
        }

        return null;
    }
}
