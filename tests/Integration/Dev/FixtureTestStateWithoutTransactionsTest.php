<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Tests\Integration\Dev;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Dev\SapphireTest;
use WeDevelop\Fixtures\Dev\FixtureTestState;
use WeDevelop\Fixtures\Tests\Integration\Model\Plain;
use WeDevelop\Fixtures\Tests\Integration\Model\PlainLink;
use WeDevelop\Fixtures\Tests\Integration\Model\Tag;

/**
 * Without a test transaction the framework drops the temporary database after every test, and the next test
 * builds a new one and loads the fixture again. The trait checks that the package's loader did that load.
 */
#[CoversClass(FixtureTestState::class)]
final class FixtureTestStateWithoutTransactionsTest extends SapphireTest
{
    use StartsFromTheLoadedData;

    protected static $fixture_file = 'FixtureTestStateTest.yml';

    protected $usesTransactions = false;

    protected static $extra_dataobjects = [
        Plain::class,
        Tag::class,
        PlainLink::class,
    ];
}
