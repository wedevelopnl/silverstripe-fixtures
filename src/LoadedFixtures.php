<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures;

use InvalidArgumentException;

/**
 * The IDs one load assigned, by the class as written in the fixture and the record's identifier.
 */
final readonly class LoadedFixtures
{
    /**
     * @param array<string, array<string, int>> $ids in declaration order: files in argument order, classes by
     *                                                first appearance, identifiers in file order
     */
    public function __construct(private array $ids)
    {
    }

    public function idOf(string $class, string $identifier): int
    {
        return $this->ids[$class][$identifier]
            ?? throw new InvalidArgumentException(sprintf('No record %s.%s was loaded', $class, $identifier));
    }

    /**
     * @return array<string, array<string, int>>
     */
    public function toArray(): array
    {
        return $this->ids;
    }
}
