<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use WeDevelop\Fixtures\InvalidFixtureException;
use WeDevelop\Fixtures\LoadedFixtures;
use WeDevelop\Fixtures\ResetRefusedException;

#[CoversClass(LoadedFixtures::class)]
#[CoversClass(InvalidFixtureException::class)]
#[CoversClass(ResetRefusedException::class)]
final class LoadedFixturesTest extends TestCase
{
    public function testItAnswersTheIdOfARecordByTheClassAsWrittenAndItsIdentifier(): void
    {
        $loaded = new LoadedFixtures(['Page' => ['home' => 7]]);

        $this->assertSame(7, $loaded->idOf('Page', 'home'));
    }

    public function testAnUnknownRecordIsRefused(): void
    {
        $loaded = new LoadedFixtures(['Page' => ['home' => 7]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No record Page.contact was loaded');

        $loaded->idOf('Page', 'contact');
    }

    public function testToArrayKeepsDeclarationOrder(): void
    {
        $ids = ['Page' => ['home' => 9, 'about' => 3], 'Image' => ['logo' => 1]];

        $this->assertSame($ids, (new LoadedFixtures($ids))->toArray());
    }

    public function testAnInvalidFixtureIsAnInvalidArgumentListingEveryProblem(): void
    {
        $exception = new InvalidFixtureException(['a.yml, Page.home: one', 'b.yml, Page.about: two']);

        $this->assertInstanceOf(InvalidArgumentException::class, $exception);
        $this->assertSame(['a.yml, Page.home: one', 'b.yml, Page.about: two'], $exception->getProblems());
        $this->assertSame(
            "Test data could not be loaded, nothing was written:\n"
            . "- a.yml, Page.home: one\n"
            . '- b.yml, Page.about: two',
            $exception->getMessage(),
        );
    }

    public function testARefusedResetIsARuntimeException(): void
    {
        $this->assertInstanceOf(RuntimeException::class, new ResetRefusedException());
        $this->assertSame(
            'Clearing test data only runs in development and test environments.',
            (new ResetRefusedException())->getMessage(),
        );
    }
}
