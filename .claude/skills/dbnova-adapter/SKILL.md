---
name: dbnova-adapter
description: Patterns for adding or changing managed-database operations in DBNova (app/Database — contracts, MySQL adapter, PDO, ProcessRunner, DTOs). Use whenever code needs to read from or write to the managed MySQL server.
---

# DBNova Adapter Development

Read `.claude/docs/DATABASE_ADAPTERS.md` first.

## Adding an operation — always in this order

1. **Contract:** add the method to the right interface in `app/Database/Contracts/` with full PHPDoc (array shapes) and return a DTO, never a raw array of PDO rows.
2. **DTO:** a `final readonly class` in `app/Database/DTOs/` with constructor promotion.
3. **MySQL implementation** in `app/Database/Adapters/MySQL/`:
    - Get PDO from `MySQLConnection`. **Never** use `DB::`, Eloquent or Query Builder.
    - Values go through `prepare()` + bound params. Identifiers go only through `IdentifierQuoter::quote()`.
    - Mutating methods call `ReadOnlyGuard::assertWritable()` first.
    - Wrap `PDOException` in `DatabaseOperationException`.
    - Large results: `MYSQL_ATTR_USE_BUFFERED_QUERY=false` + generators (`yield`).
4. **CLI work** (import/export only): `ProcessRunner` with an **array** command. The first argument after the binary is `--defaults-extra-file=` from `DefaultsFile`. Never put a password in argv.
5. **Capabilities:** if the operation isn't universal across engines, add a flag to `Capabilities`.
6. **Service/controller** depend on the interface (constructor injection), and record mutations through `AuditLogger`.
7. **Tests:**
    - Unit tests for any SQL builder.
    - Integration tests in `tests/Feature/Database/MySQL` using `withTestDatabase()` (guarded by `DBNOVA_TEST_MYSQL`).
    - Feature tests bind a fake adapter.

## Snippets

```php
$statement = $this->connection->pdo()->prepare(
    'SELECT TABLE_NAME, TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?'
);
$statement->execute([$database]);
```

```php
$sql = sprintf('DROP TABLE %s.%s', $this->quoter->quote($database), $this->quoter->quote($table));
```

## Red flags (reject in review)

- String concatenation of user input into SQL without the quoter.
- `Process::fromShellCommandline`, `exec`, `shell_exec`, or a password in a Process argument.
- `fetchAll()` on unbounded queries.
- Controllers type-hinting `MySQL*` classes.
