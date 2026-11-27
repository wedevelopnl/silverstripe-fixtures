<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Tests\Integration\Dev;

use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Kernel;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Output\BufferedOutput;
use WeDevelop\Fixtures\Dev\FixturesTask;
use WeDevelop\Fixtures\FixtureLoader;
use WeDevelop\Fixtures\InvalidFixtureException;
use WeDevelop\Fixtures\Tests\Integration\Model\Plain;
use WeDevelop\Fixtures\Tests\Integration\Model\PlainLink;
use WeDevelop\Fixtures\Tests\Integration\Model\Sub;
use WeDevelop\Fixtures\Tests\Integration\Model\Tag;

final class FixturesTaskTest extends FunctionalTest
{
    private const string TAGS = __DIR__ . '/../fixtures/tags.yml';

    private const string PLAINS = __DIR__ . '/../fixtures/plains.yml';

    private const string INVALID = __DIR__ . '/../fixtures/invalid.yml';

    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        Plain::class,
        Sub::class,
        Tag::class,
        PlainLink::class,
    ];

    private string $output = '';

    public function testLoadWritesTheFilesAndListsEveryRecordWithItsId(): void
    {
        $this->assertSame(Command::SUCCESS, $this->runTask(['--action' => 'load', '--file' => [self::TAGS, self::PLAINS]]));

        $ids = Tag::get()->sort('ID')->column('ID');
        $this->assertSame(['Red', 'Blue', 'Green'], Tag::get()->sort('ID')->column('Title'));
        $first = Plain::get()->filter('Title', 'First')->first();
        $this->assertNotNull($first);
        $this->assertSame(['Blue'], $first->Tags()->column('Title'), 'a reference resolves across the files');
        $this->assertStringContainsString(sprintf("%s.red: %d\n%s.blue: %d", Tag::class, $ids[0], Tag::class, $ids[1]), $this->output);
        $this->assertStringContainsString(sprintf('%s.first: %d', Plain::class, $first->ID), $this->output);
    }

    public function testLoadingAnInvalidFileListsEachProblemOnItsOwnLineInTheBrowserAndWritesNothing(): void
    {
        try {
            FixtureLoader::singleton()->load(self::INVALID);
            $this->fail('The fixture must be invalid');
        } catch (InvalidFixtureException $exception) {
            $problems = $exception->getProblems();
        }

        $exitCode = $this->runTask(['--action' => 'load', '--file' => [self::INVALID]], PolyOutput::FORMAT_HTML);

        $this->assertSame(Command::FAILURE, $exitCode);
        $lines = array_map(
            static fn (string $line): string => trim(html_entity_decode(strip_tags($line))),
            explode('<br>', $this->output),
        );
        foreach ($problems as $problem) {
            $this->assertContains('- ' . $problem, $lines);
        }
        $this->assertCount(0, Plain::get());
        $this->assertCount(0, Tag::get());
    }

    public function testUnloadClearsWhatWasLoadedAndKeepsOtherRecords(): void
    {
        $this->runTask(['--action' => 'load', '--file' => [self::TAGS]]);
        Tag::create(['Title' => 'Written by a test'])->write();

        $this->assertSame(Command::SUCCESS, $this->runTask(['--action' => 'unload']));

        $this->assertSame(['Written by a test'], Tag::get()->column('Title'));
    }

    public function testResetRestoresTheFilesAfterTheDataChanged(): void
    {
        $this->runTask(['--action' => 'load', '--file' => [self::TAGS]]);
        $red = Tag::get()->filter('Title', 'Red')->first();
        $this->assertNotNull($red);
        $red->Title = 'Edited';
        $red->write();

        $this->assertSame(Command::SUCCESS, $this->runTask(['--action' => 'reset', '--file' => [self::TAGS]]));

        $this->assertSame(['Red', 'Blue'], Tag::get()->sort('ID')->column('Title'));
    }

    public function testResetWithAnInvalidFileListsTheProblemsAndKeepsTheEarlierData(): void
    {
        $this->runTask(['--action' => 'load', '--file' => [self::TAGS]]);

        $this->assertSame(Command::FAILURE, $this->runTask(['--action' => 'reset', '--file' => [self::INVALID]]));

        $this->assertStringContainsString('nothing was written', $this->output);
        $this->assertStringContainsString(self::INVALID . ', ', $this->output);
        $this->assertSame(['Red', 'Blue'], Tag::get()->sort('ID')->column('Title'));
    }

    public function testUnloadOnALiveSiteFailsWithTheRefusalAndKeepsTheData(): void
    {
        $this->runTask(['--action' => 'load', '--file' => [self::TAGS]]);
        $kernel = Injector::inst()->get(Kernel::class);
        $environment = $kernel->getEnvironment();
        $kernel->setEnvironment('live');

        try {
            $exitCode = $this->runTask(['--action' => 'unload']);
        } finally {
            $kernel->setEnvironment($environment);
        }

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('only runs in development and test environments', $this->output);
        $this->assertSame(['Red', 'Blue'], Tag::get()->sort('ID')->column('Title'));
    }

    public function testLoadWithoutAFileFails(): void
    {
        $this->assertSame(Command::INVALID, $this->runTask(['--action' => 'load']));

        $this->assertStringContainsString('Name at least one fixture file with --file', $this->output);
    }

    public function testAMissingOrUnknownActionFailsAndNamesTheActions(): void
    {
        foreach ([[], ['--action' => 'truncate']] as $action) {
            $this->assertSame(Command::INVALID, $this->runTask([...$action, '--file' => [self::TAGS]]));
            $this->assertStringContainsString('--action=load, --action=unload or --action=reset', $this->output);
        }

        $this->assertCount(0, Tag::get());
    }

    public function testTheDevTasksUrlLoadsTheFilesOfItsQueryString(): void
    {
        $this->logInWithPermission('ADMIN');
        // The task runner streams a task's output instead of putting it in the response body
        $this->expectOutputRegex('/' . preg_quote(Tag::class . '.blue: ', '/') . '\d+/');

        $response = $this->get('dev/tasks/fixtures?action=load&file[]=' . rawurlencode(self::TAGS));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['Red', 'Blue'], Tag::get()->sort('ID')->column('Title'));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function runTask(array $options, string $format = PolyOutput::FORMAT_ANSI): int
    {
        $task = new FixturesTask();
        $buffer = new BufferedOutput();
        $exitCode = $task->run(
            new ArrayInput($options, new InputDefinition($task->getOptions())),
            new PolyOutput($format, wrappedOutput: $buffer),
        );
        $this->output = $buffer->fetch();

        return $exitCode;
    }
}
