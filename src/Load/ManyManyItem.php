<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Load;

/**
 * One item of a many_many value: a reference, or a map of a reference (`ref`) and extra field values.
 */
final readonly class ManyManyItem
{
    /**
     * @param array<mixed> $extras extra field values by field
     */
    private function __construct(public mixed $reference, public array $extras)
    {
    }

    /**
     * The framework also takes a comma-separated string; this loader takes the list form only.
     *
     * @return list<self>|null null when the value is not a list
     */
    public static function listOf(mixed $value): ?array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return null;
        }

        return array_map(
            static fn (mixed $item): self => is_array($item)
                ? new self($item['ref'] ?? null, array_diff_key($item, ['ref' => true]))
                : new self($item, []),
            $value,
        );
    }
}
