<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Tests\Unit\Load;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WeDevelop\Fixtures\Load\Plan;
use WeDevelop\Fixtures\Load\VersionedRows;
use WeDevelop\Fixtures\Schema\ClassSchema;
use WeDevelop\Fixtures\Tests\Unit\ClassSchemas;

#[CoversClass(VersionedRows::class)]
final class VersionedRowsTest extends TestCase
{
    private const array SPECS = ['Title' => 'Varchar'];

    private const array VERSION = ['WasDraft' => 1, 'WasDeleted' => 0, 'AuthorID' => 0, 'PublisherID' => 0];

    public function testAPublishedRecordIsOnDraftAndLiveWithOnePublishedVersion(): void
    {
        $plan = new Plan([]);

        VersionedRows::add($plan, $this->schema(true), $this->rows('Home'), true, null);

        $this->assertSame(
            [
                'Page' => [['ID' => 3, 'Title' => 'Home', 'Version' => 1]],
                'Page_Sub' => [['ID' => 3, 'Extra' => 'x']],
                'Page_Live' => [['ID' => 3, 'Title' => 'Home', 'Version' => 1]],
                'Page_Sub_Live' => [['ID' => 3, 'Extra' => 'x']],
                'Page_Versions' => [['RecordID' => 3, 'Title' => 'Home', 'Version' => 1, 'WasPublished' => 1, ...self::VERSION]],
                'Page_Sub_Versions' => [['RecordID' => 3, 'Extra' => 'x', 'Version' => 1]],
            ],
            $plan->rows(),
        );
        $this->assertSame([0 => [self::SPECS, null]], $plan->conversions()['Page_Versions'], 'each copy converts its raw values');
    }

    public function testADraftOnlyRecordHasAnUnpublishedVersionAndNoLiveRow(): void
    {
        $plan = new Plan([]);

        VersionedRows::add($plan, $this->schema(true), $this->rows('Home'), false, null);

        $this->assertSame(['Page', 'Page_Sub', 'Page_Versions', 'Page_Sub_Versions'], array_keys($plan->rows()));
        $this->assertSame(0, $plan->rows()['Page_Versions'][0]['WasPublished']);
    }

    public function testEveryVersionOfAClassWithoutStagesCountsAsPublished(): void
    {
        $plan = new Plan([]);

        VersionedRows::add($plan, $this->schema(false), $this->rows('Home'), false, null);

        $this->assertSame(1, $plan->rows()['Page_Versions'][0]['WasPublished']);
    }

    public function testARecordWithDraftValuesIsLiveAtVersionOneAndDraftAtVersionTwo(): void
    {
        $plan = new Plan([]);

        VersionedRows::add($plan, $this->schema(true), $this->rows('Home'), true, $this->rows('Home (draft)'));

        $this->assertSame([['ID' => 3, 'Title' => 'Home', 'Version' => 1]], $plan->rows()['Page_Live']);
        $this->assertSame([['ID' => 3, 'Title' => 'Home (draft)', 'Version' => 2]], $plan->rows()['Page']);
        $this->assertSame(
            [
                ['RecordID' => 3, 'Title' => 'Home', 'Version' => 1, 'WasPublished' => 1, ...self::VERSION],
                ['RecordID' => 3, 'Title' => 'Home (draft)', 'Version' => 2, 'WasPublished' => 0, ...self::VERSION],
            ],
            $plan->rows()['Page_Versions'],
        );
    }

    private function schema(bool $staged): ClassSchema
    {
        return ClassSchemas::of('App\Sub', 'Page', tables: ['Page_Sub'], versioned: true, staged: $staged);
    }

    /**
     * @return array<string, array{array<string, mixed>, array<string, string>}>
     */
    private function rows(string $title): array
    {
        return ['Page' => [['ID' => 3, 'Title' => $title], self::SPECS], 'Page_Sub' => [['ID' => 3, 'Extra' => 'x'], []]];
    }
}
