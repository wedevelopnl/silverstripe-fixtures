<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Tests\Integration\Dev;

use PHPUnit\Framework\Attributes\DataProvider;
use WeDevelop\Fixtures\Tests\Integration\Model\Tag;

/**
 * Changes the loaded data in two runs of one test: whichever order PHPUnit picks, one run follows the other, so
 * it fails unless the test state restores the data between tests.
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
        $this->assertSame(['Red', 'Blue'], Tag::get()->sort('ID')->column('Title'), 'what an earlier test changed is gone');

        $this->objFromFixture(Tag::class, 'red')->delete();
        Tag::create(['Title' => 'Created'])->write();

        $this->assertSame(['Blue', 'Created'], Tag::get()->sort('ID')->column('Title'));
    }
}
