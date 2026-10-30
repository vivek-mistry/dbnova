# Testing Strategy

Read the Boost `testing-best-practices` skill (`.cursor/skills/`) before writing tests.

## Layers

| Layer                          | Location                                              | Touches MySQL?                              | Runs in CI                            |
| ------------------------------ | ----------------------------------------------------- | ------------------------------------------- | ------------------------------------- |
| Unit                           | `tests/Unit/Database`, `tests/Unit/Import`            | No                                          | Always                                |
| Feature (HTTP, mocked adapter) | `tests/Feature/{Database,Tables,ImportExport,System}` | No — bind a fake `DatabaseAdapterInterface` | Always                                |
| Adapter integration            | `tests/Feature/Database/MySQL`                        | Yes — real server, `dbnova_test_*` schemas  | When `DBNOVA_TEST_DB_USERNAME` is set |
| Contract suite                 | `tests/Feature/Database/Contracts`                    | Yes — runs against each adapter             | Same as above                         |
| Stress / performance           | `tests/Stress` (excluded from default suite)          | Yes                                         | Manual / nightly                      |

## Key unit targets

- `IdentifierQuoter`: backticks, NUL, length, unicode.
- `DefaultsFile`: content, 0600 permissions, deletion.
- `SqlStreamScanner`: strings with `;`, escaped quotes, comments, conditional comments, `DELIMITER $$`, statements split across chunk boundaries, `USE`/`CREATE DATABASE` detection.
- `SourceStream`: sql, gz and zip (including rejecting traversal and multiple entries).
- Pagination SQL builders (keyset vs offset).

## Integration fixtures

- `mySqlTestCredentials()` (tests/Pest.php) reads `DBNOVA_TEST_DB_USERNAME` / `DBNOVA_TEST_DB_PASSWORD` from `.env`, and the integration tests skip when it's unset. Each test creates `dbnova_test_<random>` and drops it in `afterEach`.
- Local setup: a dedicated MySQL user that can only touch `dbnova\_test\_%` databases (SQL below). Never use real credentials in committed files.

```sql
CREATE USER 'dbnova_test'@'localhost' IDENTIFIED BY '<choose a password>';
GRANT ALL PRIVILEGES ON `dbnova\_test\_%`.* TO 'dbnova_test'@'localhost';
```

- Small dump fixtures in `tests/Fixtures/dumps/`: single-db, with-use, multi-db (rejected), invalid-sql, gz, zip.
- Process tests use `Process::fake()` where possible, and real binaries only in the integration layer.

## Stress fixtures (Phase 9, used earlier where needed)

- `php artisan dbnova:stress:seed-table --rows=10000000` creates a wide table with mixed types.
- `php artisan dbnova:stress:make-dump --size=10G` writes a synthetic dump to disk as a stream.
- Record results in `PERFORMANCE.md`.

## Commands

- One file: `php artisan test --compact tests/Unit/Database/IdentifierQuoterTest.php`
- With MySQL (after setting the test credentials in `.env`): `php artisan test --compact tests/Feature/Database/MySQL`
- Everything (pre-phase-close): `composer ci:check`

## CI (`.github/workflows/tests.yml`)

Since Phase 2, the workflow runs a `mysql:8.0` service container and sets `DBNOVA_TEST_DB_USERNAME`/`DBNOVA_TEST_DB_PASSWORD` to its throwaway root account, so the integration tests run on every push.
