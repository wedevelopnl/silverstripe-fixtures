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
     * @param array<string, mixed> $draft field values that differ on draft, over the published fields
     */
    public function __construct(
        public string $file,
        public string $class,
        public string $identifier,
        public array $fields,
        public ?bool $published,
        public array $locales,
        public ?string $source,
        public array $draft,
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

    /**
     * The folder a file record is placed in by ID: its ParentID, or the root (0) when that is not a positive number.
     */
    public function parentId(): int
    {
        $parentId = $this->fields['ParentID'] ?? 0;

        return is_numeric($parentId) && $parentId > 0 ? (int) $parentId : 0;
    }
}
