<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Load;

use SilverStripe\ORM\DataObject;
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
        $keys = array_flip(array_map(static fn (FixtureRecord $record): string => $record->key(), $records));
        $problems = [];
        foreach ($records as $record) {
            $class = $this->schemas->resolve($record->class);
            if ($class === null) {
                $problems[] = sprintf('%s: unknown class "%s"', $record->label(), $record->class);
                continue;
            }

            $schema = $this->schemas->forClass($class);
            foreach ($this->recordProblems($record, $schema, $keys) as $problem) {
                $problems[] = $record->label() . ': ' . $problem;
            }
        }

        return $problems;
    }

    /**
     * @param array<string, int> $keys every record of the load, by reference name
     *
     * @return list<string>
     */
    private function recordProblems(FixtureRecord $record, ClassSchema $schema, array $keys): array
    {
        $problems = [];
        foreach ($record->fields as $field => $value) {
            $problem = $this->fieldProblem($schema, $field, $value, $keys);
            if ($problem !== null) {
                $problems[] = $problem;
            }
        }

        if ($record->published !== null && !$schema->versioned) {
            $problems[] = sprintf('"@published": %s has no published state', $record->class);
        } elseif ($record->published === true && !$schema->staged) {
            $problems[] = sprintf('"@published": %s is versioned without stages and has no published state', $record->class);
        }

        return $problems;
    }

    /**
     * @param array<string, int> $keys
     */
    private function fieldProblem(ClassSchema $schema, string $field, mixed $value, array $keys): ?string
    {
        return match ($schema->kind($field)) {
            FieldKind::Column, FieldKind::Composite => null,
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
     * @param array<string, int> $keys
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
     * @param array<string, int> $keys
     */
    private function manyManyProblem(string $class, string $field, mixed $value, array $keys): ?string
    {
        if (!is_array($value) || !array_is_list($value)) {
            return sprintf('"%s" must be a list of references', $field);
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
