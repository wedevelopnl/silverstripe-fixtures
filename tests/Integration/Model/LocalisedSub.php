<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Tests\Integration\Model;

use SilverStripe\Dev\TestOnly;

class LocalisedSub extends LocalisedRecord implements TestOnly
{
    private static string $table_name = 'FixturesTest_LocalisedSub';

    private static array $db = [
        'Summary' => 'Text',
        'Rating' => 'Int',
    ];
}
