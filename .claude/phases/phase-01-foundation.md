# Phase 1 — Foundation, layout & navigation

**Status:** not started
**Goal:** A branded, locked-down app shell with DBNova navigation, a dashboard skeleton and a system health page that checks every runtime dependency.
**New dependencies:** none.
**Read first:** `docs/ARCHITECTURE.md` (config section), `docs/SECURITY.md` (auth), `docs/DESIGN.md` (layout), `docs/adr/0004-single-admin.md`.

## Tasks

### Branding & auth
- [ ] `APP_NAME=DBNova` in `.env.example`, update `AppLogo.vue` / `AppLogoIcon.vue`, and make the Welcome page redirect to login or the dashboard
- [ ] Turn off `Features::registration()` in `config/fortify.php`, and remove or adjust the register page and tests
- [ ] Enable SQLite WAL mode (ADR 0002), for example in `AppServiceProvider` or the connection config

### Configuration
- [ ] `config/dbnova.php`: connection, binaries, paths, limits, retention, read_only (see ARCHITECTURE.md)
- [ ] `.env.example`: `DBNOVA_*` keys
- [ ] A `dbnova` log channel in `config/logging.php`

### Install command
- [ ] `php artisan make:command InstallCommand` → `dbnova:install`: create the admin (prompts, validation reuses `PasswordValidationRules`), refuse if an admin already exists unless `--force`, then run the system checks and print a summary

### System checks
- [ ] `app/Services/System/SystemCheckService.php` returns a list of `SystemCheck` DTOs (name, status ok/warn/fail, detail, hint)
  - [ ] PHP version + required extensions
  - [ ] `mysql` / `mysqldump` binaries: executable + `--version` (through Symfony Process, array args)
  - [ ] MySQL reachability (a simple PDO connect, which is the seed of `MySQLConnection` in Phase 2)
  - [ ] Storage paths writable + free disk space
  - [ ] Queue worker heartbeat (a scheduled cache ping)
- [ ] `SystemController@index` → `pages/system/Health.vue`

### Layout & navigation
- [ ] `DashboardController` replaces the `Route::inertia('dashboard')` closure
- [ ] Sidebar items: Dashboard, Databases (placeholder tree), SQL Editor, Import/Export, Processes, System. Placeholder pages show a "Coming in Phase N" empty state
- [ ] `pages/Dashboard.vue`: server cards (version, uptime, databases, total size) as skeleton placeholders
- [ ] A read-only badge in the header when `dbnova.read_only` is on (shared through `HandleInertiaRequests`)

### Tests
- [ ] Registration route returns 404
- [ ] `dbnova:install` creates an admin, and refuses a second one without `--force`
- [ ] `SystemCheckService` with `Process::fake()`: binary missing → fail with a hint
- [ ] Every new route requires auth

## Acceptance criteria
- An admin created through `dbnova:install` can log in, and nobody can register.
- The sidebar shows the DBNova navigation, and the dashboard renders.
- The System page shows real `mysql`/`mysqldump` versions and flags a missing or misconfigured binary.
- `composer ci:check` passes.

## Open questions
- Should the Welcome page be removed entirely (redirect `/` → `/dashboard`)? The default proposal is yes.

## Completion notes
_(filled by /phase-done)_
