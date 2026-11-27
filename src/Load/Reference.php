<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Load;

/**
 * The `=>Class.identifier` notation a fixture refers to another record of the load with.
 */
final class Reference
{
    /**
     * The referenced record's key (`Class.identifier`), or null when the value is no reference.
     */
    public static function parse(mixed $value): ?string
    {
        if (!is_string($value) || !preg_match('/^=>\s*([^.\s]+\.\S+)$/', trim($value), $matches)) {
            return null;
        }

        return $matches[1];
    }
}
