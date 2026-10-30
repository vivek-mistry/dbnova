# Security

DBNova is effectively a superuser console for a MySQL server. Its threat model assumes anyone who gets into the app can do anything the configured MySQL user can do.

## Authentication & access

- Users sign in with a **MySQL username and password** (ADR 0008), which DBNova checks by connecting to the configured server. MySQL grants decide what they can do.
- There are no app accounts. Registration, password reset, email verification, 2FA and passkeys are removed. Fortify login throttling (5/min per username + IP) is kept.
- "Remember me" is not offered, because a remembered session would have no MySQL credentials.
- All app routes sit behind `auth` + `verified` middleware. A feature test asserts that every non-auth route requires auth.
- Session lifetime is configurable.
- Dropping a database requires typing its exact name **and** re-entering the MySQL password in the same dialog (`DropDatabaseRequest` checks the password against MySQL). The import "drop & recreate" action follows the same pattern. Fortify's `password.confirm` page is also MySQL-backed, for flows that redirect.

## MySQL credentials

- `.env` holds only **where** the server is (`DBNOVA_DB_HOST/PORT/SOCKET`), never a username or password.
- The signed-in user's credentials live in their session, encrypted with `Crypt` (`MySQLCredentialStore`), and the session is encrypted too (`SESSION_ENCRYPT=true`). They're discarded on logout and session expiry. `EnsureMySQLCredentials` signs out sessions that lack them.
- Queued jobs (Phases 6 and 7) store the dispatching user's credentials encrypted on the job row and wipe them when the job reaches a terminal state.
- `users` rows never contain a password.
- They're given to the CLI only through a temporary `--defaults-extra-file` (mode 0600, under `storage/app/dbnova/tmp`, deleted in `finally`). They're **never** put in argv (visible in `ps`) and never in `MYSQL_PWD` (deprecated).
- They're never logged, never serialized into job payloads (jobs hold only IDs), and never sent to the frontend.
- Recommended setup: a dedicated MySQL user with the required grants (see INSTALL.md) rather than `root`.

## Injection

- **SQL values** are always bound parameters through PDO with native prepares.
- **SQL identifiers** go through `IdentifierQuoter` only: backtick-quoted, backticks doubled, NUL rejected, length ≤ 64. Where possible, identifiers are checked against the actual catalog (`information_schema`) first.
- **Shell:** arrays only for Symfony Process. No `fromShellCommandline`, `exec` or `shell_exec`. Binary paths come from config and are validated as executable files.
- **The SQL editor** runs arbitrary SQL by design. It's protected by auth, the audit log, read-only mode and CSRF.

## Uploads & files

- Uploads are stored under UUID paths, and the original filename is metadata only.
- ZIP: entries with `..`, absolute paths or symlinks are rejected. Exactly one `.sql` entry is allowed. A zip-bomb guard aborts if the uncompressed bytes exceed a configured ratio or the free disk space.
- Export downloads use temporary signed URLs and expire.

## Web

- CSRF on all state-changing requests (Laravel default, including chunk PUTs).
- Rate limits on the login, upload-chunk and query-execute endpoints.
- No user-controlled HTML is rendered. Grid cells are rendered as text.

## Read-only mode

`DBNOVA_READ_ONLY=true` makes `ReadOnlyGuard` throw on every mutating adapter method. The SQL editor only allows statements whose first keyword is in a whitelist (SELECT, SHOW, DESCRIBE, EXPLAIN), determined by `SqlStreamScanner`, and the session runs `SET SESSION TRANSACTION READ ONLY` as defence in depth.

## Audit log

`audit_logs` (SQLite) records the actor, action, database, table, SQL (truncated), result and IP. It covers every DDL, every DML from the grid or editor, imports, exports and drops.

## Review checklist

See the `.claude/skills/dbnova-security-review/SKILL.md` skill, which runs at the end of every phase.
