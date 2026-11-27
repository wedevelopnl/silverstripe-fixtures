<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Tests\Integration;

use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\Connect\MySQLDatabase;
use SilverStripe\ORM\DB;
use WeDevelop\Fixtures\FixtureLoader;
use WeDevelop\Fixtures\Tests\Integration\Model\Tag;

/**
 * A server without MySQL 8's information_schema_stats_expiry, such as MariaDB: the harness has none, so the
 * connection answers the question for that variable as such a server would, with no row. Without a test
 * transaction, since the stand-in connection does not know of one and would end it.
 */
final class FixtureLoaderWithoutStatisticsExpiryTest extends SapphireTest
{
    private const string FIXTURES = 'wedevelopnl/silverstripe-fixtures:tests/Integration/fixtures/';

    protected $usesDatabase = true;

    protected $usesTransactions = false;

    protected static $extra_dataobjects = [
        Tag::class,
    ];

    public function testItLoadsAboveTheExistingIdsWithoutTouchingTheVariable(): void
    {
        $existing = Tag::create();
        $existing->ID = 500;
        $existing->Title = 'Existing';
        $existing->write(false, true);

        $connection = DB::get_conn(DB::CONN_PRIMARY);
        $this->assertInstanceOf(MySQLDatabase::class, $connection);
        $withoutVariable = new class () extends MySQLDatabase {
            /**
             * @var list<string>
             */
            public array $sent = [];

            public function query($sql, $errorLevel = E_USER_ERROR)
            {
                $this->sent[] = $sql;

                return parent::query(str_replace('information_schema_stats_expiry', 'no_such_variable', $sql), $errorLevel);
            }
        };
        $withoutVariable->setConnector($connection->getConnector());
        $withoutVariable->setQueryBuilder($connection->getQueryBuilder());
        $schemaManager = $connection->getSchemaManager();
        $withoutVariable->setSchemaManager($schemaManager);

        DB::set_conn($withoutVariable, DB::CONN_PRIMARY);
        try {
            $loaded = FixtureLoader::singleton()->load(self::FIXTURES . 'tags.yml');
        } finally {
            DB::set_conn($connection, DB::CONN_PRIMARY);
            $connection->setSchemaManager($schemaManager);
        }

        $this->assertContains("SHOW VARIABLES LIKE 'information_schema_stats_expiry'", $withoutVariable->sent, 'the fallback was taken');
        $this->assertSame([], array_values(array_filter($withoutVariable->sent, static fn (string $sql): bool => str_starts_with($sql, 'SET SESSION'))));
        $this->assertGreaterThan(500, $loaded->idOf(Tag::class, 'red'));
        $this->assertSame(['Existing', 'Red', 'Blue'], Tag::get()->sort('ID')->column('Title'));
    }
}
