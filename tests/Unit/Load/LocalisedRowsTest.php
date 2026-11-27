<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Tests\Unit\Load;

use PHPUnit\Framework\Attributes\CoversClass;
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
                    ['RecordID' => 3, 'Locale' => 'nl_NL', 'ID' => 3, 'Title' => 'Thuis', 'Sort' => 2, 'Price' => ['Amount' => 5]],
                    ['RecordID' => 3, 'Locale' => 'de_DE', 'ID' => 3, 'Title' => 'Home', 'Sort' => 2, 'Price' => ['Amount' => 5]],
                ],
                'Page_Sub_Localised' => [['RecordID' => 3, 'Locale' => 'nl_NL', 'ID' => 3], ['RecordID' => 3, 'Locale' => 'de_DE', 'ID' => 3]],
            ],
            $plan->rows(),
        );
        $this->assertSame(['Page_Localised' => [$page, $page], 'Page_Sub_Localised' => [$sub, $sub]], $plan->conversions());
    }

    public function testLocalisedStagesHaveAVersionAndALiveRowWhenPublished(): void
    {
        $schema = ClassSchemas::of('App\Page', 'Page', fields: ['Title' => 'Varchar'], localisedTables: ['Page' => ['Title']], localisedStages: true);
        $columns = ['RecordID' => true, 'Locale' => true, 'Title' => true];
        $rows = [];
        foreach ([true, false] as $published) {
            $plan = new Plan([]);
            $plan->assign('App\Page.home', 'App\Page', 3);
            $record = new FixtureRecord('a.yml', 'App\Page', 'home', [], $published, ['nl_NL' => ['Title' => 'Thuis']], null, []);

            LocalisedRows::add($plan, $schema, $record, ['Page' => [['ID' => 3], []]]);
            $rows[] = $plan->rows();
            $conversions = $plan->conversions();
        }

        $row = ['RecordID' => 3, 'Locale' => 'nl_NL', 'ID' => 3, 'Title' => 'Thuis'];
        $this->assertSame(
            ['Page_Localised' => [$row], 'Page_Localised_Versions' => [[...$row, 'Version' => 1]], 'Page_Localised_Live' => [$row]],
            $rows[0],
        );
        $this->assertSame(['Page_Localised' => [$row], 'Page_Localised_Versions' => [[...$row, 'Version' => 1]]], $rows[1]);
        $this->assertSame([0 => [['Title' => 'Varchar'], [...$columns, 'Version' => true]]], $conversions['Page_Localised_Versions'] ?? null);
    }
}
