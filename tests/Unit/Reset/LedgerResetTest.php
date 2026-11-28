<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Tests\Unit\Reset;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WeDevelop\Fixtures\Reset\LedgerReset;

#[CoversClass(LedgerReset::class)]
final class LedgerResetTest extends TestCase
{
    private const array TABLES = [
        'Page',
        'Page_Live',
        'Page_Versions',
        'Page_Localised',
        'Sub',
        'Sub_Live',
        'Sub_Versions',
        'Other',
        'Tag',
        'Page_Tags',
        'Link',
    ];

    public function testALedgerEntryOfABaseTableSelectsRecordsAndOfAnyOtherTableItsRows(): void
    {
        $reset = $this->reset();
        $reset->addLedgerEntry('Page', true, 1, 5);
        $reset->addLedgerEntry('Page', true, 9, 9);
        $reset->addLedgerEntry('Page_Tags', false, 3, 4);
        $reset->addIds('Tag', []);

        $this->assertSame(
            ['Page' => [['SELECT DISTINCT "ClassName" FROM "Page" WHERE ("ID" BETWEEN ? AND ? OR "ID" BETWEEN ? AND ?)', [1, 5, 9, 9]], ['SELECT DISTINCT "ClassName" FROM "Page_Live" WHERE ("ID" BETWEEN ? AND ? OR "ID" BETWEEN ? AND ?)', [1, 5, 9, 9]], ['SELECT DISTINCT "ClassName" FROM "Page_Versions" WHERE ("RecordID" BETWEEN ? AND ? OR "RecordID" BETWEEN ? AND ?)', [1, 5, 9, 9]]]],
            $reset->classNameReads(),
        );
        $this->assertSame(
            [['Page_Tags', ['("ID" BETWEEN ? AND ?)' => [3, 4]]]],
            array_values(array_filter($reset->deletes([], [], []), static fn (array $delete): bool => $delete[0] === 'Page_Tags')),
        );
    }

    public function testAResetClassSelectsItsRecordsAndThoseOfItsSubclassesByClassName(): void
    {
        $this->assertSame(
            ['"ClassName" IN (?, ?)' => ['App\Page', 'App\Sub']],
            LedgerReset::classCondition(['App\Page', 'App\Sub']),
        );
    }

    public function testRecordsAreFoundInTheExistingStageAndVersionTablesOfTheirBaseTable(): void
    {
        $this->assertSame(['Page' => 'ID', 'Page_Live' => 'ID', 'Page_Versions' => 'RecordID'], $this->reset()->recordTables('Page'));
        $this->assertSame(['Tag' => 'ID'], $this->reset()->recordTables('Tag'));
    }

    public function testAJoinRowGoesWhenARecordOnEitherSideGoes(): void
    {
        $reset = $this->reset();
        $join = ['join' => 'Page_Tags', 'parentBase' => 'Page', 'childBase' => 'Tag', 'parentField' => 'PageID', 'childField' => 'TagID'];
        $this->assertNull($reset->references($join), 'nothing is deleted yet');

        $reset->addIds('Tag', [4, 6]);
        $this->assertSame(['("TagID" IN (?, ?))' => [4, 6]], $reset->references($join));

        $reset->addLedgerEntry('Page', true, 1, 2);
        $this->assertSame(['("PageID" BETWEEN ? AND ?) OR ("TagID" IN (?, ?))' => [1, 2, 4, 6]], $reset->references($join));
        $this->assertNull($reset->references([...$join, 'parentBase' => null, 'childBase' => 'Other']), 'a polymorphic side names no class');
    }

    public function testDeletesClearEachFamilyFromTheTablesOfItsClassesThenTheLedgerRowsThenTheJoinRows(): void
    {
        $reset = $this->reset();
        $reset->addLedgerEntry('Page', true, 1, 2);
        $reset->addLedgerEntry('Page_Tags', false, 7, 7);
        $reset->addIds('Tag', [4]);
        $reset->addIds('Other', []);
        $joins = [
            ['join' => 'Page_Tags', 'parentBase' => 'Page', 'childBase' => 'Tag', 'parentField' => 'PageID', 'childField' => 'TagID'],
            ['join' => 'Gone_Join', 'parentBase' => 'Page', 'childBase' => 'Tag', 'parentField' => 'PageID', 'childField' => 'TagID'],
            ['join' => 'Link', 'parentBase' => 'Other', 'childBase' => null, 'parentField' => 'OtherID', 'childField' => 'ToID'],
        ];

        $deletes = $reset->deletes(
            ['Page' => ['App\Sub'], 'Tag' => ['App\Tag']],
            ['App\Sub' => ['Page', 'Sub'], 'App\Tag' => ['Tag']],
            $joins,
        );

        $page = ['("ID" BETWEEN ? AND ?)' => [1, 2]];
        $pageVersions = ['("RecordID" BETWEEN ? AND ?)' => [1, 2]];
        $this->assertSame(
            [
                ['Page', $page],
                ['Page_Live', $page],
                ['Page_Versions', $pageVersions],
                ['Page_Localised', $pageVersions],
                ['Sub', $page],
                ['Sub_Live', $page],
                ['Sub_Versions', $pageVersions],
                ['Tag', ['("ID" IN (?))' => [4]]],
                ['Page_Tags', ['("ID" BETWEEN ? AND ?)' => [7, 7]]],
                ['Page_Tags', ['("PageID" BETWEEN ? AND ?) OR ("TagID" IN (?))' => [1, 2, 4]]],
            ],
            $deletes,
        );
    }

    public function testAClassGoneFromTheCodeBaseClearsOnlyItsBaseTable(): void
    {
        $reset = $this->reset();
        $reset->addLedgerEntry('Page', true, 1, 1);

        $deletes = $reset->deletes(['Page' => ['App\Removed']], [], []);

        $this->assertSame(['Page', 'Page_Live', 'Page_Versions', 'Page_Localised'], array_column($deletes, 0));
    }

    private function reset(): LedgerReset
    {
        return new LedgerReset(array_combine(array_map(strtolower(...), self::TABLES), self::TABLES));
    }
}
