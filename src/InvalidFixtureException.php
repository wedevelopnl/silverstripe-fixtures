<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures;

use InvalidArgumentException;

/**
 * Every problem in a fixture set, raised before anything is written.
 */
final class InvalidFixtureException extends InvalidArgumentException
{
    /**
     * @param list<string> $problems each naming the file, the record and the field
     */
    public function __construct(private readonly array $problems)
    {
        parent::__construct(
            "Test data could not be loaded, nothing was written:\n- " . implode("\n- ", $problems),
        );
    }

    /**
     * @return list<string>
     */
    public function getProblems(): array
    {
        return $this->problems;
    }
}
