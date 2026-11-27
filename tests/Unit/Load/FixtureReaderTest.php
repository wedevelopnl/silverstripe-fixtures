<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Tests\Unit\Load;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WeDevelop\Fixtures\Load\FixtureReader;
use WeDevelop\Fixtures\Load\FixtureRecord;
use WeDevelop\Fixtures\Load\FixtureSet;

#[CoversClass(FixtureReader::class)]
#[CoversClass(FixtureRecord::class)]
#[CoversClass(FixtureSet::class)]
final class FixtureReaderTest extends TestCase
{
    private const string DIR = __DIR__ . '/../fixtures/';

    public function testItReadsRecordsInDeclarationOrderAcrossFiles(): void
    {
        $set = (new FixtureReader())->read([
            'pages.yml' => self::DIR . 'pages.yml',
            'more.yml' => self::DIR . 'more.yml',
        ]);

        $this->assertSame([], $set->problems);
        $this->assertSame(
            [
                'Page.home',
                'Page.about',
                'Page.team',
                'SilverStripe\Assets\Image.logo',
                'SilverStripe\Assets\Image.banner',
                'Page.contact',
                'Page.empty',
            ],
            array_map(static fn (FixtureRecord $record): string => $record->key(), $set->records),
        );
    }

    public function testItSplitsLoaderKeysFromFieldsAndAppliesTheClassLevelPublishedDefault(): void
    {
        $set = (new FixtureReader())->read(['pages.yml' => self::DIR . 'pages.yml']);
        [$home, $about, $team, $logo] = $set->records;

        $this->assertSame('pages.yml', $home->file);
        $this->assertSame(['Title' => 'Home'], $home->fields);
        $this->assertTrue($home->published);
        $this->assertSame(['nl_NL' => ['Title' => 'Thuis']], $home->locales);
        $this->assertFalse($about->published, 'a record overrides the class default');
        $this->assertSame([], $home->draft);
        $this->assertSame(['Title' => 'Team'], $team->fields, 'draft values are not the record\'s own');
        $this->assertSame(['Title' => 'Team (draft)'], $team->draft);
        $this->assertNull($logo->published);
        $this->assertSame(dirname(self::DIR . 'pages.yml') . '/../../files/logo.png', $logo->source, 'a relative source is relative to its fixture file');
        $this->assertFileEquals(__DIR__ . '/../../files/logo.png', (string) $logo->source);
    }

    public function testAnAbsoluteSourceIsKeptAsWritten(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'fixture');
        file_put_contents((string) $file, "SilverStripe\\Assets\\File:\n  notes:\n    '@source': /srv/files/notes.txt\n");

        try {
            $set = (new FixtureReader())->read(['absolute.yml' => (string) $file]);
        } finally {
            unlink((string) $file);
        }

        $this->assertSame('/srv/files/notes.txt', $set->records[0]->source);
    }

    public function testARecordWithoutValuesHasNoFields(): void
    {
        $set = (new FixtureReader())->read(['more.yml' => self::DIR . 'more.yml']);

        $this->assertSame('empty', $set->records[2]->identifier);
        $this->assertSame([], $set->records[2]->fields);
    }

    public function testTheSameClassAndIdentifierTwiceInOneLoadNamesBothFiles(): void
    {
        $set = (new FixtureReader())->read([
            'pages.yml' => self::DIR . 'pages.yml',
            'duplicate.yml' => self::DIR . 'duplicate.yml',
        ]);

        $this->assertSame(
            ['duplicate.yml, Page.home: is also declared in pages.yml'],
            $set->problems,
        );
    }

    public function testTheSameClassWrittenAnotherWayIsTheSameClass(): void
    {
        $set = (new FixtureReader())->read([
            'pages.yml' => self::DIR . 'pages.yml',
            'duplicate-spelling.yml' => self::DIR . 'duplicate-spelling.yml',
        ]);

        $this->assertSame(
            ['duplicate-spelling.yml, \\page.home: is also declared in pages.yml'],
            $set->problems,
        );
    }

    public function testARecordWhoseBodyIsNotAMapOfValuesOrWhoseIdentifierHoldsWhitespaceIsReported(): void
    {
        $set = (new FixtureReader())->read(['malformed-records.yml' => self::DIR . 'malformed-records.yml']);

        $this->assertSame(
            [
                'malformed-records.yml, Page.home: expected field values by name',
                'malformed-records.yml, Page.about: expected field values by name',
                'malformed-records.yml, Page.About us: an identifier cannot hold whitespace, as a reference could not name it',
            ],
            $set->problems,
        );
        $this->assertSame(['Page.contact'], array_map(static fn (FixtureRecord $record): string => $record->key(), $set->records));
    }

    public function testEveryMalformedLoaderKeyIsReported(): void
    {
        $set = (new FixtureReader())->read(['invalid-keys.yml' => self::DIR . 'invalid-keys.yml']);

        $this->assertSame(
            [
                'invalid-keys.yml, Page: "@published" must be true or false',
                'invalid-keys.yml, Page: unknown loader key "@sort"',
                'invalid-keys.yml, Page: "@draft" belongs on a record, not on a class',
                'invalid-keys.yml, Page.home: "@published" must be true or false',
                'invalid-keys.yml, Page.home: "@locales" must map a locale code to field values',
                'invalid-keys.yml, Page.home: "@source" must be a path',
                'invalid-keys.yml, Page.home: "@draft" must map field values',
                'invalid-keys.yml, Page.home: unknown loader key "@unknown"',
                'invalid-keys.yml, Page.about: "@locales" must map a locale code to field values',
                'invalid-keys.yml, Page.about: "@draft" must map field values',
                'invalid-keys.yml, Page.contact: "@draft" must map field values',
                'invalid-keys.yml, Member: expected records by identifier',
            ],
            $set->problems,
        );
    }

    public function testAFileThatIsNotAMapOfClassesIsReported(): void
    {
        $set = (new FixtureReader())->read(['not-a-map.yml' => self::DIR . 'not-a-map.yml']);

        $this->assertSame(['not-a-map.yml: expected classes at the top level'], $set->problems);
    }

    public function testAnEmptyFileHoldsNoRecords(): void
    {
        $set = (new FixtureReader())->read(['empty.yml' => self::DIR . 'empty.yml']);

        $this->assertSame([], $set->records);
        $this->assertSame([], $set->problems);
    }

    public function testAParseErrorIsReportedWithTheFile(): void
    {
        $set = (new FixtureReader())->read(['broken.yml' => self::DIR . 'broken.yml']);

        $this->assertCount(1, $set->problems);
        $this->assertStringStartsWith('broken.yml: cannot be parsed: ', $set->problems[0]);
    }

    public function testAFileIsParsedOncePerModificationTime(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'fixture');
        $this->assertIsString($path);
        file_put_contents($path, "Page:\n  one: { Title: One }\n");
        touch($path, 1000);
        $reader = new FixtureReader();
        $reader->read(['f.yml' => $path]);

        file_put_contents($path, "Page:\n  two: { Title: Two }\n");
        touch($path, 1000);
        clearstatcache();
        $cached = $reader->read(['f.yml' => $path]);
        touch($path, 2000);
        clearstatcache();
        $fresh = $reader->read(['f.yml' => $path]);
        unlink($path);

        $this->assertSame('one', $cached->records[0]->identifier);
        $this->assertSame('two', $fresh->records[0]->identifier);
    }
}
