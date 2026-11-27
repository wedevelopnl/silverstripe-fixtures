<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Tests\Unit\Load;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WeDevelop\Fixtures\Load\FixtureRecord;
use WeDevelop\Fixtures\Load\LookupRequest;
use WeDevelop\Fixtures\Load\Lookups;
use WeDevelop\Fixtures\Load\ManyManyItem;
use WeDevelop\Fixtures\Load\Reference;
use WeDevelop\Fixtures\Load\Validator;
use WeDevelop\Fixtures\Schema\ClassSchema;
use WeDevelop\Fixtures\Schema\FieldKind;
use WeDevelop\Fixtures\Tests\Unit\ClassSchemas;

#[CoversClass(Validator::class)]
#[CoversClass(LookupRequest::class)]
#[CoversClass(Lookups::class)]
#[CoversClass(Reference::class)]
#[CoversClass(ManyManyItem::class)]
final class ValidatorTest extends TestCase
{
    private const string PLAIN = 'App\Plain';

    private const string TAG = 'App\Tag';

    private const string FILE = 'App\File';

    private const string PAGE = 'App\Page';

    private const string UNSTAGED = 'App\Unstaged';

    private const string LOCALE = 'App\Locale';

    /**
     * @return iterable<string, array{string, mixed, string|null}>
     */
    public static function fieldValues(): iterable
    {
        yield 'a plain value' => ['Title', 'Home', null];
        yield 'the ID' => ['ID', 5, '"ID" is set by the loader'];
        yield 'the class name' => ['ClassName', 'Other', '"ClassName" is set by the loader'];
        yield 'an unknown field' => ['Subtitle', 'x', 'unknown value "Subtitle"'];
        yield 'a generated column' => ['TitleLength', 3, '"TitleLength" is a generated column and cannot be written'];
        yield 'a has_one' => ['Parent', '=>' . self::PLAIN . '.other', null];
        yield 'a has_one to a record no file holds' => ['Parent', '=>' . self::PLAIN . '.missing', 'refers to ' . self::PLAIN . '.missing, which no file of this load holds'];
        yield 'a has_one without a reference' => ['Target', 'not-a-reference', '"Target" must be a reference like =>Class.identifier'];
        yield 'a many_many' => ['Tags', ['=>' . self::TAG . '.red', ['ref' => '=>' . self::TAG . '.blue', 'Weight' => 1]], null];
        yield 'a many_many that is no list' => ['Tags', '=>' . self::TAG . '.red', '"Tags" must be a list of references, like [=>Class.a, =>Class.b]'];
        yield 'a many_many with an unknown extra field' => ['Related', [['ref' => '=>' . self::PLAIN . '.other', 'Weight' => 1]], '"Related" has no extra field "Weight"'];
        yield 'a many_many to a record no file holds' => ['Related', ['=>' . self::PLAIN . '.missing'], 'refers to ' . self::PLAIN . '.missing, which no file of this load holds'];
        yield 'a many_many through' => ['Links', ['=>' . self::TAG . '.red'], '"Links" is a many_many through; declare records of its join class instead'];
        yield 'a has_many' => ['Children', ['=>' . self::PLAIN . '.other'], '"Children" is set on the record that holds the has_one'];
        yield 'a has_one ID holding a reference' => ['ParentID', '=>' . self::PLAIN . '.other', '"ParentID" cannot hold a reference; set the has_one instead: Parent: =>Class.identifier'];
        yield 'a plain value holding a reference' => ['Body', '=>' . self::TAG . '.red', '"Body" cannot hold a reference; only a has_one or many_many takes one'];
    }

    #[DataProvider('fieldValues')]
    public function testEachFieldIsCheckedByWhatItIsOnTheClass(string $field, mixed $value, ?string $problem): void
    {
        $problems = $this->problems([
            $this->record(self::PLAIN, 'home', [$field => $value]),
            $this->record(self::PLAIN, 'other'),
            $this->record(self::TAG, 'red'),
            $this->record(self::TAG, 'blue'),
        ]);

        $this->assertSame($problem === null ? [] : ['a.yml, ' . self::PLAIN . '.home: ' . $problem], $problems);
    }

    public function testEveryProblemIsListedInRecordOrderWithItsFileAndRecord(): void
    {
        $problems = $this->problems([
            $this->record('Unknown\Kind', 'thing', ['Title' => 'Thing']),
            $this->record(self::PLAIN, 'broken', ['Subtitle' => 'x', 'ID' => 5], published: true, draft: ['Body' => 'Draft']),
            $this->record(self::TAG, 'red', ['Plains' => ['=>' . self::PLAIN . '.missing']]),
        ]);

        $this->assertSame(
            [
                'a.yml, Unknown\Kind.thing: unknown class "Unknown\Kind"',
                'a.yml, App\Plain.broken: unknown value "Subtitle"',
                'a.yml, App\Plain.broken: "ID" is set by the loader',
                'a.yml, App\Plain.broken: "@published": App\Plain has no published state',
                'a.yml, App\Plain.broken: "@draft": App\Plain has no published state',
                'a.yml, App\Tag.red: refers to App\Plain.missing, which no file of this load holds',
            ],
            $problems,
        );
    }

    public function testAPublishedStateNeedsAStagedVersionedClass(): void
    {
        $problems = $this->problems([
            $this->record(self::PLAIN, 'plain', published: false),
            $this->record(self::UNSTAGED, 'unstaged', published: true),
            $this->record(self::UNSTAGED, 'unpublished', published: false),
            $this->record(self::PAGE, 'page', published: true),
        ]);

        $this->assertSame(
            [
                'a.yml, App\Plain.plain: "@published": App\Plain has no published state',
                'a.yml, App\Unstaged.unstaged: "@published": App\Unstaged is versioned without stages and has no published state',
            ],
            $problems,
        );
    }

    public function testDraftValuesArePlainValuesOfAPublishedRecordOfAStagedClass(): void
    {
        $problems = $this->problems([
            $this->record(self::PLAIN, 'plain', draft: [
                'Title' => 'Draft',
                'Subtitle' => 'x',
                'ID' => 3,
                'Body' => '=>' . self::TAG . '.red',
                'Target' => '=>' . self::TAG . '.red',
                'Tags' => ['=>' . self::TAG . '.red'],
                'Children' => ['=>' . self::PLAIN . '.plain'],
            ]),
            $this->record(self::UNSTAGED, 'unstaged', draft: ['Title' => 'Draft']),
            $this->record(self::PAGE, 'unpublished', draft: ['Title' => 'Draft']),
            $this->record(self::PAGE, 'published', published: true, draft: ['Title' => 'Draft']),
            $this->record(self::TAG, 'red'),
        ]);

        $this->assertSame(
            [
                'a.yml, App\Plain.plain: "@draft": App\Plain has no published state',
                'a.yml, App\Plain.plain: "@draft": unknown value "Subtitle"',
                'a.yml, App\Plain.plain: "@draft": "ID" is set by the loader',
                'a.yml, App\Plain.plain: "@draft": "Body" cannot hold a reference',
                'a.yml, App\Plain.plain: "@draft": "Target" cannot hold a reference',
                'a.yml, App\Plain.plain: "@draft": "Tags" cannot hold a reference',
                'a.yml, App\Plain.plain: "@draft": "Children" is set on the record that holds the has_one',
                'a.yml, App\Unstaged.unstaged: "@draft": App\Unstaged is versioned without stages and has no published state',
                'a.yml, App\Page.unpublished: "@draft" needs a published record',
            ],
            $problems,
        );
    }

    public function testADraftValueCannotRenameOrMoveAFile(): void
    {
        $problems = $this->problems([
            $this->record(self::FILE, 'logo', ['Name' => 'logo.png'], published: true, draft: [
                'Title' => 'Logo (draft)',
                'Name' => 'renamed.png',
                'ParentID' => 0,
                'Parent' => '=>' . self::FILE . '.logo',
                'File' => 'renamed.png',
                'FileFilename' => 'renamed.png',
                'FileHash' => 'abc',
                'FileVariant' => 'thumbnail',
            ]),
        ]);

        $label = 'a.yml, App\File.logo: "@draft": ';
        $this->assertSame(
            [
                $label . '"Name" cannot rename or move a file',
                $label . '"ParentID" cannot rename or move a file',
                $label . '"Parent" cannot hold a reference',
                $label . '"File" cannot rename or move a file',
                $label . '"FileFilename" cannot rename or move a file',
                $label . '"FileHash" cannot rename or move a file',
                $label . '"FileVariant" cannot rename or move a file',
            ],
            $problems,
        );
    }

    public function testDraftValuesCannotBeCombinedWithValuesPerLanguage(): void
    {
        $problems = $this->problems(
            [$this->record(self::PAGE, 'home', published: true, locales: ['nl_NL' => ['Title' => 'Thuis']], draft: ['Title' => 'Draft'])],
            new Lookups(['nl_NL'], [], [], []),
        );

        $this->assertSame(['a.yml, App\Page.home: "@draft" cannot be combined with "@locales"'], $problems);
    }

    public function testAPairStatedAgainMustStateTheSameExtraFieldValuesFromEitherSide(): void
    {
        $problems = $this->problems([
            $this->record(self::PLAIN, 'twice', ['Tags' => [
                ['ref' => '=>' . self::TAG . '.red', 'Weight' => 1],
                ['ref' => '=>' . self::TAG . '.red', 'Weight' => 2],
                ['ref' => '=>' . self::TAG . '.blue', 'Weight' => 1],
                '=>' . self::TAG . '.green',
            ]]),
            $this->record(self::TAG, 'red'),
            $this->record(self::TAG, 'blue', ['Plains' => [['ref' => '=>' . self::PLAIN . '.twice', 'Weight' => 2]]]),
            $this->record(self::TAG, 'green', ['Plains' => ['=>' . self::PLAIN . '.twice']]),
        ]);

        $this->assertSame(
            [
                'a.yml, App\Plain.twice: "Tags" pairs it with App\Tag.red again, with other extra field values than App\Plain.twice "Tags"',
                'a.yml, App\Tag.blue: "Plains" pairs it with App\Plain.twice again, with other extra field values than App\Plain.twice "Tags"',
            ],
            $problems,
        );
    }

    public function testASourceNeedsAFileClassAFileAtThePathAndAName(): void
    {
        $problems = $this->problems(
            [
                $this->record(self::FILE, 'missing', ['Name' => 'missing.txt', 'Parent' => '=>' . self::FILE . '.folder'], source: '/files/missing.txt'),
                $this->record(self::FILE, 'nameless', source: '/files/notes.txt'),
                $this->record(self::FILE, 'folder', ['Name' => 'uploads']),
                $this->record(self::TAG, 'red', source: '/files/notes.txt'),
            ],
            new Lookups(null, [], [], ['/files/missing.txt' => false, '/files/notes.txt' => true]),
        );

        $this->assertSame(
            [
                'a.yml, App\File.missing: "@source": no file at /files/missing.txt',
                'a.yml, App\File.nameless: "Name" is required for a file with "@source"',
                'a.yml, App\Tag.red: "@source": App\Tag is not a file class',
            ],
            $problems,
        );
    }

    public function testAFileCannotTakeThePlaceOfOneTheSiteHas(): void
    {
        $problems = $this->problems(
            [
                $this->record(self::FILE, 'root', ['Name' => 'uploads']),
                $this->record(self::FILE, 'in_site_folder', ['Name' => 'logo.png', 'ParentID' => 7]),
                $this->record(self::FILE, 'elsewhere', ['Name' => 'logo.png', 'ParentID' => 8]),
                $this->record(self::FILE, 'below_a_new_folder', ['Name' => 'logo.png', 'Parent' => '=>' . self::FILE . '.root']),
            ],
            new Lookups(null, [0 => ['uploads'], 7 => ['logo.png'], 8 => ['other.png']], [7 => 'site-folder/'], []),
        );

        $this->assertSame(
            [
                'a.yml, App\File.root: the site already has a file or folder "uploads"; give the test data a folder of its own',
                'a.yml, App\File.in_site_folder: the site already has a file or folder "site-folder/logo.png"; give the test data a folder of its own',
            ],
            $problems,
        );
    }

    public function testTwoFilesOfTheLoadCannotShareAPlace(): void
    {
        $problems = $this->problems([
            $this->record(self::FILE, 'uploads', ['Name' => 'Uploads']),
            $this->record(self::FILE, 'uploads_again', ['Name' => 'Uploads']),
            $this->record(self::FILE, 'readme', ['Name' => 'readme.txt', 'Parent' => '=>' . self::FILE . '.uploads']),
            $this->record(self::FILE, 'readme_copy', ['Name' => 'readme.txt', 'Parent' => '=>' . self::FILE . '.uploads']),
            $this->record(self::FILE, 'readme_in_root', ['Name' => 'readme.txt', 'ParentID' => 0]),
        ]);

        $this->assertSame(
            [
                'a.yml, App\File.uploads_again: "Uploads" is also the name of App\File.uploads in the same folder',
                'a.yml, App\File.readme_copy: "readme.txt" is also the name of App\File.readme in the same folder',
            ],
            $problems,
        );
    }

    public function testAFolderChainLeadingBackToTheRecordIsReportedOnEachRecordInIt(): void
    {
        $problems = $this->problems([
            $this->record(self::FILE, 'own_parent', ['Name' => 'Own parent', 'Parent' => '=>' . self::FILE . '.own_parent']),
            $this->record(self::FILE, 'loop_a', ['Name' => 'Loop A', 'Parent' => '=>' . self::FILE . '.loop_b']),
            $this->record(self::FILE, 'loop_b', ['Name' => 'Loop B', 'Parent' => '=>' . self::FILE . '.loop_a']),
            $this->record(self::FILE, 'in_a_loop', ['Name' => 'logo.png', 'Parent' => '=>' . self::FILE . '.loop_a']),
        ]);

        $this->assertSame(
            [
                'a.yml, App\File.own_parent: "Parent" leads back to the record itself: App\File.own_parent => App\File.own_parent',
                'a.yml, App\File.loop_a: "Parent" leads back to the record itself: App\File.loop_a => App\File.loop_b => App\File.loop_a',
                'a.yml, App\File.loop_b: "Parent" leads back to the record itself: App\File.loop_b => App\File.loop_a => App\File.loop_b',
            ],
            $problems,
        );
    }

    public function testValuesPerLanguageNeedTheTranslationModule(): void
    {
        $problems = (new Validator($this->schemas(), false))->problems(
            [$this->record(self::TAG, 'red', locales: ['nl_NL' => ['Title' => 'Rood']])],
            new Lookups(null, [], [], []),
        );

        $this->assertSame(['a.yml, App\Tag.red: "@locales": translations need the translation module, tractorcow/silverstripe-fluent'], $problems);
    }

    public function testValuesPerLanguageAreTranslatedPlainValuesInAKnownLocale(): void
    {
        $problems = $this->problems(
            [
                $this->record(self::PAGE, 'home', locales: [
                    'nl_NL' => ['Sort' => 2, 'Unknown' => 'x', 'Title' => '=>' . self::TAG . '.red'],
                    'nl_NLL' => ['Title' => 'Thuis'],
                    'de_DE' => ['Title' => 'Heim'],
                    'fr_FR' => ['Content' => 'Accueil'],
                ]),
                $this->record(self::TAG, 'red', locales: ['nl_NL' => ['Title' => 'Rood']]),
                $this->record(self::LOCALE, 'german', ['Locale' => 'de_DE']),
            ],
            new Lookups(['nl_NL', 'fr_FR'], [], [], []),
        );

        $label = 'a.yml, App\Page.home: "@locales": ';
        $this->assertSame(
            [
                $label . '"Sort" is not translated (nl_NL)',
                $label . '"Unknown" is not translated (nl_NL)',
                $label . '"Title" cannot hold a reference (nl_NL)',
                $label . 'no Locale "nl_NLL" in this load or the database',
                'a.yml, App\Tag.red: "@locales": App\Tag is not translated',
            ],
            $problems,
        );
    }

    public function testASetWithoutValuesPerLanguageFilesOrSourcesNeedsNoLookup(): void
    {
        $request = $this->validator()->needs([
            $this->record(self::PLAIN, 'home', ['Title' => 'Home']),
            $this->record(self::FILE, 'below_a_new_folder', ['Name' => 'logo.png', 'Parent' => '=>' . self::FILE . '.root']),
            $this->record(self::FILE, 'nameless'),
            $this->record('Unknown\Kind', 'thing', ['Name' => 'x.txt']),
        ]);

        $this->assertSame([false, [], []], [$request->locales, $request->files, $request->sources]);
    }

    public function testTheLookupsNamedAreTheLocalesThePlacesOfPlainNamedFilesAndTheSources(): void
    {
        $records = [
            $this->record(self::PAGE, 'home', locales: ['nl_NL' => ['Title' => 'Thuis']]),
            $this->record(self::FILE, 'root', ['Name' => 'uploads']),
            $this->record(self::FILE, 'in_site_folder', ['Name' => 'logo.png', 'ParentID' => '7'], source: '/files/logo.png'),
            $this->record(self::TAG, 'red', source: '/files/notes.txt'),
        ];

        $request = $this->validator()->needs($records);

        $this->assertSame(
            [true, [['uploads', 0], ['logo.png', 7]], ['/files/logo.png']],
            [$request->locales, $request->files, $request->sources],
        );
        $this->assertFalse((new Validator($this->schemas(), false))->needs($records)->locales, 'without the translation module');
    }

    /**
     * @param list<FixtureRecord> $records
     *
     * @return list<string>
     */
    private function problems(array $records, ?Lookups $lookups = null): array
    {
        return $this->validator()->problems($records, $lookups ?? new Lookups(null, [], [], []));
    }

    private function validator(): Validator
    {
        return new Validator($this->schemas(), true);
    }

    /**
     * @return array<string, ClassSchema|null>
     */
    private function schemas(): array
    {
        $tags = ['join' => 'Plain_Tags', 'parentField' => 'PlainID', 'childField' => 'TagID', 'extraFields' => ['Weight' => 'Int']];

        return [
            'Unknown\Kind' => null,
            self::PLAIN => ClassSchemas::of(
                self::PLAIN,
                'Plain',
                fields: ['Title' => 'Varchar', 'Body' => 'Text', 'ParentID' => 'ForeignKey', 'TargetID' => 'ForeignKey', 'TitleLength' => 'Int'],
                kinds: [
                    'TitleLength' => FieldKind::Generated,
                    'Parent' => FieldKind::HasOne,
                    'Target' => FieldKind::HasOne,
                    'Children' => FieldKind::HasMany,
                    'Links' => FieldKind::ManyManyThrough,
                    'Tags' => FieldKind::ManyMany,
                    'Related' => FieldKind::ManyMany,
                ],
                joins: [
                    'Tags' => $tags,
                    'Related' => ['join' => 'Plain_Related', 'parentField' => 'PlainID', 'childField' => 'ChildID', 'extraFields' => []],
                ],
            ),
            self::TAG => ClassSchemas::of(
                self::TAG,
                'Tag',
                fields: ['Title' => 'Varchar'],
                kinds: ['Plains' => FieldKind::ManyMany],
                joins: ['Plains' => ['join' => 'Plain_Tags', 'parentField' => 'TagID', 'childField' => 'PlainID', 'extraFields' => ['Weight' => 'Int']]],
            ),
            self::FILE => ClassSchemas::of(
                self::FILE,
                'File',
                fields: ['Name' => 'Varchar', 'Title' => 'Varchar', 'ParentID' => 'ForeignKey', 'File' => 'DBFile', 'FileFilename' => 'Varchar', 'FileHash' => 'Varchar', 'FileVariant' => 'Varchar'],
                kinds: ['Parent' => FieldKind::HasOne],
                versioned: true,
                staged: true,
                isFile: true,
            ),
            self::PAGE => ClassSchemas::of(
                self::PAGE,
                'Page',
                fields: ['Title' => 'Varchar', 'Content' => 'HTMLText', 'Sort' => 'Int'],
                versioned: true,
                staged: true,
                localisedTables: ['Page' => ['Title', 'Content']],
            ),
            self::UNSTAGED => ClassSchemas::of(self::UNSTAGED, 'Unstaged', fields: ['Title' => 'Varchar'], versioned: true),
            self::LOCALE => ClassSchemas::of(self::LOCALE, 'Locale', fields: ['Locale' => 'Varchar'], isLocale: true),
        ];
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, array<string, mixed>> $locales
     * @param array<string, mixed> $draft
     */
    private function record(
        string $class,
        string $identifier,
        array $fields = [],
        ?bool $published = null,
        array $locales = [],
        ?string $source = null,
        array $draft = [],
    ): FixtureRecord {
        return new FixtureRecord('a.yml', $class, $identifier, $fields, $published, $locales, $source, $draft);
    }
}
