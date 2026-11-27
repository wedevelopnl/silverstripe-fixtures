<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Tests\Integration\Model;

use SilverStripe\Dev\TestOnly;

class VersionedSub extends VersionedRecord implements TestOnly
{
    private static string $table_name = 'FixturesTest_VersionedSub';

    private static array $db = [
        'Extra' => 'Varchar(255)',
    ];
}
