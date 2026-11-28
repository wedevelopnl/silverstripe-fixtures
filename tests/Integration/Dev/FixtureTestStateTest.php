<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Tests\Integration\Dev;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DB;
use WeDevelop\Fixtures\Dev\FixtureTestState;
use WeDevelop\Fixtures\FixtureLedger;
use WeDevelop\Fixtures\Tests\Integration\Model\Plain;
use WeDevelop\Fixtures\Tests\Integration\Model\PlainLink;
use WeDevelop\Fixtures\Tests\Integration\Model\Tag;

/**
 * A test class written for the framework's fixture handling, run with the package's state configured for the
 * project (the harness's app/_config/fixtures.yml). The last test and the trait's look behind the scenes, at
 * the state in use and the ledger only the package's loader writes; with transactions on, the trait's test also
 * fails when the state loads the fixture again for every test.
 */
#[CoversClass(FixtureTestState::class)]
final class FixtureTestStateTest extends SapphireTest
{
    use StartsFromTheLoadedData;

    protected static $fixture_file = 'FixtureTestStateTest.yml';

    protected static $extra_dataobjects = [
        Plain::class,
        Tag::class,
        PlainLink::class,
    ];

    public function testRecordsAreFoundByTheirFixtureNames(): void
    {
        $first = $this->objFromFixture(Plain::class, 'first');

        $this->assertSame('First', $first->Title);
        $this->assertSame($this->idFromFixture(Plain::class, 'first'), $first->ID);
        $this->assertSame(['Red'], $first->Tags()->column('Title'));
    }

    public function testAllIdsOfAClassAreListedByFixtureName(): void
    {
        $this->assertSame(['red', 'blue'], array_keys($this->allFixtureIDs(Tag::class)));
    }

    public function testTheFixtureWentThroughTheLoader(): void
    {
        $this->assertInstanceOf(FixtureTestState::class, static::$state->getStateByName('fixtures'));
        $this->assertSame(3, FixtureLedger::get()->count(), 'one ledger entry each for the Tag, Plain and join tables');
        $this->assertSame(1, (int) DB::query('SELECT COUNT(*) FROM "FixturesTest_Plain_Tags"')->value());
    }
}
