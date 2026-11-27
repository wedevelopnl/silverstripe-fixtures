<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Tests\Unit\Dev;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WeDevelop\Fixtures\Dev\FixturesTaskRules;
use WeDevelop\Fixtures\InvalidFixtureException;
use WeDevelop\Fixtures\LoadedFixtures;

#[CoversClass(FixturesTaskRules::class)]
#[CoversClass(InvalidFixtureException::class)]
final class FixturesTaskRulesTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, list<string>, list<string>, string|null}>
     */
    public static function options(): iterable
    {
        $actions = 'Name an action: --action=load, --action=unload or --action=reset';
        $files = 'Name at least one fixture file with --file or directory with --dir';

        yield 'no action' => [null, ['a.yml'], [], $actions];
        yield 'an unknown action' => ['truncate', ['a.yml'], [], $actions];
        yield 'load without files or directories' => ['load', [], [], $files];
        yield 'reset without files or directories' => ['reset', [], [], $files];
        yield 'load with a file' => ['load', ['a.yml'], [], null];
        yield 'reset with a directory' => ['reset', [], ['fixtures'], null];
        yield 'unload without files' => ['unload', [], [], null];
    }

    /**
     * @param list<string> $files
     * @param list<string> $dirs
     */
    #[DataProvider('options')]
    public function testTheOptionsAreCheckedBeforeAnythingRuns(mixed $action, array $files, array $dirs, ?string $problem): void
    {
        $this->assertSame($problem, FixturesTaskRules::optionProblem($action, $files, $dirs));
    }

    public function testADirectoryAddsItsYmlAndYamlFilesInOrderAfterTheFiles(): void
    {
        $paths = FixturesTaskRules::paths(
            ['first.yml'],
            [['fixtures/', ['a.yaml', 'b.yml', 'notes.txt', 'yml', 'c.YML']], ['/abs/more', ['z.yml']]],
        );

        $this->assertSame(['first.yml', 'fixtures/a.yaml', 'fixtures/b.yml', '/abs/more/z.yml'], $paths);
    }

    public function testEveryMissingOrEmptyDirectoryIsNamed(): void
    {
        try {
            FixturesTaskRules::paths([], [['missing', null], ['fixtures', ['b.yml']], ['empty', ['notes.txt']]]);
            $this->fail('A missing or empty directory must be refused');
        } catch (InvalidFixtureException $exception) {
            $this->assertSame(
                ['missing: no directory at this path', 'empty: no .yml or .yaml file in this directory'],
                $exception->getProblems(),
            );
        }
    }

    public function testTheReportListsEveryRecordWithItsId(): void
    {
        $lines = FixturesTaskRules::reportLines(new LoadedFixtures(['Page' => ['home' => 3, 'about' => 4], 'Tag' => ['red' => 1]]));

        $this->assertSame(['Page.home: 3', 'Page.about: 4', 'Tag.red: 1'], $lines);
    }
}
