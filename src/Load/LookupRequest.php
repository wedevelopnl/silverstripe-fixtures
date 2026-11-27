<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Load;

/**
 * What the validator needs to know of the site for one set.
 */
final readonly class LookupRequest
{
    /**
     * @param bool $locales whether the site's locale codes are needed
     * @param list<array{string, int}> $files name and parent folder ID of every file record whose place the site
     *                                        may already hold
     * @param list<string> $sources the source file paths
     */
    public function __construct(
        public bool $locales,
        public array $files,
        public array $sources,
    ) {
    }

    public function isEmpty(): bool
    {
        return !$this->locales && $this->files === [] && $this->sources === [];
    }
}
