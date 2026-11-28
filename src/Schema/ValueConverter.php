<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Schema;

use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\FieldType\DBField;
use WeDevelop\Fixtures\Load\Plan;

/**
 * Converts a plan's raw values to the database values their field types write, as write() does, in one pass before
 * the rows are inserted. One converter per load: it keeps every scalar but a float it converted, so a value a record
 * repeats on its stages and versions, or a default every record has, is converted once. A float is converted every
 * time: as a string it holds only `precision` digits, so two floats could share a key.
 */
final class ValueConverter
{
    /**
     * @var array<string, array<string, array<string, array<string, mixed>>>> columns by spec, name and typed value
     */
    private array $converted = [];

    /**
     * @return array<string, list<array<string, mixed>>> the plan's rows with every raw value converted, by table
     */
    public function rows(Plan $plan): array
    {
        $rows = $plan->rows();
        foreach ($plan->conversions() as $table => $conversions) {
            foreach ($conversions as $index => [$specs, $columns]) {
                $row = $this->row($rows[$table][$index], $specs);
                $rows[$table][$index] = $columns === null ? $row : array_intersect_key($row, $columns);
            }
        }

        return $rows;
    }

    /**
     * The database value of one column.
     */
    public function value(string $spec, string $name, mixed $value): mixed
    {
        return $this->columns($spec, $name, $value)[$name] ?? null;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, string> $specs
     *
     * @return array<string, mixed>
     */
    private function row(array $row, array $specs): array
    {
        $converted = [];
        foreach ($row as $key => $value) {
            if (!isset($specs[$key])) {
                $converted[$key] = $value;
                continue;
            }

            foreach ($this->columns($specs[$key], $key, $value) as $column => $columnValue) {
                $converted[$column] = $columnValue;
            }
        }

        return $converted;
    }

    /**
     * @return array<string, mixed>
     */
    private function columns(string $spec, string $name, mixed $value): array
    {
        if (is_float($value) || (!is_scalar($value) && $value !== null)) {
            return $this->convert($spec, $name, $value);
        }

        return $this->converted[$spec][$name][get_debug_type($value) . ':' . $value] ??= $this->convert($spec, $name, $value);
    }

    /**
     * @return array<string, mixed>
     */
    private function convert(string $spec, string $name, mixed $value): array
    {
        $field = Injector::inst()->create($spec, $name);
        assert($field instanceof DBField);
        $field->setValue($value);
        $manipulation = [];
        $field->writeToManipulation($manipulation);
        $fields = $manipulation['fields'] ?? [];

        $values = [];
        foreach (is_array($fields) ? $fields : [] as $column => $columnValue) {
            $values[(string) $column] = $columnValue;
        }

        return $values;
    }
}
