<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Load;

final readonly class FixtureSet
{
    /**
     * @param list<FixtureRecord> $records in declaration order
     * @param list<string> $problems
     */
    public function __construct(
        public array $records,
        public array $problems,
    ) {
    }
}
