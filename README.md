# SilverStripe Fixtures

A test data loader for SilverStripe 6 that puts a whole fixture set in place in one go, instead of saving record after record through the ORM.

The loader reads fixture files in the framework's YAML shape, checks them against the schema, and inserts every table's rows with multi-row inserts inside one transaction. The rows are the rows `DataObject::write()` would have written for the same values. Clearing removes exactly what was loaded since the last clear, plus the kinds of records your project names.

> **Status: under development.** This package has no release yet.

## Requirements

- PHP ^8.3
- `silverstripe/framework` ^6.0
- MySQL 8 (tested) or MariaDB (untested)

Optional, for the features that need them:

- `silverstripe/versioned`: published and draft-only records.
- `tractorcow/silverstripe-fluent` ^8: values per language.

A fixture that uses none of these features loads without them.

## Installation

```sh
composer require --dev wedevelopnl/silverstripe-fixtures
```

Run `dev/build` once: the package adds a `FixtureLedger` table that records what each load inserted.

## Writing fixtures

A fixture file maps a class to its records by identifier, as the framework's fixtures do:

```yaml
Page:
  '@published': true
  home:
    Title: Home
    URLSegment: home
    Sort: 1
  about:
    Title: About us
    URLSegment: about-us
    Sort: 2
    Parent: =>Page.home
    '@published': false
```

- Any key is a database field or a relation of the class. Keys starting with `@` belong to the loader.
- **State every value.** The loader adds only the class, the creation and edit time, and the defaults the class declares in `$defaults`. Nothing computed in `onBeforeWrite()`, `onAfterPopulateDefaults()` or another hook is filled in, so a page needs its `URLSegment` and `Sort` written out.
- `ID` is not settable; the loader numbers the records above the highest ID the table, its live and version tables hold and above the table's auto-increment counter, so no ID is handed out twice, not even after a reset. Refer to other records by name.
- References resolve across all files of one load. The same class and identifier twice in one load is refused, and so is an identifier with whitespace, which no reference could name.

### Relations

| Relation | How to write it |
|---|---|
| has_one | On the record that holds it: `Parent: =>Page.home` fills `ParentID`. A polymorphic has_one also fills `ParentClass`. |
| has_many | Not settable from the parent: set the has_one on each child. |
| many_many | A list of references on the declaring side: `Tags: [=>Tag.red, =>Tag.blue]`. An item with extra fields is a map: `{ ref: =>Tag.blue, Weight: 3 }`. The framework's comma-separated string (`=>Tag.red,=>Tag.blue`) refuses the load. A belongs_many_many takes the same list. A pair stated twice, in one list or from both sides, is one join row, as `write()` keeps it; stated twice with different extra field values it refuses the load. |
| many_many through | Declare records of the join class, with their two has_ones. |

A reference is only resolved on a has_one or many_many. A column that holds one, such as the framework's `ParentID: =>Page.home`, or a value under `@locales` refuses the load: write `Parent: =>Page.home`.

### Published and draft-only records

On a versioned class, `'@published': true` loads the record as published and `false` as draft-only. Directly under the class it is the default for that class's records in that file; a record's own `@published` overrides it.

A loaded record has exactly one version: a published record is on draft and live and not modified, a draft-only record is on draft alone. Without `@published` a record is draft-only, as a plain `write()` leaves it. `@published` on a class that is not versioned, or `true` on a class versioned without stages, refuses the load.

### Values per language

On a class Fluent translates, `@locales` maps a locale code to the record's translated values:

```yaml
Page:
  home:
    Title: Home
    URLSegment: home
    Sort: 1
    '@published': true
    '@locales':
      en_US: {}
      nl_NL: { Title: Thuis }
```

- The record exists in each locale it names, and only those. A locale without values (`en_US: {}`) takes the record's own values.
- A translated field the locale leaves out takes the record's value; fields Fluent does not translate are shared by every locale.
- `@published` applies to every stated locale.
- Only translated fields are allowed. `@locales` on a class Fluent does not translate, or without Fluent installed, refuses the load.
- Each locale code must name a `TractorCow\Fluent\Model\Locale`, spelled exactly as its `Locale` field: load those records as fixture data too, in the same load or an earlier one, or have them in the database. An unknown code refuses the load.
- Live and version rows per locale are written only for a class with `FluentVersionedExtension`, as Fluent does; a versioned class with the plain `FluentExtension` gets its `_Localised` rows only.

### Files

A `File`, `Image` or `Folder` is a record like any other. `@source` names the file to put in the asset store for it:

```yaml
SilverStripe\Assets\Folder:
  fixture_files:
    Name: fixture-files
    Title: fixture-files
    '@published': true
SilverStripe\Assets\Image:
  logo:
    Name: logo.png
    Title: Logo
    Parent: =>SilverStripe\Assets\Folder.fixture_files
    '@source': '../files/logo.png'
    '@published': true
```

- The file goes through the asset store's own API under the record's folder path and `Name`; the record gets its hash and filename. `Name` is required with `@source`.
- A published file is public, a draft-only file protected. Without Versioned a file has no stages and is public. A file an earlier load published and the fixture now declares draft-only is protected again.
- Give the test data folders of its own: a file or folder at a name the site already has refuses the load, and so do two records of one load at the same name in the same folder, and a `Parent` chain that leads back to a record.
- `@source` on a class that is not a `File` refuses the load. Reset deletes the records, never the files on disk.

### Paths

- Fixture files: an absolute path or one relative to the project root, as the framework's `YamlFixture` takes them. In a `SapphireTest`, `$fixture_file` may also be relative to the test class, as the framework resolves it before the loader sees it.
- `@source`: an absolute path or one relative to the directory of the fixture file that declares it. A module's fixtures therefore name its own files wherever the module is installed.
- A module resource (`vendor/module:path`) is not a path the loader takes: a project's tests load their own fixtures, not another module's.

## Using it in SapphireTest

Switch the loader on in your project's test configuration:

```yaml
---
Name: app-fixtures
After: sapphiretest
---
SilverStripe\Core\Injector\Injector:
  SilverStripe\Dev\State\SapphireTestState:
    properties:
      States:
        fixtures: '%$WeDevelop\Fixtures\Dev\FixtureTestState'
```

Test classes stay as they are: they name their `$fixture_file` (one file or a list, loaded together) and look records up with `objFromFixture()`, `idFromFixture()` and `allFixtureIDs()`. Each test class loads its fixtures once and every test rolls back to them. When the class is done, the loader's clear runs instead of emptying every table. Records a class writes outside its tests, such as the default records of `$requireDefaultRecordsFrom` or writes in `onBeforeLoadFixtures()`, outlive it unless their class is in `reset_classes`.

Remove the entry to return to the framework's own fixture handling.

## Using it outside PHPUnit

The loader is an Injector service, for a browser test module, a build task or a controller:

```php
use WeDevelop\Fixtures\FixtureLoader;

$loader = FixtureLoader::singleton();

$loaded = $loader->load('app/tests/fixtures/pages.yml', 'app/tests/fixtures/files.yml');
$homeId = $loaded->idOf('Page', 'home');
$all = $loaded->toArray(); // ['Page' => ['home' => 12, 'about' => 13], ...]

$loader->reset();
```

- `load(string ...$paths): LoadedFixtures` writes all records or none. Inside an open transaction, such as a test's, it runs as a savepoint and is undone with it.
- `LoadedFixtures::toArray()` is keyed by the class as written in the fixture and keeps declaration order: files in argument order, classes by first appearance, identifiers in file order.
- `InvalidFixtureException` (an `InvalidArgumentException`) lists every problem with its file, record and field; nothing is written then. `getProblems()` returns the list.
- `reset()` throws `ResetRefusedException` (a `RuntimeException`) outside the dev and test environments.
- Database errors propagate unchanged, and the load is rolled back.

The ledger lives in the database, so a load in one process and a reset in another (a CLI task and an HTTP request) work together.

## Clearing test data

`reset()` deletes every record loaded since the last reset, from all its tables: the class tables and their live, version and localised tables. It never empties whole tables, so records of a loaded class that the load did not write stay. It also deletes the many_many join rows, and the records of a many_many through join class, that point at a deleted record on either side, wherever they came from: a test that links a loaded record to one of the site's leaves no row behind.

Records your tests create, through the CMS or the ORM, are cleared only when you name their class:

```yaml
WeDevelop\Fixtures\FixtureLoader:
  reset_classes:
    - Page
```

A named class is cleared with its subclasses, selected by `ClassName`: naming `Page` leaves plain `SiteTree` records alone. Reset does not follow `cascade_deletes`; name the classes a cascade would reach. Reset never removes files from disk.

## Limits

- One version per record: no edit history, nothing modified after publishing, nothing unpublished after publishing.
- Values computed by hooks are not filled in; fixtures state them.
- `ChangeSet` and `ChangeSetItem` rows are not written; declare them as fixture records when a test needs them.
- MySQL and MariaDB only, and only MySQL 8 is tested: the test harness and CI run MySQL 8. On MariaDB, numbering above the auto-increment counter relies on its `information_schema` reporting the counter live.

## Benchmark

`task benchmark` loads and clears the same fixture set (30 files, 360 versioned records in a page, section, row, column and content tree) with the framework's handling and with this loader, and prints wall time and the number of statements MySQL executed for each step. It measures two ways, each labelled in the report:

- **Whole set**: all 30 files in one load, then one clear. This is what loading many files together saves, as a browser test that loads its whole set does.
- **Per file**: one load and one clear for each file, summed over the 30 files. This is what a suite of test classes with one fixture file each costs: the loader cannot batch across classes, and every class pays its own clear.

Both also compare the loader's `DELETE`-based clear with truncating the loaded tables. The report is written to `coverage/benchmark.md`; CI publishes it on every run without a threshold.

## Development

See [CONTRIBUTING.md](CONTRIBUTING.md) for running the module locally, the test suites and the QA gates.

## License

BSD 3-Clause, see [LICENSE](LICENSE).
