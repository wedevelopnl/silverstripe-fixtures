<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Tests\Unit\Load;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WeDevelop\Fixtures\FixtureLedger;
use WeDevelop\Fixtures\Load\FixtureRecord;
use WeDevelop\Fixtures\Load\Plan;
use WeDevelop\Fixtures\Load\RowPlanner;
use WeDevelop\Fixtures\Schema\ClassSchema;
use WeDevelop\Fixtures\Schema\FieldKind;
use WeDevelop\Fixtures\Tests\Unit\ClassSchemas;

#[CoversClass(RowPlanner::class)]
final class RowPlannerTest extends TestCase
{
    private const string NOW = '2026-11-27 10:00:00';

    public function testNewIdsStartAboveTheFloorOfEachBaseTable(): void
    {
        $plan = $this->plan([
            $this->record('App\Plain', 'first'),
            $this->record('App\Tag', 'red'),
            $this->record('App\Sub', 'second'),
        ], ['Plain' => 10, 'Tag' => 0]);

        $this->assertSame([11, 1, 12], [$plan->idOf('App\Plain.first'), $plan->idOf('App\Tag.red'), $plan->idOf('App\Sub.second')]);
        $this->assertSame(['App\Plain', 'App\Tag', 'App\Sub'], $plan->classes());
    }

    public function testARecordHasARowInEachTableOfItsClassWithItsRawValuesOverTheDefaultsAndTheirSpecs(): void
    {
        $plan = $this->plan([$this->record('App\Sub', 'second', ['SubTitle' => 'Deep', 'Title' => 'Second', 'Price' => ['Amount' => 5]])]);

        $this->assertSame(
            [
                'Plain' => [[
                    'ID' => 11,
                    'Body' => 'Default',
                    'Title' => 'Second',
                    'Price' => ['Amount' => 5],
                    'ClassName' => 'App\Sub',
                    'Created' => self::NOW,
                    'LastEdited' => self::NOW,
                ]],
                'Sub' => [['ID' => 11, 'SubTitle' => 'Deep']],
            ],
            $this->recordRows($plan),
        );
        $this->assertSame(
            [
                'Plain' => [0 => [['Body' => 'Text', 'Title' => 'Varchar', 'Price' => 'Money'], null]],
                'Sub' => [0 => [['SubTitle' => 'Varchar'], null]],
            ],
            $plan->conversions(),
        );
    }

    public function testAStatedCreatedOrLastEditedIsKeptAndConverted(): void
    {
        $plan = $this->plan([$this->record('App\Tag', 'red', ['Created' => '2020-01-01 00:00:00', 'LastEdited' => null])]);

        $this->assertSame(
            ['ID' => 1, 'Created' => '2020-01-01 00:00:00', 'LastEdited' => self::NOW, 'ClassName' => 'App\Tag'],
            $plan->rows()['Tag'][0],
        );
        $this->assertSame([['Created' => 'DBDatetime'], null], $plan->conversions()['Tag'][0]);
    }

    public function testAHasOneHoldsTheReferencedIdAndAPolymorphicOneAlsoItsClass(): void
    {
        $plan = $this->plan([
            $this->record('App\Plain', 'first', ['ParentID' => 3, 'Parent' => '=>App\Sub.second', 'Owner' => '=>App\Tag.red']),
            $this->record('App\Sub', 'second'),
            $this->record('App\Tag', 'red'),
        ]);

        $row = $plan->rows()['Plain'][0];
        $this->assertSame([12, 1, 'App\Tag'], [$row['ParentID'], $row['OwnerID'], $row['OwnerClass']]);
        $this->assertArrayNotHasKey('ParentID', $plan->conversions()['Plain'][0][0], 'the has_one replaces the stated column');
    }

    public function testAJoinRowHoldsItsExtraFieldsAndAPairStatedFromBothSidesIsWrittenOnce(): void
    {
        $plan = $this->plan([
            $this->record('App\Plain', 'first', ['Tags' => [['ref' => '=>App\Tag.red', 'Weight' => 2], '=>App\Tag.blue']]),
            $this->record('App\Tag', 'red', ['Plains' => [['ref' => '=>App\Plain.first', 'Weight' => 2]]]),
            $this->record('App\Tag', 'blue'),
        ], ['Plain_Tags' => 5]);

        $this->assertSame(
            [['ID' => 6, 'PlainID' => 11, 'TagID' => 1, 'Weight' => 2], ['ID' => 7, 'PlainID' => 11, 'TagID' => 2]],
            $plan->rows()['Plain_Tags'],
        );
        $this->assertSame([0 => [['Weight' => 'Int'], null]], $plan->conversions()['Plain_Tags']);
    }

    public function testTheLedgerHoldsTheIdRangeOfEveryTableWithItsOwnIds(): void
    {
        $plan = $this->plan([
            $this->record('App\Plain', 'first', ['Tags' => ['=>App\Tag.red']]),
            $this->record('App\Tag', 'red'),
            $this->record('App\Sub', 'second'),
        ], ['Plain_Tags' => 5]);

        $ledger = static fn (string $table, int $first, int $last): array => [
            'ClassName' => FixtureLedger::class,
            'Created' => self::NOW,
            'LastEdited' => self::NOW,
            'TableName' => $table,
            'FirstID' => $first,
            'LastID' => $last,
        ];
        $this->assertSame(
            [$ledger('Plain', 11, 12), $ledger('Tag', 1, 1), $ledger('Plain_Tags', 6, 6)],
            $plan->rows()['FixtureLedger'],
        );
    }

    public function testAPlacedFileIsTheValueOfItsFileField(): void
    {
        $placed = ['Hash' => 'abc', 'Filename' => 'files/logo.png', 'Variant' => null];
        $plan = $this->plan([$this->record('App\File', 'logo', ['Name' => 'logo.png'], source: '/files/logo.png')], placed: ['App\File.logo' => $placed]);

        $this->assertSame($placed, $plan->rows()['File'][0]['File']);
        $this->assertSame('DBFile', $plan->conversions()['File'][0][0]['File']);
    }

    public function testAPublishedRecordWithDraftValuesIsLiveWithItsValuesAndDraftWithTheDraftValuesOverThem(): void
    {
        $plan = $this->plan([$this->record('App\File', 'logo', ['Name' => 'logo.png', 'Title' => 'Logo'], published: true, draft: ['Title' => 'Logo (draft)'])]);

        $this->assertSame('Logo', $plan->rows()['File_Live'][0]['Title']);
        $this->assertSame(['Logo (draft)', 2], [$plan->rows()['File'][0]['Title'], $plan->rows()['File'][0]['Version']]);
    }

    public function testValuesPerLanguageGoToTheLocalisedTables(): void
    {
        $plan = $this->plan([$this->record('App\Tag', 'red', ['Title' => 'Red'], locales: ['nl_NL' => ['Title' => 'Rood']])]);

        $this->assertSame(['RecordID' => 1, 'Locale' => 'nl_NL', 'ID' => 1, 'Title' => 'Rood'], array_diff_key(
            $plan->rows()['Tag_Localised'][0],
            ['ClassName' => true, 'Created' => true, 'LastEdited' => true],
        ));
    }

    public function testAnIdSequenceStaysAboveTheStagesAndVersionsOfItsRecordsAndJoinTablesHaveTheirOwn(): void
    {
        $sources = $this->planner()->idSources([
            $this->record('App\Plain', 'first', ['Tags' => ['=>App\Tag.red'], 'Title' => 'First']),
            $this->record('App\Tag', 'red'),
            $this->record('App\File', 'logo'),
            $this->record('App\Sub', 'second'),
        ]);

        $this->assertSame(
            [
                'Plain' => ['Plain' => 'ID'],
                'Plain_Tags' => ['Plain_Tags' => 'ID'],
                'Tag' => ['Tag' => 'ID'],
                'File' => ['File' => 'ID', 'File_Live' => 'ID', 'File_Versions' => 'RecordID'],
            ],
            $sources,
        );
    }

    public function testASourceFileIsPlacedAtItsFoldersPathPublicWhenPublished(): void
    {
        $records = [
            $this->record('App\File', 'root', ['Name' => 'fixture-files', 'ParentID' => 7]),
            $this->record('App\File', 'logo', ['Name' => 'logo.png', 'Parent' => '=>App\File.root'], published: true, source: '/files/logo.png'),
            $this->record('App\File', 'notes', ['Name' => 'notes.txt', 'ParentID' => '9'], source: '/files/notes.txt'),
            $this->record('App\File', 'readme', ['Name' => 'readme.txt'], source: '/files/readme.txt'),
            $this->record('App\File', 'nameless', source: '/files/x.txt'),
        ];

        $this->assertSame([7, 9], $this->planner()->folderIds($records));
        $this->assertSame(
            [
                'App\File.logo' => ['/files/logo.png', 'site/fixture-files/logo.png', true],
                'App\File.notes' => ['/files/notes.txt', 'notes.txt', false],
                'App\File.readme' => ['/files/readme.txt', 'readme.txt', false],
                'App\File.nameless' => ['/files/x.txt', '', false],
            ],
            $this->planner()->placements($records, [7 => 'site/']),
        );
    }

    public function testAFileOfAClassWithoutStagesIsPublic(): void
    {
        $planner = new RowPlanner(['App\Unstaged' => ClassSchemas::of('App\Unstaged', 'File', fields: ['Name' => 'Varchar'], versioned: true, isFile: true)], 'FixtureLedger');

        $placements = $planner->placements([$this->record('App\Unstaged', 'notes', ['Name' => 'notes.txt'], source: '/files/notes.txt')], []);

        $this->assertTrue($placements['App\Unstaged.notes'][2]);
    }

    public function testARecordOfAClassThatWasNotValidatedIsRefused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('a.yml, Unknown\Kind.thing was not validated');

        $this->planner()->idSources([$this->record('Unknown\Kind', 'thing')]);
    }

    /**
     * @param list<FixtureRecord> $records
     * @param array<string, int> $floors
     * @param array<string, mixed> $placed
     */
    private function plan(array $records, array $floors = [], array $placed = []): Plan
    {
        return $this->planner()->plan($records, [...['Plain' => 10, 'Tag' => 0, 'File' => 0], ...$floors], self::NOW, $placed);
    }

    /**
     * The rows of the record tables, without the ledger.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function recordRows(Plan $plan): array
    {
        return array_diff_key($plan->rows(), ['FixtureLedger' => true]);
    }

    private function planner(): RowPlanner
    {
        return new RowPlanner($this->schemas(), 'FixtureLedger');
    }

    /**
     * @return array<string, ClassSchema|null>
     */
    private function schemas(): array
    {
        $plain = [
            'fields' => ['Title' => 'Varchar', 'Body' => 'Text', 'ParentID' => 'ForeignKey', 'Price' => 'Money', 'OwnerID' => 'ForeignKey', 'OwnerClass' => 'DBClassName'],
            'kinds' => ['Parent' => FieldKind::HasOne, 'Owner' => FieldKind::HasOne, 'Tags' => FieldKind::ManyMany],
            'joins' => ['Tags' => ['join' => 'Plain_Tags', 'parentField' => 'PlainID', 'childField' => 'TagID', 'extraFields' => ['Weight' => 'Int']]],
            'defaults' => ['Body' => 'Default'],
        ];

        return [
            'Unknown\Kind' => null,
            'App\Plain' => ClassSchemas::of('App\Plain', 'Plain', ...$plain),
            'App\Sub' => ClassSchemas::of(
                'App\Sub',
                'Plain',
                ...[...$plain, 'fields' => [...$plain['fields'], 'SubTitle' => 'Varchar']],
                fieldTables: ['SubTitle' => 'Sub'],
                tables: ['Sub'],
            ),
            'App\Tag' => ClassSchemas::of(
                'App\Tag',
                'Tag',
                fields: ['Title' => 'Varchar'],
                kinds: ['Plains' => FieldKind::ManyMany],
                joins: ['Plains' => ['join' => 'Plain_Tags', 'parentField' => 'TagID', 'childField' => 'PlainID', 'extraFields' => ['Weight' => 'Int']]],
                localisedTables: ['Tag' => ['Title']],
            ),
            'App\File' => ClassSchemas::of(
                'App\File',
                'File',
                fields: ['Name' => 'Varchar', 'Title' => 'Varchar', 'ParentID' => 'ForeignKey', 'File' => 'DBFile'],
                kinds: ['Parent' => FieldKind::HasOne],
                versioned: true,
                staged: true,
                isFile: true,
            ),
        ];
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, array<string, mixed>> $locales
     * @param array<string, mixed> $draft
     */
    private function record(
        string $class,
        string $identifier,
        array $fields = [],
        ?bool $published = null,
        array $locales = [],
        ?string $source = null,
        array $draft = [],
    ): FixtureRecord {
        return new FixtureRecord('a.yml', $class, $identifier, $fields, $published, $locales, $source, $draft);
    }
}
