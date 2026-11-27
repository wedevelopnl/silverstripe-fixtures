<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Tests\Integration;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\Connect\DatabaseException;
use SilverStripe\ORM\Connect\MySQLDatabase;
use SilverStripe\ORM\DB;
use WeDevelop\Fixtures\FixtureLoader;
use WeDevelop\Fixtures\Load\LoadPlanner;
use WeDevelop\Fixtures\Tests\Integration\Model\Tag;

/**
 * MySQL 8's information_schema_stats_expiry, which the ID floors are read with at 0 and then given back, and a
 * server without it, such as MariaDB. Each load goes through a stand-in for the connection that records every
 * statement and may rewrite it. Without a test transaction, since the stand-in does not know of one and would end it.
 */
#[CoversClass(LoadPlanner::class)]
final class FixtureLoaderStatisticsExpiryTest extends SapphireTest
{
    private const string FIXTURES = __DIR__ . '/fixtures/';

    private const string VARIABLE = "SHOW VARIABLES LIKE 'information_schema_stats_expiry'";

    protected $usesDatabase = true;

    protected $usesTransactions = false;

    protected static $extra_dataobjects = [
        Tag::class,
    ];

    private string $expiry = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->expiry = $this->sessionExpiry();
        DB::query('SET SESSION information_schema_stats_expiry = 1234');
    }

    protected function tearDown(): void
    {
        DB::query('SET SESSION information_schema_stats_expiry = ' . (int) $this->expiry);
        parent::tearDown();
    }

    public function testTheFloorsAreReadWithoutTheStatisticsCacheAndTheSessionGetsItsExpiryBack(): void
    {
        $sent = $this->loadThrough(static fn (string $sql): string => $sql);

        $this->assertSame(
            [self::VARIABLE, 'SET SESSION information_schema_stats_expiry = 0', 'the floors', 'SET SESSION information_schema_stats_expiry = 1234'],
            $this->expiryStatements($sent),
        );
        $this->assertSame('1234', $this->sessionExpiry());
        $this->assertSame(['Red', 'Blue'], Tag::get()->sort('ID')->column('Title'));
    }

    public function testTheSessionGetsItsExpiryBackWhenReadingTheFloorsFails(): void
    {
        try {
            $this->loadThrough(static fn (string $sql): string => self::isFloors($sql) ? 'SELECT "NoSuchColumn"' : $sql);
            $this->fail('The failing floors statement must fail the load');
        } catch (DatabaseException $exception) {
            $this->assertStringContainsString('NoSuchColumn', $exception->getMessage(), 'the error propagates unchanged');
        }

        $this->assertSame('1234', $this->sessionExpiry());
        $this->assertSame(0, Tag::get()->count());
    }

    public function testItLoadsAboveTheExistingIdsWithoutTouchingTheVariable(): void
    {
        $existing = Tag::create();
        $existing->ID = 500;
        $existing->Title = 'Existing';
        $existing->write(false, true);

        // The server answers the question for the variable with no row, as one without it would
        $sent = $this->loadThrough(static fn (string $sql): string => str_replace('information_schema_stats_expiry', 'no_such_variable', $sql));

        $this->assertSame([self::VARIABLE, 'the floors'], $this->expiryStatements($sent), 'the fallback was taken');
        $this->assertSame(['Existing', 'Red', 'Blue'], Tag::get()->sort('ID')->column('Title'));
        $this->assertGreaterThan(500, Tag::get()->filter('Title', 'Red')->first()?->ID);
    }

    /**
     * @param Closure(string): string $rewrite
     *
     * @return list<string> every statement sent, as the loader wrote it
     */
    private function loadThrough(Closure $rewrite): array
    {
        $connection = DB::get_conn(DB::CONN_PRIMARY);
        $this->assertInstanceOf(MySQLDatabase::class, $connection);
        $standIn = new class ($rewrite) extends MySQLDatabase {
            /**
             * @var list<string>
             */
            public array $sent = [];

            /**
             * @param Closure(string): string $rewrite
             */
            public function __construct(private readonly Closure $rewrite)
            {
            }

            public function query($sql, $errorLevel = E_USER_ERROR)
            {
                $this->sent[] = $sql;

                return parent::query(($this->rewrite)($sql), $errorLevel);
            }

            public function preparedQuery($sql, $parameters, $errorLevel = E_USER_ERROR)
            {
                $this->sent[] = $sql;

                return parent::preparedQuery(($this->rewrite)($sql), $parameters, $errorLevel);
            }
        };
        $standIn->setConnector($connection->getConnector());
        $standIn->setQueryBuilder($connection->getQueryBuilder());
        $schemaManager = $connection->getSchemaManager();
        $standIn->setSchemaManager($schemaManager);

        DB::set_conn($standIn, DB::CONN_PRIMARY);
        try {
            FixtureLoader::singleton()->load(self::FIXTURES . 'tags.yml');
        } finally {
            DB::set_conn($connection, DB::CONN_PRIMARY);
            $connection->setSchemaManager($schemaManager);
        }

        return $standIn->sent;
    }

    /**
     * @param list<string> $sent
     *
     * @return list<string> the statements on the variable and the floors statement, in the order sent
     */
    private function expiryStatements(array $sent): array
    {
        $statements = [];
        foreach ($sent as $sql) {
            if (self::isFloors($sql)) {
                $statements[] = 'the floors';
            } elseif (str_contains($sql, 'information_schema_stats_expiry')) {
                $statements[] = $sql;
            }
        }

        return $statements;
    }

    private static function isFloors(string $sql): bool
    {
        return str_starts_with($sql, 'SELECT GREATEST(');
    }

    private function sessionExpiry(): string
    {
        return (string) DB::query(self::VARIABLE)->record()['Value'];
    }
}
