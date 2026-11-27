<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Tests\Integration;

use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\Assets\Image;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Fixtures\FixtureLoader;
use WeDevelop\Fixtures\InvalidFixtureException;
use WeDevelop\Fixtures\LoadedFixtures;
use WeDevelop\Fixtures\Tests\Integration\Model\Card;
use WeDevelop\Fixtures\Tests\Integration\Model\Tag;

final class FileFixtureTest extends SapphireTest
{
    private const string FIXTURES = 'wedevelopnl/silverstripe-fixtures:tests/Integration/fixtures/';

    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        Card::class,
        Tag::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        TestAssetStore::activate('FileFixtureTest');
    }

    protected function tearDown(): void
    {
        TestAssetStore::reset();
        parent::tearDown();
    }

    public function testAnAttachedFileIsPlacedInTheAssetStoreAndListedWithItsFolder(): void
    {
        $loaded = FixtureLoader::singleton()->load(self::FIXTURES . 'files.yml');

        $card = Card::get()->byID($loaded->idOf(Card::class, 'card'));
        $this->assertNotNull($card);
        $image = $card->Image();
        $this->assertTrue($image->exists(), 'the record shows the image');
        $this->assertSame('fixture-files/logo.png', $image->getFilename());
        $this->assertSame(sha1_file(__DIR__ . '/../files/logo.png'), $image->getHash());
        $this->assertSame(['fixture-files'], Folder::get()->column('Name'), 'the files section lists the folder');
        $this->assertSame(['logo.png'], File::get()->filter('ParentID', $image->ParentID)->column('Name'));
        $this->assertNotEmpty($image->getURL());
    }

    public function testAFileWithoutPublishedStateIsPublicWithoutStagesAndProtectedOnDraft(): void
    {
        $loaded = FixtureLoader::singleton()->load(self::FIXTURES . 'files.yml');

        $this->assertSame(
            class_exists(Versioned::class) ? AssetStore::VISIBILITY_PROTECTED : AssetStore::VISIBILITY_PUBLIC,
            $this->visibility($loaded, Image::class, 'logo'),
        );
    }

    public function testAPublishedFileIsPublicAndADraftOnlyFileIsProtected(): void
    {
        $this->requireVersioned();

        $loaded = FixtureLoader::singleton()->load(self::FIXTURES . 'files-published.yml');

        $this->assertSame(AssetStore::VISIBILITY_PUBLIC, $this->visibility($loaded, Image::class, 'logo'));
        $this->assertSame(AssetStore::VISIBILITY_PROTECTED, $this->visibility($loaded, File::class, 'notes'));
        $image = Image::get()->byID($loaded->idOf(Image::class, 'logo'));
        $this->assertTrue($image?->isPublished());
        $this->assertNotNull(Versioned::get_by_stage(Folder::class, Versioned::LIVE)->byID($image->ParentID));
    }

    public function testAFilePublishedByAnEarlierLoadIsProtectedWhenDeclaredDraftOnly(): void
    {
        $this->requireVersioned();
        FixtureLoader::singleton()->load(self::FIXTURES . 'files-published.yml');
        FixtureLoader::singleton()->reset();

        $loaded = FixtureLoader::singleton()->load(self::FIXTURES . 'files-draft.yml');

        $this->assertSame(AssetStore::VISIBILITY_PROTECTED, $this->visibility($loaded, Image::class, 'logo'));
    }

    public function testAFileOrFolderAtANameTheSiteAlreadyHasIsRefused(): void
    {
        Folder::find_or_make('fixture-files');

        $this->expectException(InvalidFixtureException::class);
        $this->expectExceptionMessage(
            'SilverStripe\Assets\Folder.fixture_files: the site already has a file or folder "fixture-files"; '
            . 'give the test data a folder of its own',
        );

        FixtureLoader::singleton()->load(self::FIXTURES . 'files.yml');
    }

    public function testEveryFileProblemIsListed(): void
    {
        try {
            FixtureLoader::singleton()->load(self::FIXTURES . 'files-invalid.yml');
            $this->fail('An invalid file fixture must be refused');
        } catch (InvalidFixtureException $exception) {
            $where = self::FIXTURES . 'files-invalid.yml, ';
            $this->assertSame(
                [
                    $where . 'SilverStripe\Assets\Folder.uploads_again: "Uploads" is also the name of SilverStripe\Assets\Folder.uploads in the same folder',
                    $where . 'SilverStripe\Assets\File.missing: "@source": no file at wedevelopnl/silverstripe-fixtures:tests/files/missing.txt',
                    $where . 'SilverStripe\Assets\File.nameless: "Name" is required for a file with "@source"',
                    $where . 'SilverStripe\Assets\File.readme_copy: "readme.txt" is also the name of SilverStripe\Assets\File.readme in the same folder',
                    $where . Tag::class . '.red: "@source": ' . Tag::class . ' is not a file class',
                ],
                $exception->getProblems(),
            );
        }

        $this->assertSame(0, File::get()->count());
    }

    public function testResetKeepsAFileTheSiteAlreadyHad(): void
    {
        $existing = Image::create();
        $existing->setFromString('existing', 'existing.png');
        $existing->write();
        FixtureLoader::singleton()->load(self::FIXTURES . 'files.yml');
        Config::modify()->set(FixtureLoader::class, 'reset_classes', [Card::class]);

        FixtureLoader::singleton()->reset();

        $this->assertSame([$existing->ID], File::get()->column('ID'));
        $this->assertSame(0, Card::get()->count());
    }

    private function visibility(LoadedFixtures $loaded, string $class, string $identifier): ?string
    {
        $file = File::get()->byID($loaded->idOf($class, $identifier));
        $this->assertNotNull($file);

        return Injector::inst()->get(AssetStore::class)->getVisibility($file->getFilename(), $file->getHash());
    }

    private function requireVersioned(): void
    {
        if (!class_exists(Versioned::class)) {
            $this->markTestSkipped('silverstripe/versioned is not installed');
        }
    }
}
