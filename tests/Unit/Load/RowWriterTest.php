<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Tests\Unit\Load;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WeDevelop\Fixtures\Load\RowWriter;

#[CoversClass(RowWriter::class)]
final class RowWriterTest extends TestCase
{
    public function testRowsStatingDifferentColumnsGoInSeparateInsertsWithQuotedColumns(): void
    {
        $batches = RowWriter::batches([
            'Page' => [['ID' => 1, 'Title' => 'Home'], ['ID' => 2], ['ID' => 3, 'Title' => 'About']],
            'Tag' => [['ID' => 4]],
        ]);

        $this->assertSame(
            [
                ['Page', [['"ID"' => 1, '"Title"' => 'Home'], ['"ID"' => 3, '"Title"' => 'About']]],
                ['Page', [['"ID"' => 2]]],
                ['Tag', [['"ID"' => 4]]],
            ],
            $batches,
        );
    }

    public function testATableAboveTheParameterLimitIsSplitAcrossInserts(): void
    {
        // Three columns: 20,000 rows reach the 60,000 parameters one insert takes
        $rows = array_map(static fn (int $id): array => ['ID' => $id, 'PageID' => 1, 'TagID' => $id], range(1, 20001));

        $batches = RowWriter::batches(['Page_Tags' => $rows]);

        $this->assertSame([20000, 1], array_map(static fn (array $batch): int => count($batch[1]), $batches));
        $this->assertSame(['"ID"' => 20001, '"PageID"' => 1, '"TagID"' => 20001], $batches[1][1][0]);
    }
}
