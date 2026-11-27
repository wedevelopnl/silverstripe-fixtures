---
description: Task (Taskfile) commands for the Docker testbeds, tests and QA
applyTo: "**/*"
---

# Commands

All PHP commands run in Docker through [Task](https://taskfile.dev); `task --list` shows every target. Two testbeds: `app` (framework only) and `app-modules` (CMS + Fluent, `--profile modules`).

| Command | Description |
|---------|-------------|
| `task up` / `task up-modules` | Start the base / optional-modules testbed |
| `task down` / `task destroy` | Stop both testbeds / also drop their volumes |
| `task build` | Rebuild both images — required after editing anything in `.docker/app/` |
| `task test` | All PHP tests in the base testbed |
| `task test-unit` / `task test-integration` | One suite in the base testbed |
| `task test-modules` | All PHP tests in the optional-modules testbed |
| `task coverage` / `task coverage-check` | Coverage in the optional-modules testbed / fail below 90% |
| `task benchmark` | Benchmark against the framework's fixture handling (modules testbed); report in `coverage/benchmark.md` |
| `task analyse` / `task analyse-php85` | PHPStan (8.3–8.5 range / target pinned to 8.5), in the modules testbed |
| `task rector-dry` / `task rector` | Rector preview / apply |
| `task class-leak` | Unreferenced classes in `src/` |
| `task qa` | All checks and both test runs in parallel |
| `task relock` | Regenerate both harness locks — run after changing `require` in `composer.json` |
| `task verify-lock` | Fail if either committed lock (base, modules) no longer covers the module |
| `task db-build` / `task flush` | Rebuild the database / clear the cache in the base testbed |

- Run single tests with `docker compose -f .docker/compose.yml exec -T app vendor/bin/phpunit --filter <name>`.
- Container paths: the module is `/module`, the harness app `/app`; the module is installed at `/app/vendor/wedevelopnl/silverstripe-fixtures`.
- Analysers, Rector and class-leak run in the modules testbed (`app-modules`) — `src/` references Versioned/Fluent classes the base testbed lacks.
