<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Tests\Integration\Model;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

class Coordinate extends DataObject implements TestOnly
{
    private static string $table_name = 'FixturesTest_Coordinate';

    private static array $db = [
        'Latitude' => 'Double',
    ];
}
