<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Load;

/**
 * The site's answers to a lookup request.
 */
final readonly class Lookups
{
    /**
     * @param list<string>|null $locales the codes of the database's Locale records; null when not requested
     * @param array<int, list<string>> $existingFiles the requested names the site already has a file or folder
     *                                                at, by parent folder ID
     * @param array<int, string> $folderNames the path of each parent folder of an existing file, by ID
     * @param array<string, bool> $sources whether a file exists at each requested source path
     */
    public function __construct(
        public ?array $locales,
        public array $existingFiles,
        public array $folderNames,
        public array $sources,
    ) {
    }
}
