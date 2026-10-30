# Phase 1 — Foundation, layout & navigation

**Status:** done
**Goal:** A branded, locked-down app shell with DBNova navigation, a dashboard skeleton and a system health page that checks every runtime dependency. Users sign in with their MySQL account (ADR 0008).
**New dependencies:** none.
**Read first:** `docs/ARCHITECTURE.md` (config section), `docs/SECURITY.md` (auth), `docs/DESIGN.md` (layout), `docs/adr/0008-mysql-account-login.md`.

## Tasks

### Branding

- [x] `APP_NAME=DBNova` in `.env.example` (and as the `config/app.php` default), plus a new database logo in `AppLogoIcon.vue`. `/` redirects to `/dashboard`, and `Welcome.vue` is deleted
- [x] Enable SQLite WAL mode (ADR 0002) through the connection config: `journal_mode=wal`, `busy_timeout=5000`, `synchronous=normal`

### MySQL account login (added — ADR 0008, replaces the single admin)

- [x] `MySQLConnector` (`app/Database/Adapters/MySQL`): PDO connect with host settings from config, `serverLabel()`, and `isAccessDenied()` (1045/1698)
- [x] `AuthenticateMySQLUser` registered with `Fortify::authenticateUsing` and `confirmPasswordsUsing`. Access denied → "credentials do not match". Unreachable server → error naming the server
- [x] `MySQLCredentialStore`: `{username, password}` encrypted in the session. `SESSION_ENCRYPT` defaults to true
- [x] `EnsureMySQLCredentials` middleware on every app route: logs out sessions without matching credentials
- [x] Migration `convert_users_to_mysql_accounts`: `username` (unique), nullable email/password, drops 2FA columns and the `passkeys` table. `User`/`UserFactory` updated
- [x] Fortify: `username` field, `lowercase_usernames=false`, `features=[]`, only the login limiter
- [x] Removed: registration, password reset, email verification, 2FA, passkeys, the Profile/Security settings pages, their controllers, requests, components and composable, and remember-me
- [x] `Login.vue`: username + password, with the server (`127.0.0.1:3306`) shown read-only. `ConfirmPassword.vue` asks for the MySQL password. Settings only has Appearance

### Configuration

- [x] `config/dbnova.php`: connection (host, port, socket, charset, timeout; **no credentials**), binaries, paths, limits, retention, queue heartbeat threshold, read_only
- [x] `.env.example`: `DBNOVA_*` keys
- [x] A `dbnova` log channel in `config/logging.php`

### Install command

- [x] `dbnova:install`: runs the system checks, prints a table, and exits 1 if any fail. It says to sign in with a MySQL account

### System checks

- [x] `SystemCheckService` returns a list of `SystemCheck` DTOs (`SystemCheckStatus` enum)
    - [x] PHP version + required extensions, and worker extensions as a warning
    - [x] `mysql` / `mysqldump` binaries: executable + `--version` (Process facade, array args)
    - [x] MySQL server through `MySQLConnector`. Signed in: version read with the user's credentials. No credentials (CLI): access denied counts as "reachable"
    - [x] Storage paths writable (created when missing) + free disk space
    - [x] Queue worker heartbeat (`RecordQueueHeartbeat` scheduled every minute)
- [x] `SystemController@index` → `pages/system/Health.vue` (with a re-run button)

### Layout & navigation

- [x] `DashboardController` (invokable)
- [x] Navigation shared in `resources/js/lib/navigation.ts`. Unbuilt sections render `ComingSoon.vue` with their phase number
- [x] `pages/Dashboard.vue`: skeleton server cards
- [x] A read-only badge in the header (`dbnova.readOnly` shared prop)
- [x] The user menu shows the MySQL username

### Tests

- [x] `Auth/AuthenticationTest`:
    - login screen shows the server
    - MySQL login stores credentials
    - a returning user reuses their account
    - username case is kept
    - access denied and unreachable server
    - logout when credentials are missing or don't match
    - logout discards the credentials
    - rate limiting
- [x] `Auth/PasswordConfirmationTest`: confirmed against MySQL, and rejected when MySQL rejects it
- [x] `Auth/RegistrationDisabledTest`, `Console/Commands/InstallCommandTest` (table + success, failure exit)
- [x] `Services/System/SystemCheckServiceTest`: binaries, MySQL (unreachable, reachable anonymously, version with credentials), paths, disk, heartbeat
- [x] `NavigationTest`, `SystemTest`, `DashboardTest`, `Jobs/RecordQueueHeartbeatTest`, `ExampleTest`
- [x] Removed with the user's approval: EmailVerification, PasswordReset, Registration, TwoFactorChallenge, VerificationNotification, Settings/ProfileUpdate, Settings/Security

## Acceptance criteria

- Users sign in with a MySQL username and password, and nobody can register. ✅ Feature tests. `AuthenticateMySQLUser::verify()` was also run against the real local server: the real `root` password is accepted and a wrong password rejected.
- The sidebar shows the DBNova navigation, and the dashboard renders. ✅ `NavigationTest`, `DashboardTest`, vue-tsc. A visual check by the user is still pending.
- The System page shows real `mysql`/`mysqldump` versions and flags a missing or misconfigured binary. ✅ Run against this machine.
- `composer ci:check` passes. ✅ Lint, format, vue-tsc, Pint, PHPStan level 7 (0 errors), Pest 49/49.

## Open questions

- _(resolved)_ Welcome page: removed. `/` redirects to `/dashboard`.
- _(resolved)_ Branching: Phase 1 commits go on the existing `phase/00-architecture` branch.
- _(resolved)_ Login model: MySQL account (ADR 0008). The host is fixed in `.env`.

## Completion notes

- **Shipped:** everything above. There are no new dependencies.
- **Unused now:** `@laravel/passkeys` (npm) is no longer used. Removing it needs approval, so it's left installed.
- **Deviations:**
    - The single-admin model was replaced by MySQL login partway through the phase, at the user's request.
    - The health route is named `system.index`.
    - `NavFooter.vue` is kept but unused.
- **Environment:** the `mysql` alias on this machine is broken. Use `/usr/local/mysql/bin/mysql` and `/usr/local/mysql/bin/mysqldump` (8.0.39).
- **Security review:**
    - Passwords are only held encrypted in the session, and never logged, stored in the DB, or rendered.
    - Access denied and unreachable server are reported differently, without leaking the password.
    - Every app route is behind `auth` + `EnsureMySQLCredentials`.
    - There's no user-chosen host, so no SSRF.
    - No findings.
- **Follow-ups:**
    - Phase 2 builds `MySQLConnection` on `MySQLConnector` + `MySQLCredentialStore`.
    - Phases 6 and 7 store encrypted credentials on job rows (ADR 0008).
