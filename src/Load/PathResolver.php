<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Load;

use InvalidArgumentException;
use SilverStripe\Control\Director;
use SilverStripe\Core\Manifest\ModuleResource;
use SilverStripe\Core\Manifest\ModuleResourceLoader;

/**
 * Finds the file a fixture path or source names: a module resource (`vendor/module:path`), an absolute path, or
 * a path relative to the project root.
 */
final class PathResolver
{
    public static function resolve(string $path): ?string
    {
        try {
            $resource = ModuleResourceLoader::singleton()->resolveResource($path);
        } catch (InvalidArgumentException) {
            // The module the path names is not installed
            return null;
        }

        if ($resource instanceof ModuleResource) {
            $absolute = $resource->getPath();
        } else {
            $absolute = Director::is_absolute($path) ? $path : Director::baseFolder() . '/' . $path;
        }

        return is_file($absolute) ? $absolute : null;
    }
}
