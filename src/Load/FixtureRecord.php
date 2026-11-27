<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Load;

/**
 * One record as a fixture file declares it, before it is checked against the schema.
 */
final readonly class FixtureRecord
{
    /**
     * @param array<string, mixed> $fields every key that is not a loader key
     * @param array<string, array<string, mixed>> $locales localised field values by locale code
     */
    public function __construct(
        public string $file,
        public string $class,
        public string $identifier,
        public array $fields,
        public ?bool $published,
        public array $locales,
        public ?string $source,
    ) {
    }

    /**
     * The name references use: `=>Class.identifier`, with the class as written.
     */
    public function key(): string
    {
        return $this->class . '.' . $this->identifier;
    }

    public function label(): string
    {
        return $this->file . ', ' . $this->key();
    }
}
