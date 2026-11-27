<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Tests\Integration\Model;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * Every column and relation shape the compatibility suite compares with write().
 */
class Plain extends DataObject implements TestOnly
{
    private static string $table_name = 'FixturesTest_Plain';

    private static array $db = [
        'Title' => 'Varchar(255)',
        'Quantity' => 'Int',
        'Flag' => 'Boolean',
        'Price' => 'Money',
        'Body' => 'Text',
        'Status' => "Enum('New,Done','New')",
        'TitleLength' => 'Generated("Int", "CHAR_LENGTH(\"Title\")", "STORED")',
    ];

    private static array $has_one = [
        'Parent' => Plain::class,
        'Target' => DataObject::class,
    ];

    private static array $has_many = [
        'Children' => Plain::class . '.Parent',
    ];

    private static array $many_many = [
        'Tags' => Tag::class,
        'Related' => Plain::class,
        'Links' => [
            'through' => PlainLink::class,
            'from' => 'From',
            'to' => 'To',
        ],
    ];

    private static array $many_many_extraFields = [
        'Tags' => ['Weight' => 'Int'],
    ];

    private static array $defaults = [
        'Quantity' => 5,
        'Body' => 'parent default',
    ];

    private static array $extensions = [
        PlainDefaultsExtension::class,
    ];
}
