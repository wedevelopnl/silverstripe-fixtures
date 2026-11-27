<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Tests\Integration;

use Page;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Fixtures\FixtureLoader;

/**
 * Pages loaded by the loader as the CMS and the site see them. Needs silverstripe/cms.
 */
final class CmsPageTest extends FunctionalTest
{
    private const string PAGES = __DIR__ . '/fixtures/pages.yml';

    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists(SiteTree::class)) {
            $this->markTestSkipped('silverstripe/cms is not installed');
        }
    }

    public function testAPublishedPageIsVisibleOnTheLiveSiteAndADraftOnlyPageIsNot(): void
    {
        $loaded = FixtureLoader::singleton()->load(self::PAGES);

        $home = Page::get()->byID($loaded->idOf('Page', 'home'));
        $this->assertNotNull($home);
        $this->assertTrue($home->isPublished());
        $this->assertSame('Home', Versioned::get_by_stage(Page::class, Versioned::LIVE)->byID($home->ID)?->Title);
        $about = Page::get()->byID($loaded->idOf('Page', 'about'));
        $this->assertNotNull($about);
        $this->assertFalse($about->isPublished());
        $this->assertTrue($about->isOnDraft());

        $response = $this->get('/home');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Home', (string) $response->getBody());
        $this->assertSame(404, $this->get('/about-us')->getStatusCode());
    }

    public function testAPublishedPageWithADraftTitleIsModifiedAndShowsEachTitleOnItsOwnStage(): void
    {
        $loaded = FixtureLoader::singleton()->load(__DIR__ . '/fixtures/pages-draft.yml');

        $about = Page::get()->byID($loaded->idOf('Page', 'about'));
        $this->assertNotNull($about);
        $this->assertTrue($about->isPublished());
        $this->assertTrue($about->isModifiedOnDraft());
        $this->assertArrayHasKey('modified', $about->getStatusFlags(), 'the CMS shows the page as modified');

        $live = (string) $this->get('/about-us')->getBody();
        $this->assertStringContainsString('About us', $live);
        $this->assertStringNotContainsString('About us (draft)', $live, 'the live site shows the published title');

        $this->logInWithPermission('ADMIN');
        $this->assertStringContainsString('About us (draft)', (string) $this->get('/about-us?stage=Stage')->getBody(), 'the draft shows the draft title');
    }

    public function testResettingPagesKeepsAPlainSiteTreeRecord(): void
    {
        $plain = SiteTree::create();
        $plain->update(['Title' => 'Plain', 'URLSegment' => 'plain']);
        $plain->write();
        $plain->publishSingle();
        $created = Page::create();
        $created->update(['Title' => 'Created', 'URLSegment' => 'created']);
        $created->write();
        FixtureLoader::singleton()->load(self::PAGES);
        Config::modify()->set(FixtureLoader::class, 'reset_classes', [Page::class]);

        FixtureLoader::singleton()->reset();

        foreach ([Versioned::DRAFT, Versioned::LIVE] as $stage) {
            $this->assertSame(['Plain'], Versioned::get_by_stage(SiteTree::class, $stage)->column('Title'), $stage);
        }
    }
}
