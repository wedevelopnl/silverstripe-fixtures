<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Tests\Integration\Model;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * Join class of Plain.Links, a many_many through.
 */
class PlainLink extends DataObject implements TestOnly
{
    private static string $table_name = 'FixturesTest_PlainLink';

    private static array $db = [
        'Weight' => 'Int',
    ];

    private static array $has_one = [
        'From' => Plain::class,
        'To' => Tag::class,
    ];
}
