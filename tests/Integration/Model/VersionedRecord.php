<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Tests\Integration\Model;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * Versioned by the tests that list it, through their required extensions: the base testbed has no Versioned.
 */
class VersionedRecord extends DataObject implements TestOnly
{
    private static string $table_name = 'FixturesTest_VersionedRecord';

    private static array $db = [
        'Title' => 'Varchar(255)',
        'Sort' => 'Int',
    ];

    private static array $has_one = [
        'Parent' => VersionedRecord::class,
    ];

    private static array $has_many = [
        'Children' => VersionedRecord::class . '.Parent',
    ];

    private static array $owns = [
        'Children',
    ];

    private static array $defaults = [
        'Sort' => 3,
    ];
}
