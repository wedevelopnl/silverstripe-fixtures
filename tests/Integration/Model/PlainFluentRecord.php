<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Tests\Integration\Model;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * Versioned, and localised by the plain FluentExtension rather than FluentVersionedExtension, by the tests that
 * list it, through their required extensions.
 */
class PlainFluentRecord extends DataObject implements TestOnly
{
    private static string $table_name = 'FixturesTest_PlainFluentRecord';

    private static array $db = [
        'Title' => 'Varchar(255)',
    ];
}
