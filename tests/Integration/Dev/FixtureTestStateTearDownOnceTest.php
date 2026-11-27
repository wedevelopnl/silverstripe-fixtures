<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Tests\Integration\Dev;

use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\Connect\TempDatabase;
use WeDevelop\Fixtures\Dev\FixtureTestState;
use WeDevelop\Fixtures\FixtureLedger;
use WeDevelop\Fixtures\FixtureLoader;
use WeDevelop\Fixtures\Tests\Integration\Model\Tag;

final class FixtureTestStateTearDownOnceTest extends SapphireTest
{
    private const string FIXTURES = 'wedevelopnl/silverstripe-fixtures:tests/Integration/fixtures/';

    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        Tag::class,
    ];

    public function testItResetsWhatTheClassLoadedIntoTheTemporaryDatabase(): void
    {
        FixtureLoader::singleton()->load(self::FIXTURES . 'tags.yml');

        (new FixtureTestState())->tearDownOnce(self::class);

        $this->assertSame(0, Tag::get()->count());
        $this->assertSame(0, FixtureLedger::get()->count());
    }

    public function testItLeavesADatabaseThatIsNotTheTemporaryOneAlone(): void
    {
        FixtureLoader::singleton()->load(self::FIXTURES . 'tags.yml');
        $tempDB = self::$tempDB;
        self::$tempDB = new class () extends TempDatabase {
            public function isUsed(): bool
            {
                return false;
            }
        };

        try {
            (new FixtureTestState())->tearDownOnce(self::class);
        } finally {
            self::$tempDB = $tempDB;
        }

        $this->assertSame(2, Tag::get()->count());
        $this->assertSame(1, FixtureLedger::get()->count());
    }
}
