<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Tests\Integration\Model;

use SilverStripe\Dev\TestOnly;

class Sub extends Plain implements TestOnly
{
    private static string $table_name = 'FixturesTest_Sub';

    private static array $db = [
        'Extra' => 'Varchar(255)',
        'Level' => 'Int',
    ];

    private static array $defaults = [
        'Quantity' => 10,
        'Note' => 'leaf default',
        'Extra' => 'leaf extra',
    ];
}
