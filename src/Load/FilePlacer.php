<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Load;

use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Core\Injector\Injector;

/**
 * Puts a fixture's source file in the asset store, public or protected, through the store's own API.
 */
final class FilePlacer
{
    /**
     * @return array{Hash: string, Filename: string, Variant: ?string} the file's place, for the record's File field
     */
    public function place(string $source, string $filename, bool $public): array
    {
        $store = Injector::inst()->get(AssetStore::class);

        /** @var array{Hash: string, Filename: string, Variant: ?string} $placed */
        $placed = $store->setFromLocalFile($source, $filename, null, null, [
            'visibility' => $public ? AssetStore::VISIBILITY_PUBLIC : AssetStore::VISIBILITY_PROTECTED,
            'conflict' => AssetStore::CONFLICT_OVERWRITE,
        ]);

        // A file an earlier load left in the other store is written where it is: move it to the declared one
        if ($public) {
            $store->publish($placed['Filename'], $placed['Hash']);
        } else {
            $store->protect($placed['Filename'], $placed['Hash']);
        }

        return $placed;
    }
}
