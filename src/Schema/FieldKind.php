<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Schema;

/**
 * What a non-loader key of a fixture record stands for on its class.
 */
enum FieldKind
{
    /**
     * A database column or a composite field: a value write() converts with its field type.
     */
    case Value;
    case HasOne;
    case ManyMany;
    case ManyManyThrough;
    case HasMany;
    case Generated;
    case Fixed;
    case Unknown;
}
