<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Tests\Benchmark;

use SilverStripe\Dev\FixtureFactory;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Dev\YamlFixture;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;
use Symfony\Component\Yaml\Yaml;
use WeDevelop\Fixtures\FixtureLoader;
use WeDevelop\Fixtures\Tests\Benchmark\Model\BenchmarkBlock;
use WeDevelop\Fixtures\Tests\Benchmark\Model\BenchmarkColumn;
use WeDevelop\Fixtures\Tests\Benchmark\Model\BenchmarkContent;
use WeDevelop\Fixtures\Tests\Benchmark\Model\BenchmarkPage;
use WeDevelop\Fixtures\Tests\Benchmark\Model\BenchmarkRow;
use WeDevelop\Fixtures\Tests\Benchmark\Model\BenchmarkSection;

/**
 * Loads and clears the same fixture set with the framework's handling (YamlFixture and FixtureFactory, cleared
 * as FixtureTestState clears between test classes) and with this package, and reports wall time and the
 * statements MySQL counted. Twice: the whole set in one load and one clear, and per file, one load and one clear
 * for each file as a test class with one fixture file would, summed over the files. Run by `task benchmark`; it
 * asserts nothing about the figures.
 */
final class FixtureBenchmarkTest extends SapphireTest
{
    private const int FILES = 30;

    private const int ROUNDS = 3;

    private const string REPORT = BASE_PATH . '/coverage/benchmark.md';

    protected $usesDatabase = true;

    // TRUNCATE ends a transaction, and the framework's clear empties the whole database
    protected $usesTransactions = false;

    protected static $extra_dataobjects = [
        BenchmarkPage::class,
        BenchmarkBlock::class,
        BenchmarkSection::class,
        BenchmarkRow::class,
        BenchmarkColumn::class,
        BenchmarkContent::class,
    ];

    protected static $required_extensions = [
        BenchmarkPage::class => [Versioned::class],
        BenchmarkBlock::class => [Versioned::class],
    ];

    public function testLoadingAndClearingAgainstTheFrameworksFixtureHandling(): void
    {
        [$paths, $records] = $this->writeFixtureSet();
        $granularities = [
            'whole set' => [$paths],
            'per file' => array_map(static fn (string $path): array => [$path], $paths),
        ];
        $results = [];
        for ($round = 0; $round < self::ROUNDS; $round++) {
            foreach ($granularities as $granularity => $groups) {
                [$sums, $loaded] = $this->loadAndClear($groups);
                $this->assertSame($records, $loaded, $granularity . ': every record was loaded');
                foreach ($sums as $step => $sum) {
                    $results[$granularity . ' ' . $step][] = $sum;
                }
            }
        }

        $report = $this->report(count($paths), $records, $results);
        file_put_contents(self::REPORT, $report);
        fwrite(STDOUT, "\n" . $report . "\nReport written to coverage/benchmark.md\n");

        $this->assertFileExists(self::REPORT);
    }

    /**
     * A deterministic set shaped like a page builder's E2E fixtures: per file a page with a section, rows,
     * columns and content blocks, each parented through a polymorphic has_one.
     *
     * @return array{list<string>, int} the fixture paths and the number of records they hold
     */
    private function writeFixtureSet(): array
    {
        $directory = TEMP_PATH . '/fixtures-benchmark';
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $paths = [];
        $records = 0;
        for ($file = 1; $file <= self::FILES; $file++) {
            $set = $this->fileSet($file);
            $records += array_sum(array_map(count(...), $set));
            $paths[] = $path = sprintf('%s/page-%02d.yml', $directory, $file);
            file_put_contents($path, Yaml::dump($set, 3));
        }

        return [$paths, $records];
    }

    /**
     * @return array<class-string, array<string, array<string, mixed>>>
     */
    private function fileSet(int $file): array
    {
        $page = 'page' . $file;
        $section = 'section' . $file;
        $set = [
            BenchmarkPage::class => [$page => ['Title' => 'Page ' . $file, 'URLSegment' => 'page-' . $file, 'Sort' => $file]],
            BenchmarkSection::class => [$section => [
                'Title' => 'Section ' . $file,
                'ShowTitle' => true,
                'Zone' => 'main',
                'Sort' => 1,
                'Parent' => '=>' . BenchmarkPage::class . '.' . $page,
            ]],
        ];
        for ($row = 1; $row <= 2; $row++) {
            $rowId = sprintf('row%d_%d', $file, $row);
            $set[BenchmarkRow::class][$rowId] = ['Title' => 'Row ' . $row, 'Sort' => $row, 'Gutter' => 16, 'Parent' => '=>' . BenchmarkSection::class . '.' . $section];
            for ($column = 1; $column <= 2; $column++) {
                $columnId = sprintf('column%d_%d_%d', $file, $row, $column);
                $set[BenchmarkColumn::class][$columnId] = [
                    'Title' => 'Column ' . $column,
                    'Sort' => $column,
                    'Settings' => '{"default":{"width":6,"offset":0,"visible":true},"overrides":{}}',
                    'Parent' => '=>' . BenchmarkRow::class . '.' . $rowId,
                ];
                $set[BenchmarkContent::class][sprintf('content%d_%d_%d', $file, $row, $column)] = [
                    'Title' => 'Text block',
                    'Sort' => 1,
                    'Content' => '<p>Content of column ' . $column . ' in row ' . $row . ' of page ' . $file . '.</p>',
                    'Parent' => '=>' . BenchmarkColumn::class . '.' . $columnId,
                ];
            }
        }

        return $set;
    }

    /**
     * Loads and clears each group of files with each loader, as one test class with those fixture files would.
     *
     * @param list<list<string>> $groups fixture paths per load
     *
     * @return array{array<string, array{float, int}>, int} wall time and statements per step summed over the
     *                                                      groups, and the records the framework loaded
     */
    private function loadAndClear(array $groups): array
    {
        $sums = [];
        $loaded = 0;
        foreach ($groups as $group) {
            $steps = [];
            $steps['framework load'] = $this->measure(static function () use ($group): void {
                $factory = new FixtureFactory();
                foreach ($group as $path) {
                    YamlFixture::create($path)->writeInto($factory);
                }
            });
            $count = $this->recordCount();
            $steps['framework clear'] = $this->measure(static fn () => static::tempDB()->clearAllData());

            $steps['package load'] = $this->measure(static fn () => FixtureLoader::singleton()->load(...$group));
            $this->assertSame($count, $this->recordCount(), 'both loaders load the same records');
            $steps['package clear'] = $this->measure(static fn () => FixtureLoader::singleton()->reset());
            $this->assertSame(0, $this->recordCount());

            FixtureLoader::singleton()->load(...$group);
            $steps['truncate clear'] = $this->measure(fn () => $this->truncateLoadedTables());

            $loaded += $count;
            foreach ($steps as $step => [$seconds, $statements]) {
                $sums[$step] = [($sums[$step][0] ?? 0.0) + $seconds, ($sums[$step][1] ?? 0) + $statements];
            }
        }

        return [$sums, $loaded];
    }

    /**
     * @return array{float, int} wall time in seconds and the statements MySQL executed
     */
    private function measure(callable $step): array
    {
        $questions = $this->questions();
        $start = hrtime(true);
        $step();
        $seconds = (hrtime(true) - $start) / 1e9;

        // The second SHOW STATUS counts itself
        return [$seconds, $this->questions() - $questions - 1];
    }

    private function questions(): int
    {
        return (int) DB::query("SHOW SESSION STATUS LIKE 'Questions'")->record()['Value'];
    }

    private function recordCount(): int
    {
        return BenchmarkPage::get()->count() + BenchmarkBlock::get()->count();
    }

    private function truncateLoadedTables(): void
    {
        foreach (DB::table_list() as $lowerCase => $table) {
            if (str_starts_with((string) $lowerCase, 'fixturesbenchmark_') || $lowerCase === 'fixtureledger') {
                DB::query(sprintf('TRUNCATE "%s"', $table));
            }
        }
    }

    /**
     * @param array<string, list<array{float, int}>> $results
     */
    private function report(int $files, int $records, array $results): string
    {
        $granularities = [
            'whole set' => 'Whole set: all files in one load, one clear',
            'per file' => sprintf('Per file: one load and one clear per file, summed over %d files', $files),
        ];
        $steps = [
            ['Load', 'framework (YamlFixture, FixtureFactory)', 'framework load'],
            ['Load', 'silverstripe-fixtures', 'package load'],
            ['Clear', 'framework (clearAllData: every table)', 'framework clear'],
            ['Clear', 'silverstripe-fixtures (reset: DELETE)', 'package clear'],
            ['Clear', 'TRUNCATE of the loaded tables', 'truncate clear'],
        ];
        $lines = [
            '# Fixture loading benchmark',
            '',
            sprintf('Test data: %d files, %d records. Median of %d rounds; storage requests are the statements MySQL counted (`Questions`).', $files, $records, self::ROUNDS),
            '',
            'A test class with one fixture file loads and clears as one per-file step; the whole set shows what loading many files together in one load saves.',
            '',
            '| Measured as | Step | Loader | Time | Storage requests |',
            '|---|---|---|---:|---:|',
        ];
        foreach ($granularities as $granularity => $measuredAs) {
            foreach ($steps as [$step, $loader, $key]) {
                $key = $granularity . ' ' . $key;
                $lines[] = sprintf(
                    '| %s | %s | %s | %.3f s | %s |',
                    $measuredAs,
                    $step,
                    $loader,
                    $this->median(array_column($results[$key], 0)),
                    number_format($this->median(array_column($results[$key], 1))),
                );
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param list<float|int> $values
     */
    private function median(array $values): float
    {
        sort($values);

        return (float) $values[intdiv(count($values), 2)];
    }
}
