<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Load;

use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use TractorCow\Fluent\Model\Locale;

/**
 * Answers the validator's lookup request from the database and the filesystem, sending nothing for an empty one.
 */
final class LookupFetcher
{
    public function fetch(LookupRequest $request): Lookups
    {
        [$existingFiles, $folderNames] = $this->existingFiles($request->files);
        $sources = [];
        foreach ($request->sources as $source) {
            $sources[$source] = is_file($source);
        }

        return new Lookups(
            // Requested only when the translation module is installed
            $request->locales ? array_values(array_filter(Locale::get()->column('Locale'), is_string(...))) : null,
            $existingFiles,
            $folderNames,
            $sources,
        );
    }

    /**
     * @param list<array{string, int}> $files
     *
     * @return array{array<int, list<string>>, array<int, string>}
     */
    private function existingFiles(array $files): array
    {
        if ($files === []) {
            return [[], []];
        }

        $table = (string) DataObject::getSchema()->baseDataTable(File::class);
        $tables = DB::table_list();
        $stages = array_filter([$table, $table . '_Live'], static fn (string $stage): bool => isset($tables[strtolower($stage)]));
        $existing = [];
        $folderNames = [];
        foreach ($files as [$name, $parentId]) {
            foreach ($stages as $stage) {
                $query = sprintf('SELECT COUNT(*) FROM "%s" WHERE "Name" = ? AND "ParentID" = ?', $stage);
                if ((int) DB::prepared_query($query, [$name, $parentId])->value() > 0) {
                    $existing[$parentId][] = $name;
                    if ($parentId > 0) {
                        $folderNames[$parentId] ??= (string) Folder::get()->byID($parentId)?->getFilename();
                    }

                    break;
                }
            }
        }

        return [$existing, $folderNames];
    }
}
