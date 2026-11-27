<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Tests\Benchmark\Model;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * A block in a page's tree, parented to the page or another block through a polymorphic has_one.
 */
class BenchmarkBlock extends DataObject implements TestOnly
{
    private static string $table_name = 'FixturesBenchmark_Block';

    private static array $db = [
        'Title' => 'Varchar(255)',
        'Sort' => 'Int',
    ];

    private static array $has_one = [
        'Parent' => DataObject::class,
    ];
}
