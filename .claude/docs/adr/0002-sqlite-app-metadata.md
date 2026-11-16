# ADR 0002 — SQLite for application metadata

**Status:** Accepted (2026-10-30)

## Context

DBNova needs storage for users, sessions, cache, queue jobs, uploads, import/export jobs, query history and the audit log. The managed MySQL server may be broken, restarted, or in the middle of a re-import.

## Decision

Use the starter kit's default SQLite database (`database/database.sqlite`) through normal Laravel Eloquent.

## Alternatives

- **A schema on the managed MySQL server.** It couples the app to the server it manages, an import could overwrite it, and the app breaks when MySQL is down. Rejected.
- **A separate MySQL/PostgreSQL instance.** Extra setup for a self-hosted single-operator tool. Rejected for V1.

## Consequences

- Zero extra setup. Backing up the app means backing up one file.
- SQLite write concurrency is enough for a few signed-in MySQL users plus one worker. Enable WAL mode (Phase 1) so the worker's progress writes don't block web reads.
