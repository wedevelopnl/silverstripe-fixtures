# Contributing to SilverStripe Fixtures

## Prerequisites

- Docker Desktop (or compatible): every PHP suite and check runs in containers
- [Task](https://taskfile.dev): `brew install go-task/tap/go-task`, or see the [installation options](https://taskfile.dev/installation). Run `task --list` for every target.

## Testbeds

The module is tested in two SilverStripe 6 apps (FrankenPHP with Caddy, MySQL 8), which mount this checkout as `vendor/wedevelopnl/silverstripe-fixtures`:

| Testbed | Start | Installs | Database |
|---------|-------|----------|----------|
| Base | `task up` | the framework alone (`silverstripe/recipe-core`) | `silverstripe` |
| Optional modules | `task up-modules` | the CMS (Versioned) and Fluent | `silverstripe_modules` |

The base testbed proves the module works without its optional integrations; the optional-modules testbed exercises them and owns the coverage gate.

`.docker/.env` is generated on first use with ports hashed from the checkout's directory name, so worktrees run side by side. `task up` prints the URL (`WEB_PORT`, `MODULES_WEB_PORT`); log in with `admin` / `admin`. MySQL listens on `127.0.0.1:<DB_PORT>`, user and password `silverstripe`.

> First boot takes a few minutes while Composer installs inside the container and the database builds.

`task down` stops both testbeds; `task destroy` also drops their volumes.

## Tests

| Command | Description |
|---------|-------------|
| `task test` | All PHP tests in the base testbed |
| `task test-unit` | Unit tests (no database or framework) |
| `task test-integration` | Integration tests (full SilverStripe environment) |
| `task test-modules` | All PHP tests in the optional-modules testbed |
| `task coverage` | All tests with coverage, HTML and Clover in `coverage/` |
| `task coverage-check` | Fail below 90% line coverage |
| `task benchmark` | Compare loading and clearing with the framework's fixture handling; report in `coverage/benchmark.md` |

Tests live under `tests/Unit` and `tests/Integration`, namespace `WeDevelop\Fixtures\Tests`; the benchmark under `tests/Benchmark` runs only through `task benchmark`.

The harness project enables the package's test state (`.docker/app/_config/fixtures.yml`), so every test class with a `$fixture_file` loads it through the loader, as in a consuming project.

Test models that need Versioned get it from the test that lists them (`$required_extensions`), never from their own `$extensions`: the base testbed resolves the config of every model while building its database, and Versioned is not installed there.

## Static analysis

| Command | Description |
|---------|-------------|
| `task analyse` | PHPStan at level max over the 8.3–8.5 range, with 100% type coverage |
| `task analyse-php85` | PHPStan with the analysis target pinned to PHP 8.5 |
| `task rector-dry` | Rector in dry-run mode; `task rector` applies it |
| `task class-leak` | Classes in `src/` that nothing references |
| `task qa` | Every check above plus both test runs, in parallel |

The analysers run in the optional-modules testbed: `src/` uses Versioned and Fluent behind installed-checks, and they need those classes to resolve. The tool configuration lives in `.docker/app/` and is baked into the image: after changing it, rebuild with `task build`.

## Harness dependencies

The testbeds install from committed locks (`.docker/app/composer.lock`, `.docker/app/composer.modules.lock`), so a container boot never re-resolves against Packagist. After changing `require` in `composer.json`, run `task relock` and commit both locks; `task verify-lock` (also in CI) fails when they no longer cover the module. The locks freeze the SilverStripe 6.x release the testbeds run; `task relock` also moves them to the latest one.

## Continuous integration

GitHub Actions (`.github/workflows/ci.yml`) runs static analysis once, the PHP suites on PHP 8.3, 8.4 and 8.5 in both testbeds, and the benchmark, whose report it adds to the job summary and uploads as an artifact. The benchmark has no threshold and never fails on timings.
