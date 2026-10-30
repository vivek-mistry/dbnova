# ADR 0006 — Token-aware streaming import with single-database enforcement

**Status:** Accepted (2026-10-30)

## Context
Dumps may contain `CREATE DATABASE` and `USE` statements, which would redirect data away from the database the user selected. Files can be 10 GB+.

## Decision
- A streaming `SqlStreamScanner` tokenizer (aware of quotes, comments, conditional comments and `DELIMITER`) runs during **preflight** (full streaming pass) and **import** (as a filter).
- 0 database references → OK. 1 distinct name → `CREATE DATABASE`/`USE`/`DROP DATABASE` for that name are dropped at statement level, and `--database=<target>` pins the session. ≥ 2 names → rejected.
- The UI reports **bytes sent to MySQL**, never "% committed". There's no rollback claim. Partial imports are disclosed and the user is offered drop & recreate.

## Alternatives
- **Regex rewriting.** Breaks on `;` or `USE` inside strings or comments and on custom delimiters. Rejected (the spec forbids it too).
- **A single transaction for rollback.** MySQL DDL causes implicit commits, so atomicity is impossible for typical dumps. Rejected as a promise.
- **Restore into a temp DB, then swap.** True safety, but needs 2× space and a rename of every table. Considered for a post-V1 "safe import" option.

## Consequences
- The scanner needs thorough unit tests (chunk-boundary splits, escapes).
- The preflight pass doubles I/O reads for very large files. That's acceptable, and it's shown as a separate step.
