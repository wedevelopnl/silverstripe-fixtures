<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Tests\Benchmark\Model;

use SilverStripe\Dev\TestOnly;

class BenchmarkSection extends BenchmarkBlock implements TestOnly
{
    private static string $table_name = 'FixturesBenchmark_Section';

    private static array $db = [
        'ShowTitle' => 'Boolean',
        'Zone' => 'Varchar(50)',
    ];
}
