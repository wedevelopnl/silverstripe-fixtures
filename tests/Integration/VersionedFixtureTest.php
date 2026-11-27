<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Tests\Integration;

use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Fixtures\FixtureLoader;
use WeDevelop\Fixtures\InvalidFixtureException;
use WeDevelop\Fixtures\LoadedFixtures;
use WeDevelop\Fixtures\Tests\Integration\Model\UnstagedRecord;
use WeDevelop\Fixtures\Tests\Integration\Model\VersionedRecord;
use WeDevelop\Fixtures\Tests\Integration\Model\VersionedSub;

final class VersionedFixtureTest extends SapphireTest
{
    use RowSnapshot;

    private const string FIXTURES = 'wedevelopnl/silverstripe-fixtures:tests/Integration/fixtures/';

    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        VersionedRecord::class,
        VersionedSub::class,
        UnstagedRecord::class,
    ];

    protected static $required_extensions = [
        VersionedRecord::class => [Versioned::class],
    ];

    public static function setUpBeforeClass(): void
    {
        if (class_exists(Versioned::class)) {
            // Versioned without stages needs the `.versioned` service, which $required_extensions and
            // add_extension() both reject: configure it as a static $extensions declaration would
            static::start();
            Config::modify()->merge(UnstagedRecord::class, 'extensions', [Versioned::class . '.versioned']);
        }

        parent::setUpBeforeClass();
    }

    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();
        Config::modify()->remove(UnstagedRecord::class, 'extensions');
    }

    public static function getExtraDataObjects(): array
    {
        return class_exists(Versioned::class) ? parent::getExtraDataObjects() : [];
    }

    public static function getRequiredExtensions(): array
    {
        return class_exists(Versioned::class) ? parent::getRequiredExtensions() : [];
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists(Versioned::class)) {
            $this->markTestSkipped('silverstripe/versioned is not installed');
        }
    }

    public function testAPublishedRecordIsPublishedUnmodifiedWithOneVersion(): void
    {
        $loaded = FixtureLoader::singleton()->load(self::FIXTURES . 'versioned.yml');

        foreach ([[VersionedRecord::class, 'home'], [VersionedSub::class, 'sub']] as [$class, $identifier]) {
            $record = $this->record($loaded, $class, $identifier);
            $this->assertTrue($record->isPublished(), $identifier . ' is published');
            $this->assertTrue($record->isOnDraft(), $identifier . ' is on draft');
            $this->assertFalse($record->isModifiedOnDraft(), $identifier . ' is not modified');
            $this->assertSame(1, $record->Versions()->count(), $identifier . ' has one version');
            $this->assertNotNull(Versioned::get_by_stage($class, Versioned::LIVE)->byID($record->ID));
        }

        $this->assertSame('extra', Versioned::get_by_stage(VersionedSub::class, Versioned::LIVE)
            ->byID($loaded->idOf(VersionedSub::class, 'sub'))?->Extra);
    }

    public function testACachedVersionNumberHidesNeitherALoadNorAReset(): void
    {
        // Without IDs, Versioned marks the live cache complete: an ID it does not hold reads as not on live
        Versioned::prepopulateVersionNumberCacheForStage(VersionedRecord::class, Versioned::LIVE);
        $id = FixtureLoader::singleton()->load(self::FIXTURES . 'versioned.yml')->idOf(VersionedRecord::class, 'home');

        $this->assertSame(1, (int) Versioned::get_versionnumber_by_stage(VersionedRecord::class, Versioned::LIVE, $id), 'the load flushed the cache');

        FixtureLoader::singleton()->reset();

        $this->assertNull(Versioned::get_versionnumber_by_stage(VersionedRecord::class, Versioned::LIVE, $id), 'the reset flushed the cache');
    }

    public function testADraftOnlyRecordIsNotPublished(): void
    {
        $loaded = FixtureLoader::singleton()->load(self::FIXTURES . 'versioned.yml');

        foreach ([[VersionedRecord::class, 'draft'], [VersionedSub::class, 'subDraft']] as [$class, $identifier]) {
            $record = $this->record($loaded, $class, $identifier);
            $this->assertFalse($record->isPublished(), $identifier . ' is not published');
            $this->assertTrue($record->isOnDraft(), $identifier . ' is on draft');
            $this->assertNull(Versioned::get_by_stage($class, Versioned::LIVE)->byID($record->ID));
        }
    }

    public function testAClassLevelPublishedStateAppliesToItsRecordsInThatFileOnly(): void
    {
        $loaded = FixtureLoader::singleton()->load(self::FIXTURES . 'versioned.yml');

        $this->assertTrue($this->record($loaded, VersionedRecord::class, 'home')->isPublished(), 'the class default applies');
        $this->assertFalse($this->record($loaded, VersionedRecord::class, 'draft')->isPublished(), 'a record overrides it');
        $this->assertFalse($this->record($loaded, VersionedSub::class, 'subDraft')->isPublished(), 'other classes keep their own');
    }

    public function testAVersionedClassWithoutStagesHasNoPublishedState(): void
    {
        $loaded = FixtureLoader::singleton()->load(self::FIXTURES . 'versioned.yml');
        $this->assertSame(1, $this->record($loaded, UnstagedRecord::class, 'unstaged')->Versions()->count());

        $this->expectException(InvalidFixtureException::class);
        $this->expectExceptionMessage('is versioned without stages and has no published state');

        FixtureLoader::singleton()->load(self::FIXTURES . 'unstaged-published.yml');
    }

    public function testNewIdsStartAboveArchivedAndLiveOnlyRecords(): void
    {
        $archived = $this->writeRecord('Archived', true);
        $liveOnly = $this->writeRecord('Live only', true);
        $archived->doArchive();
        $liveOnly->deleteFromStage(Versioned::DRAFT);

        $loaded = FixtureLoader::singleton()->load(self::FIXTURES . 'versioned.yml');

        $this->assertSame(max($archived->ID, $liveOnly->ID) + 1, $loaded->idOf(VersionedRecord::class, 'home'));
    }

    public function testResetClearsStagesAndVersionsOfLoadedAndConfiguredRecordsOnly(): void
    {
        $parentRecord = $this->writeRecord('Created parent class record', true);
        $created = VersionedSub::create();
        $created->Title = 'Created through the CMS';
        $created->write();
        $created->publishSingle();
        FixtureLoader::singleton()->load(self::FIXTURES . 'versioned.yml');
        Config::modify()->set(FixtureLoader::class, 'reset_classes', [VersionedSub::class]);

        FixtureLoader::singleton()->reset();

        foreach ([Versioned::DRAFT, Versioned::LIVE] as $stage) {
            $this->assertSame(
                [$parentRecord->ID],
                Versioned::get_by_stage(VersionedRecord::class, $stage)->column('ID'),
                $stage . ' keeps only the parent class record',
            );
        }

        foreach (['FixturesTest_VersionedRecord_Versions', 'FixturesTest_UnstagedRecord_Versions'] as $table) {
            $this->assertSame(
                $table === 'FixturesTest_UnstagedRecord_Versions' ? [] : [$parentRecord->ID],
                array_values(array_unique(array_map(intval(...), DB::query(sprintf('SELECT "RecordID" FROM "%s"', $table))->column()))),
                $table,
            );
        }

        $this->assertSame(0, (int) DB::query('SELECT COUNT(*) FROM "FixturesTest_VersionedSub_Versions"')->value());
    }

    public function testADraftOnlyRecordHasTheRowsWriteLeaves(): void
    {
        // One snapshot per table family: each numbers its records from 1, so their IDs may coincide
        $staged = [
            'FixturesTest_VersionedRecord',
            'FixturesTest_VersionedRecord_Live',
            'FixturesTest_VersionedRecord_Versions',
            'FixturesTest_VersionedSub',
            'FixturesTest_VersionedSub_Live',
            'FixturesTest_VersionedSub_Versions',
        ];
        $unstaged = ['FixturesTest_UnstagedRecord', 'FixturesTest_UnstagedRecord_Versions'];
        DBDatetime::set_mock_now('2026-11-27 10:00:00');
        // The loader writes no author: a record saved by nobody logged in is the one to compare with
        $this->logOut();
        $draft = $this->writeRecord('Draft', false);
        $sub = VersionedSub::create();
        $sub->update(['Title' => 'Sub', 'Extra' => 'extra']);
        $sub->write();
        $unstagedRecord = UnstagedRecord::create();
        $unstagedRecord->Title = 'Unstaged';
        $unstagedRecord->write();
        $written = [
            $this->snapshot($staged, ['draft' => $draft->ID, 'sub' => $sub->ID]),
            $this->snapshot($unstaged, ['unstaged' => $unstagedRecord->ID]),
        ];
        $this->assertCount(2, $written[0]['FixturesTest_VersionedRecord_Versions'], 'write() left a version row each for draft and sub');
        $this->emptyTables([...$staged, ...$unstaged]);

        $loaded = FixtureLoader::singleton()->load(self::FIXTURES . 'versioned-draft.yml');

        $this->assertSame($written, [
            $this->snapshot($staged, [
                'draft' => $loaded->idOf(VersionedRecord::class, 'draft'),
                'sub' => $loaded->idOf(VersionedSub::class, 'sub'),
            ]),
            $this->snapshot($unstaged, ['unstaged' => $loaded->idOf(UnstagedRecord::class, 'unstaged')]),
        ]);
    }

    /**
     * @template T of DataObject
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function record(LoadedFixtures $loaded, string $class, string $identifier): DataObject
    {
        $record = $class::get()->byID($loaded->idOf($class, $identifier));
        $this->assertNotNull($record);

        return $record;
    }

    private function writeRecord(string $title, bool $publish): VersionedRecord
    {
        $record = VersionedRecord::create();
        $record->Title = $title;
        $record->write();
        if ($publish) {
            $record->publishSingle();
        }

        return $record;
    }
}
