# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.2] - 2026-11-27

### Added

- `@draft` on a published versioned record: field values that differ on draft. The record loads as published and modified on draft, with the published version and the draft change as its two versions. Not allowed on a record that is not published, with `@locales`, for references and relations, or for a file's name, folder or stored file.

### Fixed

- A load or reset also clears Versioned's cache of whether an owner's owned records differ between stages.

## [0.1.1] - 2026-11-27

### Added

- `--dir` on the `fixtures` task (`dir[]` at `/dev/tasks/fixtures`): loads or resets every `.yml` and `.yaml` file directly in a directory, together with the `--file` files. A missing directory, or one without such a file, fails the task before anything is cleared or written.

## [0.1.0] - 2026-11-27

### Added

- `FixtureLoader`: loads fixture files in the framework's YAML shape straight into the database, in one transaction with multi-row inserts, writing the rows `DataObject::write()` would. Returns the loaded IDs by class and identifier (`LoadedFixtures`).
- `@published` for versioned records, per class in a file or per record: published or draft-only, with one version.
- `@locales` for classes Fluent translates: the record's values per locale, with live and version rows for classes with `FluentVersionedExtension`. A locale code must name a Locale of the load or the database.
- `@source` for `File`, `Image` and `Folder` records: places the file in the asset store, public when published and protected when draft-only. A relative source is relative to the fixture file that declares it.
- `FixtureLoader::reset()` and the `reset_classes` config: clears the records loaded since the last reset, recorded in the new `FixtureLedger` table, plus the records of the named classes, with the many_many join rows and join records that point at any of them.
- Loaded records, and join rows, are numbered above the table's auto-increment counter too, so an ID is never handed out twice, not even after a reset.
- `FixtureLoader::reload()`: resets and loads in one transaction, so an invalid file or a database error leaves the data as it was.
- The `fixtures` task (`sake tasks:fixtures`, `/dev/tasks/fixtures`): loads, unloads or resets fixture files without any code.
- `WeDevelop\Fixtures\Dev\FixtureTestState`: loads a SapphireTest's `$fixture_file` through the loader when set as the `fixtures` test state.
- `InvalidFixtureException` listing every problem of a fixture set, and `ResetRefusedException` outside the dev and test environments.
- `task benchmark`: compares loading and clearing with the framework's fixture handling, for the whole set in one load and per file.

[Unreleased]: https://github.com/wedevelopnl/silverstripe-fixtures/compare/0.1.2...HEAD
[0.1.2]: https://github.com/wedevelopnl/silverstripe-fixtures/compare/0.1.1...0.1.2
[0.1.1]: https://github.com/wedevelopnl/silverstripe-fixtures/compare/0.1.0...0.1.1
[0.1.0]: https://github.com/wedevelopnl/silverstripe-fixtures/releases/tag/0.1.0
