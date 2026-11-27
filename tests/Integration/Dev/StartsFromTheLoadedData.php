<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Tests\Integration\Dev;

use PHPUnit\Framework\Attributes\DataProvider;
use WeDevelop\Fixtures\FixtureLedger;
use WeDevelop\Fixtures\Tests\Integration\Model\Tag;

/**
 * Changes the loaded data in two runs of one test: whichever order PHPUnit picks, one run follows the other. It
 * fails unless every test starts from the fixture as the package's loader wrote it: the ledger entries only that
 * loader leaves, and the records without what an earlier test changed. Loading again on every test would double
 * both.
 */
trait StartsFromTheLoadedData
{
    /**
     * @return iterable<string, array{}>
     */
    public static function twoRuns(): iterable
    {
        yield 'first run' => [];
        yield 'second run' => [];
    }

    #[DataProvider('twoRuns')]
    public function testEveryTestStartsFromTheLoadedData(): void
    {
        $this->assertSame(3, FixtureLedger::get()->count(), 'one load by the package, with entries for the Tag, Plain and join tables');
        $this->assertSame(['Red', 'Blue'], Tag::get()->sort('ID')->column('Title'), 'what an earlier test changed is gone');

        $this->objFromFixture(Tag::class, 'red')->delete();
        Tag::create(['Title' => 'Created'])->write();

        $this->assertSame(['Blue', 'Created'], Tag::get()->sort('ID')->column('Title'));
    }
}
