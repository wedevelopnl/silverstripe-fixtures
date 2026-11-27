<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Load;

use SilverStripe\Assets\Folder;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\FieldType\DBDatetime;
use WeDevelop\Fixtures\FixtureLedger;
use WeDevelop\Fixtures\Schema\ClassSchema;
use WeDevelop\Fixtures\Schema\ValueConverter;

/**
 * Asks the database, the field types, the asset store and the clock what RowPlanner needs, in this order: the ID
 * floors, the folders, the stated timestamps converted, the files placed, the time.
 */
final readonly class LoadPlanner
{
    /**
     * @param array<string, ClassSchema|null> $schemas by class as written, of every record
     */
    public function __construct(private array $schemas, private ValueConverter $converter)
    {
    }

    /**
     * Places the source files before anything is written; a failed load leaves them in the store, as a reset
     * leaves files alone.
     *
     * @param list<FixtureRecord> $records validated
     */
    public function plan(array $records): Plan
    {
        $planner = new RowPlanner($this->schemas, (string) DataObject::getSchema()->tableName(FixtureLedger::class));
        $floors = $this->floors($planner->idSources($records));
        $folders = [];
        foreach ($planner->folderIds($records) as $id) {
            $folder = Folder::get()->byID($id);
            if ($folder !== null) {
                $folders[$id] = (string) $folder->getFilename();
            }
        }

        // Before the files are placed, so a value DBDatetime refuses leaves none in the store
        $timestamps = [];
        foreach ($planner->timestamps($records) as $key => $stated) {
            foreach ($stated as $column => [$spec, $value]) {
                $timestamps[$key][$column] = $this->converter->value($spec, $column, $value);
            }
        }

        $placed = [];
        foreach ($planner->placements($records, $folders) as $key => [$source, $filename, $public]) {
            $placed[$key] = FilePlacer::place($source, $filename, $public);
        }

        return $planner->plan($records, $floors, DBDatetime::now()->Rfc2822(), $placed, $timestamps);
    }

    /**
     * The highest ID each table has handed out, in one query: above every ID its tables hold, and below its
     * auto-increment counter, so an ID a reset freed is never handed out again.
     *
     * @param array<string, array<string, string>> $sources by table with its own ID sequence: ID column by table
     *                                                     holding its IDs
     *
     * @return array<string, int>
     */
    private function floors(array $sources): array
    {
        $columns = [];
        $parameters = [];
        foreach ($sources as $table => $tables) {
            $maxima = ['COALESCE((SELECT "AUTO_INCREMENT" - 1 FROM "information_schema"."TABLES" WHERE "TABLE_SCHEMA" = DATABASE() AND "TABLE_NAME" = ?), 0)'];
            $parameters[] = $table;
            foreach ($tables as $source => $column) {
                $maxima[] = sprintf('COALESCE((SELECT MAX("%s") FROM "%s"), 0)', $column, $source);
            }

            $columns[] = sprintf('GREATEST(%s) AS "%s"', implode(', ', $maxima), $table);
        }

        $row = $this->withLiveStatistics(
            static fn (): array => DB::prepared_query('SELECT ' . implode(', ', $columns), $parameters)->record(),
        );
        $floors = [];
        foreach ($row as $table => $floor) {
            $floors[(string) $table] = is_numeric($floor) ? (int) $floor : 0;
        }

        return $floors;
    }

    /**
     * MySQL 8 serves information_schema's AUTO_INCREMENT from a statistics cache that is a day old by default;
     * MariaDB has no such cache, nor the variable.
     *
     * @template T
     *
     * @param callable(): T $query
     *
     * @return T
     */
    private function withLiveStatistics(callable $query): mixed
    {
        $expiry = DB::query("SHOW VARIABLES LIKE 'information_schema_stats_expiry'")->record()['Value'] ?? null;
        if (!is_numeric($expiry)) {
            return $query();
        }

        DB::query('SET SESSION information_schema_stats_expiry = 0');
        try {
            return $query();
        } finally {
            DB::query('SET SESSION information_schema_stats_expiry = ' . (int) $expiry);
        }
    }
}
