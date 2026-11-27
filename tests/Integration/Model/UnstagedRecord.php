<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Tests\Integration\Model;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * Versioned without stages by the tests that list it, through their required extensions.
 */
class UnstagedRecord extends DataObject implements TestOnly
{
    private static string $table_name = 'FixturesTest_UnstagedRecord';

    private static array $db = [
        'Title' => 'Varchar(255)',
    ];
}
