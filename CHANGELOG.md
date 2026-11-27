# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `FixtureLoader`: loads fixture files in the framework's YAML shape straight into the database, in one transaction with multi-row inserts, writing the rows `DataObject::write()` would. Returns the loaded IDs by class and identifier (`LoadedFixtures`).
- `@published` for versioned records, per class in a file or per record: published or draft-only, with one version.
- `@locales` for classes Fluent translates: the record's values per locale, with live and version rows for classes with `FluentVersionedExtension`. A locale code must name a Locale of the load or the database.
- `@source` for `File`, `Image` and `Folder` records: places the file in the asset store, public when published and protected when draft-only.
- `FixtureLoader::reset()` and the `reset_classes` config: clears the records loaded since the last reset, recorded in the new `FixtureLedger` table, plus the records of the named classes, with the many_many join rows and join records that point at any of them.
- Loaded records, and join rows, are numbered above the table's auto-increment counter too, so an ID is never handed out twice, not even after a reset.
- `WeDevelop\Fixtures\Dev\FixtureTestState`: loads a SapphireTest's `$fixture_file` through the loader when set as the `fixtures` test state.
- `InvalidFixtureException` listing every problem of a fixture set, and `ResetRefusedException` outside the dev and test environments.
- `task benchmark`: compares loading and clearing with the framework's fixture handling, for the whole set in one load and per file.
