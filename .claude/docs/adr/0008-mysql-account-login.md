# ADR 0008 — Sign in with a MySQL account

**Status:** Accepted (2026-10-30). Supersedes [ADR 0004](0004-single-admin.md).

## Context

ADR 0004 gave DBNova its own single admin account, with the MySQL credentials kept in `.env`. The user wants phpMyAdmin-style login instead: the login form takes a **MySQL username and password**, and DBNova acts as that MySQL user.

## Decision

- **Login:** Fortify's login screen and throttling are kept, but `Fortify::authenticateUsing` calls `AuthenticateMySQLUser`. That action opens a PDO connection through `MySQLConnector` with the submitted credentials:
    - The server accepts the connection → the user is signed in.
    - Error 1045/1698 (access denied) → Fortify's usual "credentials do not match" error.
    - Any other error (server down) → a validation error naming the server.
- **Host:** the host, port and socket are fixed in `.env` (`DBNOVA_DB_HOST`, `DBNOVA_DB_PORT`, `DBNOVA_DB_SOCKET`) and shown read-only on the login page. Users can't choose an arbitrary server, which avoids SSRF and keeps V1 local-only.
- **`users` table:** each row records a MySQL username that has signed in (`firstOrCreate`), so audit logs and query history have a stable actor. Rows hold **no password**. Usernames are case-sensitive (`lowercase_usernames => false`).
- **Credentials at runtime:** `MySQLCredentialStore` keeps `{username, password}` in the session, encrypted with `Crypt` (and the session itself is encrypted by default). Every adapter call in Phase 2 and later uses these credentials. `EnsureMySQLCredentials` logs the user out if they're missing or belong to a different username.
- **Password confirmation** (`password.confirm`) re-checks the password against MySQL.
- **Removed:**
    - Fortify registration, password reset, email verification, 2FA and passkeys.
    - The Profile and Security settings pages, plus their components and tests.
    - Admin creation in `dbnova:install`, which now only runs the system checks and exits non-zero if any fail.
- **Remember me** is removed, because a remembered login wouldn't have MySQL credentials.
- **MySQL accounts with empty passwords** can't log in, because the Fortify login request requires a password. Use an account with a password.

## Alternatives

- **Keep the app admin + MySQL credentials in `.env` (ADR 0004).** This was rejected by the user. It also gives every DBNova user the same MySQL privileges.
- **A custom guard with no `users` table.** Simpler storage, but there'd be no stable actor for the audit log or query history, and Fortify's login, throttling and confirmation couldn't be reused.
- **Host field on the login form.** Deferred, because V1 is local-only and an arbitrary host is an SSRF risk.

## Consequences

- MySQL grants decide what each user can do. DBNova's read-only mode still applies on top.
- **Background jobs (Phases 6 and 7):** a queued import or export outlives the HTTP request. When it's dispatched, the job row stores the user's credentials encrypted with `Crypt`, and they're wiped when the job reaches a terminal state. Job payloads only ever hold IDs.
- The `APP_KEY` protects stored credentials. Rotating it signs everyone out.
- The CLI (`dbnova:install`, the scheduler) has no user credentials, so the MySQL check there only proves the server is reachable (it treats access denied as reachable).
