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
- Option files are read line by line, so a value containing CR, LF or NUL would start a new option. For example, the password `x⏎result-file=/path` makes mysqldump write over any file the worker can write. Quoting can't stop this. `DefaultsFile` therefore **refuses** such usernames, passwords, hosts and socket paths, without echoing the value, and login refuses them too. Any MySQL user can set such a password for themselves, so this is the real control. (Security review 7a, HIGH-1, verified against mysqldump 8.0.39.)
- They're never logged, never serialized into job payloads (jobs hold only IDs), and never sent to the frontend.
- Recommended setup: a dedicated MySQL user with the required grants (see INSTALL.md) rather than `root`.

## Injection

- **SQL values** are always bound parameters through PDO with native prepares.
- **SQL identifiers** go through `IdentifierQuoter` only: backtick-quoted, backticks doubled, NUL rejected, length ≤ 64. Where possible, identifiers are checked against the actual catalog (`information_schema`) first.
- **Shell:** arrays only for Symfony Process. No `fromShellCommandline`, `exec` or `shell_exec`. Binary paths come from config and are validated as executable files.
- **The SQL editor** runs arbitrary SQL by design. It's protected by auth, the audit log, read-only mode and CSRF.

## Uploads & files

- Uploads are stored under UUID paths, and the original filename is metadata only.
- Uploads (Phase 6a) are owned by the MySQL account that started them. Another user's upload or import returns 404. Files are mode 0600 in 0700 directories. Chunk PUTs are rate-limited per user, and a chunk of the wrong length is refused and not recorded.
- ZIP: entries with `..` segments, absolute or drive paths, NUL bytes, symlinks or encryption are rejected. Exactly one file (a `.sql`) is allowed; `__MACOSX/` and `._*` metadata are ignored. Nothing is ever extracted to disk: the entry is streamed, so a zip bomb can't fill the disk, and reads are bounded per call. Gzip is inflated in 8 KB compressed steps, so one step can't explode memory.
- The `mysql` client that runs imports (6b) always gets `--binary-mode` (client commands such as `\!` shell and `source` are disabled in piped input) and `--local-infile=0` (a dump can't read files on the DBNova host). Dump files are untrusted input.
- **The "one database" rule is a best-effort guard, not a security boundary.** The real boundary is the MySQL account's grants: import with an account limited to the target database when the dump is untrusted. The guard (`MySQLDumpPolicy`, run on every statement in preflight) does three things:
    - Accepts only the statement kinds that dump tools write: SET, INSERT/REPLACE, CREATE/ALTER/DROP of tables, views, routines, triggers, events and indexes, LOCK/UNLOCK, TRUNCATE, START TRANSACTION/COMMIT, and the database statements it skips. UPDATE, DELETE, SELECT, CALL, RENAME, LOAD DATA, GRANT and the rest are refused; they belong in the SQL editor.
    - Refuses database-qualified names in every table-name position (including DROP/LOCK lists, `CREATE INDEX … ON`, `CREATE TRIGGER … ON`), plus `ALTER TABLE … RENAME/EXCHANGE`.
    - Refuses text the `mysql` client would split differently from DBNova's scanner: a backslash outside strings (client commands such as `\C`), the delimiter inside `/*! … */`, `delimiter` at the start of a line in the middle of a statement, `NO_BACKSLASH_ESCAPES`, and big5/cp932/gb18030/gbk/sjis charsets. The scanner follows the client's comment rule (`--` + whitespace only); this was checked against the real 8.0.39 client.
    - Routine, trigger and view **bodies** are not inspected (they run later, with the definer's or caller's rights).
    - The `mysql` client always enables multi-statements, so under `DELIMITER ;;` a `;` inside one client statement makes MySQL run several. A `;` in code under a non-`;` delimiter is **refused**, except in CREATE PROCEDURE/FUNCTION/TRIGGER/EVENT. Those "compound programs" can't be checked (anything after the body's END also runs), so preflight counts them and **Start requires the user to confirm they trust the dump**; without it the pump refuses them. (Security review 6b, F1.)
    - `DELIMITER` arguments must be 1–16 characters with no quotes, backslash or whitespace (the client strips quotes and refuses backslashes, so the split would differ). Whitespace before a multi-character delimiter is kept when re-emitting. (F2, F3.)
- Import credentials: stored encrypted on the import at Start, read by the worker and **wiped as soon as the `mysql` client has them**. A queued import that no worker starts within `session.lifetime` is failed and wiped by `dbnova:recover-jobs`. The temporary `.cnf` is deleted in `finally`; one left by a SIGKILLed worker is deleted by `dbnova:prune-temp` after 1 h.
- "Drop & recreate target" requires the typed name and the MySQL password, is throttled (5/min; so is dropping a database), and is refused while another import into that database is queued or running, or while MySQL is still running statements there (`busySessionCount`, e.g. a `mysql` client orphaned by a killed worker).
- Security review 2026-11-13 (Phase 6a) found the original leading-token check bypassable (`RENAME TABLE a TO other.a`, `DROP TABLE a, other.b`, `--\x01;USE other`); the policy above replaced it.
- Exports (7a):
    - `mysqldump` gets database and table names only after `--`, and only after they have been checked against the catalog.
    - Credentials go on the export row encrypted and are wiped as soon as the worker has read them.
    - Files use UUID paths (mode 0600, opened with `x`), and a failed, cancelled or interrupted export deletes its partial file.
    - Downloads need a temporary signed URL (≤ `export_link_minutes`, never past the file's expiry) **and** the signed-in owner. The response is `Cache-Control: no-store`, and the download filename keeps only `[A-Za-z0-9_.-]`.
    - Read-only mode allows exports.
    - The `mysql` system database can be exported, because what a user can dump is decided by their MySQL grants.

## Web

- CSRF on all state-changing requests (Laravel default, including chunk PUTs).
- Rate limits on the login, upload-chunk and query-execute endpoints.
- No user-controlled HTML is rendered. Grid cells are rendered as text.

## Read-only mode

`DBNOVA_READ_ONLY=true` makes `ReadOnlyGuard` throw on every mutating adapter method. The SQL editor only allows statements whose first keyword is in a whitelist (SELECT, SHOW, DESCRIBE, EXPLAIN), determined by `SqlStreamScanner`, and the session runs `SET SESSION TRANSACTION READ ONLY` as defence in depth.

## Audit log

`audit_logs` (SQLite) records the actor (user + MySQL username), action, database, table, details, result (with the error) and IP. It covers every DDL, every row change from the grid, imports, exports and drops. **Row values are never stored**: row changes record column names and row counts only, because table data may be sensitive.

## Review checklist

See the `.claude/skills/dbnova-security-review/SKILL.md` skill, which runs at the end of every phase.
