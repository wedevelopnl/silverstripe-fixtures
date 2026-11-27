<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Tests\Integration\Model;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

class Tag extends DataObject implements TestOnly
{
    private static string $table_name = 'FixturesTest_Tag';

    private static array $db = [
        'Title' => 'Varchar(255)',
        'Code' => 'Varchar(50)',
    ];

    private static array $indexes = [
        'Code' => ['type' => 'unique', 'columns' => ['Code']],
    ];

    private static array $belongs_many_many = [
        'Plains' => Plain::class . '.Tags',
    ];
}
