<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Reset;

/**
 * The record IDs a reset deletes from one table family: ID ranges from the ledger and single IDs of the
 * configured classes.
 */
final class Condition
{
    /**
     * @var list<array{int, int}>
     */
    private array $ranges = [];

    /**
     * @var array<int, int>
     */
    private array $ids = [];

    public function addRange(int $first, int $last): void
    {
        $this->ranges[] = [$first, $last];
    }

    /**
     * @param list<int> $ids
     */
    public function addIds(array $ids): void
    {
        $this->ids += array_combine($ids, $ids);
    }

    public function isEmpty(): bool
    {
        return $this->ranges === [] && $this->ids === [];
    }

    /**
     * @return array<string, list<int>> a WHERE clause on the column with its parameters
     */
    public function toSql(string $column): array
    {
        $clauses = [];
        $parameters = [];
        foreach ($this->ranges as [$first, $last]) {
            $clauses[] = sprintf('"%s" BETWEEN ? AND ?', $column);
            $parameters[] = $first;
            $parameters[] = $last;
        }

        if ($this->ids !== []) {
            $clauses[] = sprintf('"%s" IN (%s)', $column, implode(', ', array_fill(0, count($this->ids), '?')));
            $parameters = [...$parameters, ...array_values($this->ids)];
        }

        return ['(' . implode(' OR ', $clauses) . ')' => $parameters];
    }
}
