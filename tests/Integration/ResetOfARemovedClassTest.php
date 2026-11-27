<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DB;
use WeDevelop\Fixtures\FixtureLedger;
use WeDevelop\Fixtures\FixtureLoader;
use WeDevelop\Fixtures\Reset\DatabaseReset;
use WeDevelop\Fixtures\Tests\Integration\Model\Tag;

#[CoversClass(DatabaseReset::class)]
final class ResetOfARemovedClassTest extends SapphireTest
{
    private const string FIXTURES = __DIR__ . '/fixtures/';

    protected $usesDatabase = true;

    /**
     * The test alters a column, which would end a test transaction; the framework drops the database instead.
     */
    protected $usesTransactions = false;

    protected static $extra_dataobjects = [
        Tag::class,
    ];

    public function testResetRemovesALoadedRecordWhoseClassIsGoneFromTheCodeBase(): void
    {
        $loaded = FixtureLoader::singleton()->load(self::FIXTURES . 'tags.yml');
        $removed = 'WeDevelop\Fixtures\Tests\Integration\Model\RemovedTag';
        $connection = DB::get_conn();
        // As dev/build leaves the column once a class is deleted: its name stays an option while rows hold it
        DB::query(sprintf(
            'ALTER TABLE "FixturesTest_Tag" MODIFY "ClassName" ENUM(%s, %s)',
            $connection->quoteString(Tag::class),
            $connection->quoteString($removed),
        ));
        $red = $loaded->idOf(Tag::class, 'red');
        DB::prepared_query('UPDATE "FixturesTest_Tag" SET "ClassName" = ? WHERE "ID" = ?', [$removed, $red]);
        $this->assertSame($removed, DB::prepared_query('SELECT "ClassName" FROM "FixturesTest_Tag" WHERE "ID" = ?', [$red])->value());

        FixtureLoader::singleton()->reset();

        $this->assertSame(0, (int) DB::query('SELECT COUNT(*) FROM "FixturesTest_Tag"')->value());
        $this->assertSame(0, FixtureLedger::get()->count());
    }
}
