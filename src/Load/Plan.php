<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Load;

use SilverStripe\ORM\DataObject;

/**
 * The rows one load inserts, the IDs it assigned and the ID range it claimed per table.
 */
final class Plan
{
    /**
     * @var array<string, list<array<string, mixed>>>
     */
    private array $rows = [];

    /**
     * @var array<string, array{int, int}>
     */
    private array $ranges = [];

    /**
     * @var array<string, int> record ID by reference name
     */
    private array $ids = [];

    /**
     * @var array<string, true> the join pairs planned, by join table and pair
     */
    private array $pairs = [];

    /**
     * @var array<string, class-string<DataObject>> record class by reference name
     */
    private array $classes = [];

    /**
     * @param array<string, int> $floors the highest ID each table with its own ID sequence has handed out
     */
    public function __construct(private readonly array $floors)
    {
    }

    /**
     * Claims the next ID of a table with its own ID sequence; the first claim starts above its floor.
     */
    public function claim(string $table): int
    {
        $id = ($this->ranges[$table][1] ?? $this->floors[$table]) + 1;
        $this->ranges[$table] = [$this->ranges[$table][0] ?? $id, $id];

        return $id;
    }

    /**
     * @param class-string<DataObject> $class
     */
    public function assign(string $key, string $class, int $id): void
    {
        $this->ids[$key] = $id;
        $this->classes[$key] = $class;
    }

    public function idOf(string $key): int
    {
        return $this->ids[$key];
    }

    /**
     * @return class-string<DataObject>
     */
    public function classOf(string $key): string
    {
        return $this->classes[$key];
    }

    /**
     * Claims a pair of records in a join table; false when the load already holds it, as write() keeps one row per
     * pair.
     *
     * @param array<string, int> $sides record ID by join column
     */
    public function claimPair(string $table, array $sides): bool
    {
        ksort($sides);
        $pair = serialize([$table, $sides]);
        if (isset($this->pairs[$pair])) {
            return false;
        }

        $this->pairs[$pair] = true;

        return true;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function add(string $table, array $row): void
    {
        $this->rows[$table][] = $row;
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public function rows(): array
    {
        return $this->rows;
    }

    /**
     * @return array<string, array{int, int}> first and last ID by table
     */
    public function ranges(): array
    {
        return $this->ranges;
    }

    /**
     * @return list<class-string<DataObject>>
     */
    public function classes(): array
    {
        return array_values(array_unique($this->classes));
    }
}
