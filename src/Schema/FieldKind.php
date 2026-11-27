<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Schema;

/**
 * What a non-loader key of a fixture record stands for on its class.
 */
enum FieldKind
{
    case Column;
    case Composite;
    case HasOne;
    case ManyMany;
    case ManyManyThrough;
    case HasMany;
    case Generated;
    case Fixed;
    case Unknown;
}
