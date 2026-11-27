<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\FieldType\DBDatetime;
use WeDevelop\Fixtures\FixtureLoader;
use WeDevelop\Fixtures\Load\LoadPlanner;
use WeDevelop\Fixtures\Load\RowWriter;
use WeDevelop\Fixtures\Schema\ClassSchema;
use WeDevelop\Fixtures\Schema\ValueConverter;
use WeDevelop\Fixtures\Tests\Integration\Model\Plain;
use WeDevelop\Fixtures\Tests\Integration\Model\PlainLink;
use WeDevelop\Fixtures\Tests\Integration\Model\Sub;
use WeDevelop\Fixtures\Tests\Integration\Model\SubWithoutTable;
use WeDevelop\Fixtures\Tests\Integration\Model\Tag;

/**
 * The loader's rows must equal the rows write() leaves for the same records: the only guard that a directly
 * written row is a row the ORM would have written.
 */
#[CoversClass(FixtureLoader::class)]
#[CoversClass(ClassSchema::class)]
#[CoversClass(LoadPlanner::class)]
#[CoversClass(ValueConverter::class)]
#[CoversClass(RowWriter::class)]
final class CompatibilityTest extends SapphireTest
{
    use RowSnapshot;

    private const array TABLES = [
        'FixturesTest_Tag',
        'FixturesTest_Plain',
        'FixturesTest_Sub',
        'FixturesTest_PlainLink',
        'FixturesTest_Plain_Tags',
        'FixturesTest_Plain_Related',
    ];

    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        Plain::class,
        Sub::class,
        SubWithoutTable::class,
        Tag::class,
        PlainLink::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        DBDatetime::set_mock_now('2026-11-27 10:00:00');
    }

    public function testTheLoaderWritesTheRowsWriteWouldForEveryClassShape(): void
    {
        $this->reserveIdRanges();
        $written = $this->snapshot(self::TABLES, $this->writeThroughTheOrm());
        $this->assertNotContains([], $written, 'every compared table holds rows');
        $this->emptyTables(self::TABLES);
        $this->reserveIdRanges();

        $loaded = FixtureLoader::singleton()->load(
            __DIR__ . '/fixtures/compatibility.yml',
        );
        $ids = [];
        foreach ($loaded->toArray() as $identifiers) {
            $ids = [...$ids, ...$identifiers];
        }

        $this->assertSame($written, $this->snapshot(self::TABLES, $ids));
    }

    /**
     * Each table numbers its records from 1, so a Tag and a Plain could share an ID and the snapshot could not
     * tell which record a foreign key names. A record at a distinct high ID moves each table to its own range.
     */
    private function reserveIdRanges(): void
    {
        foreach ([Tag::class => 1000, PlainLink::class => 2000] as $class => $id) {
            $record = $class::create();
            $record->ID = $id;
            $record->write(false, true);
        }
    }

    /**
     * @return array<string, int>
     */
    private function writeThroughTheOrm(): array
    {
        $red = Tag::create();
        $red->Title = 'Red';
        $red->write();
        $blue = Tag::create();
        $blue->Title = 'Blue';
        $blue->write();

        $root = Plain::create();
        $root->update([
            'Title' => 'Root',
            'Flag' => true,
            'PriceAmount' => 12.5,
            'PriceCurrency' => 'EUR',
            'Status' => 'Done',
            'TargetID' => $red->ID,
            'TargetClass' => Tag::class,
        ]);
        $root->write();
        $root->Tags()->add($red);
        $root->Tags()->add($blue, ['Weight' => 3]);

        $child = Plain::create();
        $child->update(['Title' => 'Child', 'PriceAmount' => 3, 'PriceCurrency' => 'USD', 'ParentID' => $root->ID]);
        $child->write();
        $child->Related()->add($root);
        // A pair stated twice, or on both sides, is one join row
        $child->Tags()->add($red);
        $child->Tags()->add($red);
        $red->Plains()->add($root);
        $blue->Plains()->add($child);

        $leaf = Sub::create();
        $leaf->update(['Title' => 'Leaf', 'Level' => 2]);
        $leaf->write();

        $tableless = SubWithoutTable::create();
        $tableless->update(['Title' => 'Tableless', 'Note' => 'stated']);
        $tableless->write();

        $link = PlainLink::create();
        $link->update(['Weight' => 4, 'FromID' => $root->ID, 'ToID' => $blue->ID]);
        $link->write();

        return [
            'red' => $red->ID,
            'blue' => $blue->ID,
            'root' => $root->ID,
            'child' => $child->ID,
            'leaf' => $leaf->ID,
            'tableless' => $tableless->ID,
            'link' => $link->ID,
        ];
    }
}
