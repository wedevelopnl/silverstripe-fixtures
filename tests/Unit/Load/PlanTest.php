<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Tests\Unit\Load;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WeDevelop\Fixtures\Load\Plan;

#[CoversClass(Plan::class)]
final class PlanTest extends TestCase
{
    public function testIdsAreClaimedInSequenceAboveEachTablesFloor(): void
    {
        $plan = new Plan(['Page' => 40, 'Tag' => 0]);

        $this->assertSame(41, $plan->claim('Page'));
        $this->assertSame(1, $plan->claim('Tag'));
        $this->assertSame(42, $plan->claim('Page'));
        $this->assertSame(['Page' => [41, 42], 'Tag' => [1, 1]], $plan->ranges());
    }

    public function testAnAssignedRecordAnswersItsIdAndClass(): void
    {
        $plan = new Plan([]);
        $plan->assign('Page.home', 'App\Page', 7);
        $plan->assign('Sub.about', 'App\Sub', 8);
        $plan->assign('Page.contact', 'App\Page', 9);

        $this->assertSame(8, $plan->idOf('Sub.about'));
        $this->assertSame('App\Page', $plan->classOf('Page.home'));
        $this->assertSame(['App\Page', 'App\Sub'], $plan->classes());
    }

    public function testAJoinPairIsClaimedOnceWhicheverSideStatesIt(): void
    {
        $plan = new Plan([]);

        $this->assertTrue($plan->claimPair('Page_Tags', ['PageID' => 1, 'TagID' => 2]));
        $this->assertFalse($plan->claimPair('Page_Tags', ['TagID' => 2, 'PageID' => 1]));
        $this->assertTrue($plan->claimPair('Page_Links', ['PageID' => 1, 'TagID' => 2]), 'another join table');
    }

    public function testRowsAreKeptPerTableInTheOrderAddedWithWhatTheirRawValuesConvertBy(): void
    {
        $plan = new Plan([]);
        $plan->add('Page', ['ID' => 1, 'Title' => 'Home'], ['Title' => 'Varchar']);
        $plan->add('Tag', ['ID' => 2]);
        $plan->add('Page', ['ID' => 3]);
        $plan->add('Page_Localised', ['RecordID' => 1, 'Title' => 'Thuis'], [], ['RecordID' => true]);

        $this->assertSame(
            ['Page' => [['ID' => 1, 'Title' => 'Home'], ['ID' => 3]], 'Tag' => [['ID' => 2]], 'Page_Localised' => [['RecordID' => 1, 'Title' => 'Thuis']]],
            $plan->rows(),
        );
        $this->assertSame(
            ['Page' => [0 => [['Title' => 'Varchar'], null]], 'Page_Localised' => [0 => [[], ['RecordID' => true]]]],
            $plan->conversions(),
        );
    }
}
