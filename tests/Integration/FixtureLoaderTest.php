<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
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
use WeDevelop\Fixtures\Load\LoadPlanner;
use WeDevelop\Fixtures\Load\LookupFetcher;
use WeDevelop\Fixtures\Load\RowWriter;
use WeDevelop\Fixtures\Reset\DatabaseReset;
use WeDevelop\Fixtures\ResetRefusedException;
use WeDevelop\Fixtures\Schema\ClassSchema;
use WeDevelop\Fixtures\Schema\SchemaMap;
use WeDevelop\Fixtures\Schema\ValueConverter;
use WeDevelop\Fixtures\Tests\Integration\Model\Plain;
use WeDevelop\Fixtures\Tests\Integration\Model\PlainLink;
use WeDevelop\Fixtures\Tests\Integration\Model\Sub;
use WeDevelop\Fixtures\Tests\Integration\Model\SubWithoutTable;
use WeDevelop\Fixtures\Tests\Integration\Model\Tag;

#[CoversClass(FixtureLoader::class)]
#[CoversClass(SchemaMap::class)]
#[CoversClass(ClassSchema::class)]
#[CoversClass(LookupFetcher::class)]
#[CoversClass(LoadPlanner::class)]
#[CoversClass(ValueConverter::class)]
#[CoversClass(RowWriter::class)]
#[CoversClass(DatabaseReset::class)]
#[CoversClass(FixtureLedger::class)]
#[CoversClass(InvalidFixtureException::class)]
#[CoversClass(ResetRefusedException::class)]
final class FixtureLoaderTest extends SapphireTest
{
    private const string FIXTURES = __DIR__ . '/fixtures/';

    /**
     * An existing fixture named as a module resource, which the loader does not resolve.
     */
    private const string MODULE_RESOURCE = 'wedevelopnl/silverstripe-fixtures:tests/Integration/fixtures/tags.yml';

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

    public function testAPathRelativeToTheProjectRootLoads(): void
    {
        // The harness installs the module here
        $loaded = FixtureLoader::singleton()->load('vendor/wedevelopnl/silverstripe-fixtures/tests/Integration/fixtures/tags.yml');

        $this->assertSame(['red', 'blue'], array_keys($loaded->toArray()[Tag::class]));
    }

    public function testEveryProblemOfAnInvalidSetIsListedAndNothingIsWritten(): void
    {
        try {
            FixtureLoader::singleton()->load(self::FIXTURES . 'invalid.yml', self::FIXTURES . 'missing.yml', self::MODULE_RESOURCE);
            $this->fail('An invalid fixture set must be refused');
        } catch (InvalidFixtureException $exception) {
            $where = self::FIXTURES . 'invalid.yml, ';
            $this->assertSame(
                [
                    self::FIXTURES . 'missing.yml: no fixture file at this path',
                    self::MODULE_RESOURCE . ': no fixture file at this path',
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
                    $where . Plain::class . '.broken: "@draft": ' . Plain::class . ' has no published state',
                    $where . Plain::class . '.broken: "@draft": unknown value "Subtitle"',
                    $where . Plain::class . '.broken: "@draft": "ID" is set by the loader',
                    $where . Plain::class . '.broken: "@draft": "Body" cannot hold a reference',
                    $where . Plain::class . '.broken: "@draft": "Target" cannot hold a reference',
                    $where . Plain::class . '.broken: "@draft": "Tags" cannot hold a reference',
                    $where . Plain::class . '.broken: "@draft": "Children" is set on the record that holds the has_one',
                    $where . Plain::class . '.broken: "@draft": unknown value "@source"',
                    $where . Plain::class . '.twice: "Tags" pairs it with ' . Tag::class . '.red again, with other extra field values than ' . Plain::class . '.twice "Tags"',
                    $where . Tag::class . '.red: refers to ' . Plain::class . '.missing, which no file of this load holds',
                    $where . Tag::class . '.blue: "Plains" pairs it with ' . Plain::class . '.twice again, with other extra field values than ' . Plain::class . '.twice "Tags"',
                    $where . 'Unknown\Kind.thing: unknown class "Unknown\Kind"',
                ],
                $exception->getProblems(),
            );
        }

        $this->assertSame(0, Plain::get()->count());
        $this->assertSame(0, Tag::get()->count());
        $this->assertSame(0, FixtureLedger::get()->count());
    }

    public function testAFileWithoutRecordsOrNoFileAtAllLoadsNothingAndSendsNoQuery(): void
    {
        $loader = FixtureLoader::singleton();
        // Reading the counter is itself a statement, so an empty load adds exactly what an idle read does
        $before = $this->questions();
        $idle = $this->questions() - $before;

        $before = $this->questions();
        $fromEmptyFile = $loader->load(self::FIXTURES . 'empty.yml');
        $withoutFiles = $loader->load();
        $sent = $this->questions() - $before;

        $this->assertSame([], $fromEmptyFile->toArray());
        $this->assertSame([], $withoutFiles->toArray());
        $this->assertSame($idle, $sent);
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

        // Above, not at, 501: the auto-increment counter may be higher still, as a rollback does not lower it
        $this->assertGreaterThan(500, $loaded->idOf(Tag::class, 'red'));
        $this->assertSame($loaded->idOf(Tag::class, 'red') + 1, $loaded->idOf(Tag::class, 'blue'));
    }

    public function testALoadAfterAResetNeverHandsOutAnIdAgain(): void
    {
        $first = FixtureLoader::singleton()->load(self::FIXTURES . 'tags.yml', self::FIXTURES . 'plains.yml');
        $firstJoinRow = $this->ledgerFirstId('FixturesTest_Plain_Tags');
        FixtureLoader::singleton()->reset();

        $second = FixtureLoader::singleton()->load(self::FIXTURES . 'tags.yml', self::FIXTURES . 'plains.yml');

        $this->assertGreaterThan($first->idOf(Tag::class, 'green'), $second->idOf(Tag::class, 'red'), 'records');
        $this->assertGreaterThan($first->idOf(Sub::class, 'second'), $second->idOf(Plain::class, 'first'), 'records of a subclass');
        $this->assertGreaterThan($firstJoinRow, $this->ledgerFirstId('FixturesTest_Plain_Tags'), 'join rows');
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

    public function testResetRemovesTheJoinRowsAndJoinRecordsOfRemovedRecordsOnly(): void
    {
        $siteTag = $this->tag('Site tag');
        $sitePlain = Plain::create();
        $sitePlain->Title = 'Site plain';
        $sitePlain->write();
        $sitePlain->Tags()->add($siteTag);
        $keptLink = PlainLink::create(['FromID' => $sitePlain->ID, 'ToID' => $siteTag->ID]);
        $keptLink->write();
        $loaded = FixtureLoader::singleton()->load(self::FIXTURES . 'tags.yml', self::FIXTURES . 'plains.yml');
        $red = $loaded->idOf(Tag::class, 'red');
        // Added at runtime, so outside the ledger: a loaded record on either side
        Plain::get()->byID($loaded->idOf(Plain::class, 'first'))?->Tags()->add($siteTag);
        $sitePlain->Tags()->add($red);
        PlainLink::create(['FromID' => $sitePlain->ID, 'ToID' => $red])->write();

        FixtureLoader::singleton()->reset();

        $this->assertSame(
            [[(string) $sitePlain->ID, (string) $siteTag->ID]],
            array_map(
                static fn (array $row): array => [(string) $row['FixturesTest_PlainID'], (string) $row['FixturesTest_TagID']],
                iterator_to_array(DB::query('SELECT "FixturesTest_PlainID", "FixturesTest_TagID" FROM "FixturesTest_Plain_Tags"'), false),
            ),
        );
        $this->assertSame([$keptLink->ID], PlainLink::get()->column('ID'));
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

    private function ledgerFirstId(string $table): int
    {
        return (int) FixtureLedger::get()->find('TableName', $table)?->FirstID;
    }

    /**
     * The statements MySQL has counted for this connection.
     */
    private function questions(): int
    {
        return (int) DB::query("SHOW SESSION STATUS LIKE 'Questions'")->record()['Value'];
    }

    private function tag(string $title): Tag
    {
        $tag = Tag::create();
        $tag->Title = $title;
        $tag->write();

        return $tag;
    }
}
