<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Tests\Unit\Reset;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WeDevelop\Fixtures\Reset\Condition;

#[CoversClass(Condition::class)]
final class ConditionTest extends TestCase
{
    public function testANewConditionSelectsNothing(): void
    {
        $this->assertTrue((new Condition())->isEmpty());
    }

    public function testRangesAndIdsCombineIntoOneClauseWithEachIdOnce(): void
    {
        $condition = new Condition();
        $condition->addRange(1, 5);
        $condition->addIds([9, 12]);
        $condition->addRange(20, 21);
        $condition->addIds([12, 15]);

        $this->assertFalse($condition->isEmpty());
        $this->assertSame(
            ['("ID" BETWEEN ? AND ? OR "ID" BETWEEN ? AND ? OR "ID" IN (?, ?, ?))', [1, 5, 20, 21, 9, 12, 15]],
            $condition->toSql('ID'),
        );
    }

    public function testIdsAloneSelectByTheGivenColumn(): void
    {
        $condition = new Condition();
        $condition->addIds([3]);

        $this->assertSame(['("RecordID" IN (?))', [3]], $condition->toSql('RecordID'));
    }
}
