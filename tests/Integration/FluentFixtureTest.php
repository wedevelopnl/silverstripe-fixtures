<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Tests\Integration;

use Page;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Versioned\Versioned;
use TractorCow\Fluent\Extension\FluentExtension;
use TractorCow\Fluent\Extension\FluentVersionedExtension;
use TractorCow\Fluent\Model\Locale;
use TractorCow\Fluent\State\FluentState;
use WeDevelop\Fixtures\FixtureLoader;
use WeDevelop\Fixtures\InvalidFixtureException;
use WeDevelop\Fixtures\LoadedFixtures;
use WeDevelop\Fixtures\Tests\Integration\Model\LocalisedRecord;
use WeDevelop\Fixtures\Tests\Integration\Model\LocalisedSub;
use WeDevelop\Fixtures\Tests\Integration\Model\Tag;

final class FluentFixtureTest extends SapphireTest
{
    use RowSnapshot;

    private const string FIXTURES = 'wedevelopnl/silverstripe-fixtures:tests/Integration/fixtures/';

    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        Tag::class,
        LocalisedRecord::class,
        LocalisedSub::class,
    ];

    protected static $required_extensions = [
        LocalisedRecord::class => [Versioned::class, FluentVersionedExtension::class],
    ];

    public static function getExtraDataObjects(): array
    {
        return self::fluentInstalled() ? parent::getExtraDataObjects() : [Tag::class];
    }

    public static function getRequiredExtensions(): array
    {
        return self::fluentInstalled() ? parent::getRequiredExtensions() : [];
    }

    public function testValuesPerLanguageNeedATranslatedClass(): void
    {
        $this->expectException(InvalidFixtureException::class);
        $this->expectExceptionMessage(
            Tag::class . '.red: "@locales": ' . (self::fluentInstalled()
                ? Tag::class . ' is not translated'
                : 'translations need the translation module, tractorcow/silverstripe-fluent'),
        );

        FixtureLoader::singleton()->load(self::FIXTURES . 'localised-invalid.yml');
    }

    public function testEveryLanguageShowsItsOwnValues(): void
    {
        $loaded = $this->loadLocalised();

        $home = $loaded->idOf(LocalisedRecord::class, 'home');
        $sub = $loaded->idOf(LocalisedSub::class, 'sub');
        $this->assertSame('Home', $this->inLocale('en_US', LocalisedRecord::class, $home)?->Title);
        $this->assertSame('Thuis', $this->inLocale('nl_NL', LocalisedRecord::class, $home)?->Title);
        $this->assertSame('A summary', $this->inLocale('en_US', LocalisedSub::class, $sub)?->Summary);
        $this->assertSame('Een samenvatting', $this->inLocale('nl_NL', LocalisedSub::class, $sub)?->Summary);
        $this->assertSame('Onder', $this->inLocale('nl_NL', LocalisedSub::class, $sub)?->Title);
        $this->assertSame(4, $this->inLocale('nl_NL', LocalisedSub::class, $sub)?->Rating, 'a field that is not translated is shared');
    }

    public function testARecordExistsAndIsPublishedInTheStatedLocalesOnly(): void
    {
        $loaded = $this->loadLocalised();

        $home = LocalisedRecord::get()->byID($loaded->idOf(LocalisedRecord::class, 'home'));
        $draft = LocalisedRecord::get()->byID($loaded->idOf(LocalisedRecord::class, 'draft'));
        $this->assertNotNull($home);
        $this->assertNotNull($draft);
        foreach (['en_US', 'nl_NL'] as $locale) {
            $this->assertTrue($home->existsInLocale($locale), 'home exists in ' . $locale);
            $this->assertTrue($home->isPublishedInLocale($locale), 'home is published in ' . $locale);
        }

        $this->assertFalse($home->existsInLocale('de_DE'));
        $this->assertTrue($draft->existsInLocale('nl_NL'));
        $this->assertFalse($draft->existsInLocale('en_US'));
        $this->assertFalse($draft->isPublishedInLocale('nl_NL'));
        $this->assertTrue($draft->isDraftedInLocale('nl_NL'));
    }

    public function testLocalisedRowsAreTheRowsWriteLeavesInEachLocale(): void
    {
        $this->requireFluent();
        $tables = ['FixturesTest_LocalisedRecord_Localised', 'FixturesTest_LocalisedSub_Localised'];
        FixtureLoader::singleton()->load(self::FIXTURES . 'locales.yml');
        DBDatetime::set_mock_now('2026-11-27 10:00:00');
        $record = LocalisedSub::create();
        $this->writeInLocale('en_US', $record, ['Title' => 'Sub', 'Summary' => 'A summary', 'Rating' => 4]);
        $this->writeInLocale('nl_NL', $record, ['Title' => 'Onder', 'Summary' => 'Een samenvatting']);
        $written = $this->snapshot($tables, ['sub' => $record->ID]);
        $this->assertCount(2, $written['FixturesTest_LocalisedSub_Localised']);
        $this->emptyTables($tables);

        $loaded = FixtureLoader::singleton()->load(self::FIXTURES . 'localised.yml');

        $this->assertSame(
            $written,
            array_map(
                static fn (array $rows): array => array_values(array_filter($rows, static fn (array $row): bool => $row['RecordID'] === 'sub')),
                $this->snapshot($tables, ['sub' => $loaded->idOf(LocalisedSub::class, 'sub')]),
            ),
        );
    }

    public function testAPageShowsEachLanguageWithItsOwnTitle(): void
    {
        $this->requireFluent();
        FixtureLoader::singleton()->load(self::FIXTURES . 'locales.yml');
        $id = FixtureLoader::singleton()->load(self::FIXTURES . 'pages-localised.yml')->idOf('Page', 'home');

        foreach (['en_US' => 'Home', 'nl_NL' => 'Thuis'] as $locale => $title) {
            $live = FluentState::singleton()->withState(static function (FluentState $state) use ($locale, $id): ?SiteTree {
                $state->setLocale($locale);

                return Versioned::get_by_stage(Page::class, Versioned::LIVE)->byID($id);
            });
            $this->assertSame($title, $live?->Title, $locale);
        }
    }

    public function testLoadedLocalesAreKnownToFluentAtOnce(): void
    {
        $this->requireFluent();
        $this->assertSame([], Locale::getCached()->column('Locale'), 'the cached list starts empty');

        FixtureLoader::singleton()->load(self::FIXTURES . 'locales.yml');

        $this->assertSame(['en_US', 'nl_NL', 'de_DE'], Locale::getCached()->column('Locale'));
    }

    public function testACachedLocaleListHidesNeitherALoadNorAReset(): void
    {
        $this->requireFluent();
        FixtureLoader::singleton()->load(self::FIXTURES . 'locales.yml');
        // Fluent marks the locale's lists complete: a record it does not hold reads as not in that locale
        FluentVersionedExtension::prepoulateIdsInLocale('nl_NL', LocalisedRecord::class);
        $loaded = FixtureLoader::singleton()->load(self::FIXTURES . 'localised.yml');
        $home = LocalisedRecord::get()->byID($loaded->idOf(LocalisedRecord::class, 'home'));
        $this->assertNotNull($home);

        $this->assertTrue($home->isPublishedInLocale('nl_NL'), 'the load flushed the cache');

        FixtureLoader::singleton()->reset();

        $this->assertFalse($home->isPublishedInLocale('nl_NL'), 'the reset flushed the cache');
    }

    public function testResetRemovesTheLocalisedRows(): void
    {
        $this->loadLocalised();
        $this->assertSame(
            [
                'FixturesTest_LocalisedRecord_Localised' => 5,
                'FixturesTest_LocalisedRecord_Localised_Live' => 4,
                'FixturesTest_LocalisedRecord_Localised_Versions' => 5,
                'FixturesTest_LocalisedSub_Localised' => 2,
                'FixturesTest_LocalisedSub_Localised_Live' => 2,
                'FixturesTest_LocalisedSub_Localised_Versions' => 2,
            ],
            $this->localisedRowCounts(),
            'home, draft and sub in their locales; only home and sub are published',
        );

        FixtureLoader::singleton()->reset();

        $this->assertSame([0, 0, 0, 0, 0, 0], array_values($this->localisedRowCounts()));
    }

    public function testEveryLocalisedValueMustBeATranslatedField(): void
    {
        $this->requireFluent();

        try {
            FixtureLoader::singleton()->load(self::FIXTURES . 'localised-invalid.yml');
            $this->fail('Values of fields that are not translated must be refused');
        } catch (InvalidFixtureException $exception) {
            $where = self::FIXTURES . 'localised-invalid.yml, ' . LocalisedRecord::class . '.home: "@locales": ';
            $this->assertSame(
                [
                    $where . '"Sort" is not translated (nl_NL)',
                    $where . '"Unknown" is not translated (nl_NL)',
                    $where . '"Title" cannot hold a reference (nl_NL)',
                    self::FIXTURES . 'localised-invalid.yml, ' . Tag::class . '.red: "@locales": ' . Tag::class . ' is not translated',
                ],
                $exception->getProblems(),
            );
        }
    }

    private static function fluentInstalled(): bool
    {
        return class_exists(FluentExtension::class);
    }

    private function requireFluent(): void
    {
        if (!self::fluentInstalled()) {
            $this->markTestSkipped('tractorcow/silverstripe-fluent is not installed');
        }
    }

    /**
     * @return array<string, int> row count by localised table of LocalisedRecord and LocalisedSub
     */
    private function localisedRowCounts(): array
    {
        $counts = [];
        foreach (['FixturesTest_LocalisedRecord', 'FixturesTest_LocalisedSub'] as $table) {
            foreach (['', '_Live', '_Versions'] as $suffix) {
                $localised = $table . '_Localised' . $suffix;
                $counts[$localised] = (int) DB::query(sprintf('SELECT COUNT(*) FROM "%s"', $localised))->value();
            }
        }

        return $counts;
    }

    private function loadLocalised(): LoadedFixtures
    {
        $this->requireFluent();

        return FixtureLoader::singleton()->load(self::FIXTURES . 'locales.yml', self::FIXTURES . 'localised.yml');
    }

    /**
     * @param class-string<DataObject> $class
     */
    private function inLocale(string $locale, string $class, int $id): ?DataObject
    {
        return FluentState::singleton()->withState(static function (FluentState $state) use ($locale, $class, $id): ?DataObject {
            $state->setLocale($locale);

            return $class::get()->byID($id);
        });
    }

    /**
     * @param array<string, mixed> $values
     */
    private function writeInLocale(string $locale, DataObject $record, array $values): void
    {
        FluentState::singleton()->withState(static function (FluentState $state) use ($locale, $record, $values): void {
            $state->setLocale($locale);
            $record->update($values);
            $record->write();
        });
    }
}
