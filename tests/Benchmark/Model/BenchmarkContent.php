<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Tests\Benchmark\Model;

use SilverStripe\Dev\TestOnly;

class BenchmarkContent extends BenchmarkBlock implements TestOnly
{
    private static string $table_name = 'FixturesBenchmark_Content';

    private static array $db = [
        'Content' => 'HTMLText',
    ];
}
