# Testing Strategy

Read the Boost `testing-best-practices` skill (`.cursor/skills/`) before writing tests.

## Layers

| Layer                          | Location                                              | Touches MySQL?                              | Runs in CI                 |
| ------------------------------ | ----------------------------------------------------- | ------------------------------------------- | -------------------------- |
| Unit                           | `tests/Unit/Database`, `tests/Unit/Import`            | No                                          | Always                     |
| Feature (HTTP, mocked adapter) | `tests/Feature/{Database,Tables,ImportExport,System}` | No — bind a fake `DatabaseAdapterInterface` | Always                     |
| Adapter integration            | `tests/Feature/Database/MySQL`                        | Yes — real server, `dbnova_test_*` schemas  | When `DBNOVA_TEST_MYSQL=1` |
| Contract suite                 | `tests/Feature/Database/Contracts`                    | Yes — runs against each adapter             | Same as above              |
| Stress / performance           | `tests/Stress` (excluded from default suite)          | Yes                                         | Manual / nightly           |

## Key unit targets

- `IdentifierQuoter`: backticks, NUL, length, unicode.
- `DefaultsFile`: content, 0600 permissions, deletion.
- `SqlStreamScanner`: strings with `;`, escaped quotes, comments, conditional comments, `DELIMITER $$`, statements split across chunk boundaries, `USE`/`CREATE DATABASE` detection.
- `SourceStream`: sql, gz and zip (including rejecting traversal and multiple entries).
- Pagination SQL builders (keyset vs offset).

## Integration fixtures

- A helper `withTestDatabase(fn)` creates `dbnova_test_<random>` and drops it in `afterEach`.
- Small dump fixtures in `tests/Fixtures/dumps/`: single-db, with-use, multi-db (rejected), invalid-sql, gz, zip.
- Process tests use `Process::fake()` where possible, and real binaries only in the integration layer.

## Stress fixtures (Phase 9, used earlier where needed)

- `php artisan dbnova:stress:seed-table --rows=10000000` creates a wide table with mixed types.
- `php artisan dbnova:stress:make-dump --size=10G` writes a synthetic dump to disk as a stream.
- Record results in `PERFORMANCE.md`.

## Commands

- One file: `php artisan test --compact tests/Unit/Database/IdentifierQuoterTest.php`
- With MySQL: `DBNOVA_TEST_MYSQL=1 php artisan test --compact tests/Feature/Database/MySQL`
- Everything (pre-phase-close): `composer ci:check`

## CI (`.github/workflows/tests.yml`)

Phase 2 adds a `mysql:8` service container and sets `DBNOVA_TEST_MYSQL=1`, plus the binaries for the integration job.
