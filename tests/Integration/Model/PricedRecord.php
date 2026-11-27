<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Tests\Integration\Model;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * A composite with a default on one of its columns, so a record can state that column ahead of the composite.
 * Versioned and localised by the tests that list it, through their required extensions.
 */
class PricedRecord extends DataObject implements TestOnly
{
    private static string $table_name = 'FixturesTest_PricedRecord';

    private static array $db = [
        'Title' => 'Varchar(255)',
        'Price' => 'Money',
    ];

    private static array $defaults = [
        'PriceCurrency' => 'EUR',
    ];
}
