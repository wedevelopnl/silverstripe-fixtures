<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Tests\Integration\Model;

use SilverStripe\Assets\Image;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

class Card extends DataObject implements TestOnly
{
    private static string $table_name = 'FixturesTest_Card';

    private static array $db = [
        'Title' => 'Varchar(255)',
    ];

    private static array $has_one = [
        'Image' => Image::class,
    ];
}
