<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Tests\Benchmark\Model;

use SilverStripe\Dev\TestOnly;

class BenchmarkColumn extends BenchmarkBlock implements TestOnly
{
    private static string $table_name = 'FixturesBenchmark_Column';

    private static array $db = [
        'Settings' => 'Text',
    ];
}
