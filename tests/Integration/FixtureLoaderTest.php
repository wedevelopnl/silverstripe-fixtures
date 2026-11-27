<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Tests\Integration;

use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Kernel;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\Connect\DatabaseException;
use SilverStripe\ORM\DB;
use WeDevelop\Fixtures\FixtureLedger;
use WeDevelop\Fixtures\FixtureLoader;
use WeDevelop\Fixtures\InvalidFixtureException;
use WeDevelop\Fixtures\ResetRefusedException;
use WeDevelop\Fixtures\Tests\Integration\Model\Plain;
use WeDevelop\Fixtures\Tests\Integration\Model\PlainLink;
use WeDevelop\Fixtures\Tests\Integration\Model\Sub;
use WeDevelop\Fixtures\Tests\Integration\Model\SubWithoutTable;
use WeDevelop\Fixtures\Tests\Integration\Model\Tag;

final class FixtureLoaderTest extends SapphireTest
{
    private const string FIXTURES = 'wedevelopnl/silverstripe-fixtures:tests/Integration/fixtures/';

    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        Plain::class,
        Sub::class,
        SubWithoutTable::class,
        Tag::class,
        PlainLink::class,
    ];

    public function testItLoadsFilesTogetherAndAnswersIdsInDeclarationOrder(): void
    {
        $loaded = FixtureLoader::singleton()->load(self::FIXTURES . 'tags.yml', self::FIXTURES . 'plains.yml');

        $this->assertSame(
            [
                Tag::class => ['red', 'blue', 'green'],
                Plain::class => ['first'],
                Sub::class => ['second'],
            ],
            array_map(array_keys(...), $loaded->toArray()),
        );
        $first = Plain::get()->byID($loaded->idOf(Plain::class, 'first'));
        $this->assertNotNull($first);
        $this->assertSame('First', $first->Title);
        $this->assertSame(['Blue'], $first->Tags()->column('Title'), 'a reference resolves across files');
        $this->assertSame('Second', Sub::get()->byID($loaded->idOf(Sub::class, 'second'))?->Title);
    }

    public function testEveryProblemOfAnInvalidSetIsListedAndNothingIsWritten(): void
    {
        try {
            FixtureLoader::singleton()->load(self::FIXTURES . 'invalid.yml', self::FIXTURES . 'missing.yml', 'unknown/module:file.yml');
            $this->fail('An invalid fixture set must be refused');
        } catch (InvalidFixtureException $exception) {
            $where = 'wedevelopnl/silverstripe-fixtures:tests/Integration/fixtures/invalid.yml, ';
            $this->assertSame(
                [
                    self::FIXTURES . 'missing.yml: no fixture file at this path',
                    'unknown/module:file.yml: no fixture file at this path',
                    $where . Plain::class . '.broken: "ID" is set by the loader',
                    $where . Plain::class . '.broken: "ClassName" is set by the loader',
                    $where . Plain::class . '.broken: unknown value "Subtitle"',
                    $where . Plain::class . '.broken: "TitleLength" is a generated column and cannot be written',
                    $where . Plain::class . '.broken: refers to ' . Plain::class . '.missing, which no file of this load holds',
                    $where . Plain::class . '.broken: "Target" must be a reference like =>Class.identifier',
                    $where . Plain::class . '.broken: "Tags" must be a list of references, like [=>Class.a, =>Class.b]',
                    $where . Plain::class . '.broken: "Related" has no extra field "Weight"',
                    $where . Plain::class . '.broken: "Links" is a many_many through; declare records of its join class instead',
                    $where . Plain::class . '.broken: "Children" is set on the record that holds the has_one',
                    $where . Plain::class . '.broken: "ParentID" cannot hold a reference; set the has_one instead: Parent: =>Class.identifier',
                    $where . Plain::class . '.broken: "Body" cannot hold a reference; only a has_one or many_many takes one',
                    $where . Plain::class . '.broken: "@published": ' . Plain::class . ' has no published state',
                    $where . Tag::class . '.red: refers to ' . Plain::class . '.missing, which no file of this load holds',
                    $where . 'Unknown\Kind.thing: unknown class "Unknown\Kind"',
                ],
                $exception->getProblems(),
            );
        }

        $this->assertSame(0, Plain::get()->count());
        $this->assertSame(0, Tag::get()->count());
        $this->assertSame(0, FixtureLedger::get()->count());
    }

    public function testADatabaseErrorLeavesNothingOfTheLoad(): void
    {
        $this->tag('Site tag');

        try {
            FixtureLoader::singleton()->load(self::FIXTURES . 'database-error.yml');
            $this->fail('Two records with the same unique code must fail the insert');
        } catch (DatabaseException $exception) {
            $this->assertStringContainsString('Duplicate entry', $exception->getMessage(), 'database errors propagate unchanged');
        }

        $this->assertSame(0, Plain::get()->count(), 'the table inserted before the failing one is rolled back');
        $this->assertSame(['Site tag'], Tag::get()->column('Title'));
        $this->assertSame(0, FixtureLedger::get()->count());
    }

    public function testTheLedgerRecordsTheIdRangeOfEveryTableWithItsOwnIds(): void
    {
        $loaded = FixtureLoader::singleton()->load(self::FIXTURES . 'tags.yml', self::FIXTURES . 'plains.yml');

        $ranges = [];
        foreach (FixtureLedger::get() as $entry) {
            $ranges[(string) $entry->TableName] = [$entry->FirstID, $entry->LastID];
        }

        $joinId = (int) DB::query('SELECT "ID" FROM "FixturesTest_Plain_Tags"')->value();
        $this->assertSame(
            [
                'FixturesTest_Tag' => [$loaded->idOf(Tag::class, 'red'), $loaded->idOf(Tag::class, 'green')],
                'FixturesTest_Plain' => [$loaded->idOf(Plain::class, 'first'), $loaded->idOf(Sub::class, 'second')],
                'FixturesTest_Plain_Tags' => [$joinId, $joinId],
            ],
            $ranges,
        );
    }

    public function testNewIdsStartAboveTheHighestExistingId(): void
    {
        $existing = Tag::create();
        $existing->ID = 500;
        $existing->Title = 'Existing';
        $existing->write(false, true);

        $loaded = FixtureLoader::singleton()->load(self::FIXTURES . 'tags.yml');

        $this->assertSame(501, $loaded->idOf(Tag::class, 'red'));
        $this->assertSame(502, $loaded->idOf(Tag::class, 'blue'));
    }

    public function testATableTooLargeForOneInsertIsSplitAcrossInserts(): void
    {
        // 150 records each related to all 150: 22,500 join rows of three columns (ID and the two record IDs) need
        // 67,500 placeholders, more than the 65,535 MySQL accepts in one statement
        $count = 150;
        $references = [];
        for ($i = 1; $i <= $count; ++$i) {
            $references[] = '=>' . Plain::class . '.plain' . $i;
        }

        $yaml = Plain::class . ":\n";
        for ($i = 1; $i <= $count; ++$i) {
            $yaml .= sprintf("  plain%d: { Title: Plain %d, Related: [%s] }\n", $i, $i, implode(', ', $references));
        }

        $path = (string) tempnam(sys_get_temp_dir(), 'fixture');
        file_put_contents($path, $yaml);
        try {
            $loaded = FixtureLoader::singleton()->load($path);
        } finally {
            unlink($path);
        }

        $this->assertSame($count ** 2, (int) DB::query('SELECT COUNT(*) FROM "FixturesTest_Plain_Related"')->value());
        $last = Plain::get()->byID($loaded->idOf(Plain::class, 'plain' . $count));
        $this->assertSame($count, $last?->Related()->count());
    }

    public function testResetRemovesTheLoadedRecordsAndTheConfiguredClassesOnly(): void
    {
        $siteTag = $this->tag('Site tag');
        $createdPlain = Plain::create();
        $createdPlain->Title = 'Created by a test';
        $createdPlain->write();
        $createdSub = Sub::create();
        $createdSub->Title = 'Created subclass record';
        $createdSub->write();
        FixtureLoader::singleton()->load(self::FIXTURES . 'tags.yml', self::FIXTURES . 'plains.yml');
        Config::modify()->set(FixtureLoader::class, 'reset_classes', [Sub::class]);

        FixtureLoader::singleton()->reset();

        $this->assertSame(['Site tag'], Tag::get()->column('Title'), 'a record of a loaded class that was not loaded stays');
        $this->assertSame(['Created by a test'], Plain::get()->column('Title'), 'a parent class of a configured one stays');
        $this->assertNull(Sub::get()->byID($createdSub->ID));
        $this->assertSame(0, (int) DB::query('SELECT COUNT(*) FROM "FixturesTest_Sub"')->value());
        $this->assertSame(0, (int) DB::query('SELECT COUNT(*) FROM "FixturesTest_Plain_Tags"')->value());
        $this->assertSame(0, FixtureLedger::get()->count());
        $this->assertNotNull(Tag::get()->byID($siteTag->ID));
    }

    public function testALoadAndResetInsideAnOpenTransactionAreUndoneWithIt(): void
    {
        $connection = DB::get_conn();
        $loaded = FixtureLoader::singleton()->load(self::FIXTURES . 'tags.yml');

        $connection->transactionStart();
        FixtureLoader::singleton()->load(self::FIXTURES . 'tags.yml');
        $this->assertSame(4, Tag::get()->count());
        $this->assertSame(2, FixtureLedger::get()->count());
        FixtureLoader::singleton()->reset();
        $this->assertSame(0, Tag::get()->count());
        $connection->transactionRollback();

        $this->assertSame(['Red', 'Blue'], Tag::get()->sort('ID')->column('Title'), 'only the load before the transaction stays');
        $this->assertSame(1, FixtureLedger::get()->count());
        $this->assertSame($loaded->idOf(Tag::class, 'red'), (int) FixtureLedger::get()->first()?->FirstID);
    }

    public function testResetIsRefusedOutsideTheDevAndTestEnvironments(): void
    {
        FixtureLoader::singleton()->load(self::FIXTURES . 'tags.yml');
        $kernel = Injector::inst()->get(Kernel::class);
        $environment = $kernel->getEnvironment();
        $kernel->setEnvironment('live');

        try {
            FixtureLoader::singleton()->reset();
            $this->fail('A reset on a live environment must be refused');
        } catch (ResetRefusedException) {
            $this->assertTrue(Director::isLive());
        } finally {
            $kernel->setEnvironment($environment);
        }

        $this->assertSame(2, Tag::get()->count());
    }

    private function tag(string $title): Tag
    {
        $tag = Tag::create();
        $tag->Title = $title;
        $tag->write();

        return $tag;
    }
}
