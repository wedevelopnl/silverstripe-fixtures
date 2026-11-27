<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Tests\Unit\Load;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WeDevelop\Fixtures\Load\FixtureRecord;
use WeDevelop\Fixtures\Load\LocalisedRows;
use WeDevelop\Fixtures\Load\Plan;
use WeDevelop\Fixtures\Tests\Unit\ClassSchemas;

#[CoversClass(LocalisedRows::class)]
final class LocalisedRowsTest extends TestCase
{
    public function testEachLocaleHasARowInEveryLocalisedTableThatKeepsOnlyTheTranslatedColumns(): void
    {
        $schema = ClassSchemas::of(
            'App\Sub',
            'Page',
            fields: ['Title' => 'Varchar', 'Sort' => 'Int', 'Price' => 'Money', 'Note' => 'Text'],
            fieldTables: ['Note' => 'Page_Sub'],
            tables: ['Page_Sub'],
            localisedTables: ['Page' => ['Title', 'PriceAmount'], 'Page_Sub' => ['Note']],
        );
        $plan = new Plan([]);
        $plan->assign('App\Sub.home', 'App\Sub', 3);
        $record = new FixtureRecord('a.yml', 'App\Sub', 'home', [], null, ['nl_NL' => ['Title' => 'Thuis'], 'de_DE' => []], null, []);

        LocalisedRows::add($plan, $schema, $record, [
            'Page' => [['ID' => 3, 'Title' => 'Home', 'Sort' => 2, 'Price' => ['Amount' => 5]], ['Title' => 'Varchar', 'Sort' => 'Int', 'Price' => 'Money']],
            'Page_Sub' => [['ID' => 3], []],
        ]);

        $page = [['Title' => 'Varchar', 'Sort' => 'Int', 'Price' => 'Money'], ['RecordID' => true, 'Locale' => true, 'Title' => true, 'PriceAmount' => true]];
        $sub = [[], ['RecordID' => true, 'Locale' => true, 'Note' => true]];
        $this->assertSame(
            [
                'Page_Localised' => [
                    ['RecordID' => 3, 'Locale' => 'nl_NL', 'ID' => 3, 'Sort' => 2, 'Price' => ['Amount' => 5], 'Title' => 'Thuis'],
                    ['RecordID' => 3, 'Locale' => 'de_DE', 'ID' => 3, 'Title' => 'Home', 'Sort' => 2, 'Price' => ['Amount' => 5]],
                ],
                'Page_Sub_Localised' => [['RecordID' => 3, 'Locale' => 'nl_NL', 'ID' => 3], ['RecordID' => 3, 'Locale' => 'de_DE', 'ID' => 3]],
            ],
            $plan->rows(),
        );
        $this->assertSame(['Page_Localised' => [$page, $page], 'Page_Sub_Localised' => [$sub, $sub]], $plan->conversions());
    }

    public function testACompositeColumnStatedPerLocaleIsKeptOverTheRecordsCompositeAndConvertedByItsOwnSpec(): void
    {
        $schema = ClassSchemas::of(
            'App\Page',
            'Page',
            fields: ['Price' => 'Money', 'PriceAmount' => 'Decimal', 'PriceCurrency' => 'Varchar'],
            localisedTables: ['Page' => ['PriceAmount']],
        );
        $plan = new Plan([]);
        $plan->assign('App\Page.home', 'App\Page', 3);
        $record = new FixtureRecord('a.yml', 'App\Page', 'home', [], null, ['nl_NL' => ['PriceAmount' => 7]], null, []);

        LocalisedRows::add($plan, $schema, $record, ['Page' => [['ID' => 3, 'Price' => ['Amount' => 5, 'Currency' => 'EUR']], ['Price' => 'Money']]]);

        $this->assertSame(
            ['RecordID' => 3, 'Locale' => 'nl_NL', 'ID' => 3, 'Price' => ['Amount' => 5, 'Currency' => 'EUR'], 'PriceAmount' => 7],
            $plan->rows()['Page_Localised'][0],
        );
        $this->assertSame(
            [['Price' => 'Money', 'PriceAmount' => 'Decimal'], ['RecordID' => true, 'Locale' => true, 'PriceAmount' => true]],
            $plan->conversions()['Page_Localised'][0],
        );
    }

    public function testALocalesValueOfAColumnComesAfterTheRecordsCompositeThoughTheRowHeldTheColumnBeforeIt(): void
    {
        $schema = ClassSchemas::of(
            'App\Page',
            'Page',
            fields: ['Price' => 'Money', 'PriceAmount' => 'Decimal', 'PriceCurrency' => 'Varchar'],
            localisedTables: ['Page' => ['PriceCurrency']],
        );
        $plan = new Plan([]);
        $plan->assign('App\Page.home', 'App\Page', 3);
        $record = new FixtureRecord('a.yml', 'App\Page', 'home', [], null, ['nl_NL' => ['PriceCurrency' => 'GBP']], null, []);

        LocalisedRows::add($plan, $schema, $record, ['Page' => [
            ['ID' => 3, 'PriceCurrency' => 'EUR', 'Price' => ['Amount' => 5, 'Currency' => 'USD']],
            ['PriceCurrency' => 'Varchar', 'Price' => 'Money'],
        ]]);

        $this->assertSame(
            ['RecordID' => 3, 'Locale' => 'nl_NL', 'ID' => 3, 'Price' => ['Amount' => 5, 'Currency' => 'USD'], 'PriceCurrency' => 'GBP'],
            $plan->rows()['Page_Localised'][0],
        );
    }

    /**
     * @return iterable<string, array{bool, array<string, list<array<string, mixed>>>, array<string, mixed>}>
     */
    public static function publication(): iterable
    {
        $row = ['RecordID' => 3, 'Locale' => 'nl_NL', 'ID' => 3, 'Title' => 'Thuis'];
        $columns = ['RecordID' => true, 'Locale' => true, 'Title' => true];
        $stage = [0 => [['Title' => 'Varchar'], $columns]];
        $version = [0 => [['Title' => 'Varchar'], [...$columns, 'Version' => true]]];
        yield 'published' => [
            true,
            ['Page_Localised' => [$row], 'Page_Localised_Versions' => [[...$row, 'Version' => 1]], 'Page_Localised_Live' => [$row]],
            ['Page_Localised' => $stage, 'Page_Localised_Versions' => $version, 'Page_Localised_Live' => $stage],
        ];
        yield 'unpublished' => [
            false,
            ['Page_Localised' => [$row], 'Page_Localised_Versions' => [[...$row, 'Version' => 1]]],
            ['Page_Localised' => $stage, 'Page_Localised_Versions' => $version],
        ];
    }

    /**
     * @param array<string, list<array<string, mixed>>> $rows
     * @param array<string, mixed> $conversions
     */
    #[DataProvider('publication')]
    public function testLocalisedStagesHaveAVersionAndALiveRowWhenPublished(bool $published, array $rows, array $conversions): void
    {
        $schema = ClassSchemas::of('App\Page', 'Page', fields: ['Title' => 'Varchar'], localisedTables: ['Page' => ['Title']], localisedStages: true);
        $plan = new Plan([]);
        $plan->assign('App\Page.home', 'App\Page', 3);
        $record = new FixtureRecord('a.yml', 'App\Page', 'home', [], $published, ['nl_NL' => ['Title' => 'Thuis']], null, []);

        LocalisedRows::add($plan, $schema, $record, ['Page' => [['ID' => 3], []]]);

        $this->assertSame([$rows, $conversions], [$plan->rows(), $plan->conversions()]);
    }
}
