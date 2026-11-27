<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Tests\Benchmark\Model;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * A page holding a tree of blocks. Versioned by the benchmark through its required extensions.
 */
class BenchmarkPage extends DataObject implements TestOnly
{
    private static string $table_name = 'FixturesBenchmark_Page';

    private static array $db = [
        'Title' => 'Varchar(255)',
        'URLSegment' => 'Varchar(255)',
        'Sort' => 'Int',
        'ShowInMenus' => 'Boolean',
    ];

    private static array $defaults = [
        'ShowInMenus' => true,
    ];
}
