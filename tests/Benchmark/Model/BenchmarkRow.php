<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Tests\Benchmark\Model;

use SilverStripe\Dev\TestOnly;

class BenchmarkRow extends BenchmarkBlock implements TestOnly
{
    private static string $table_name = 'FixturesBenchmark_Row';

    private static array $db = [
        'Gutter' => 'Int',
    ];
}
