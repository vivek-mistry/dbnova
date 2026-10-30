# Gotchas & Lessons Learned

Append entries during and at the end of each phase. Use the format `Phase N — what happened — what to do instead`.

- Phase 0 — `.gitignore` (from Boost) ignored `/.claude` and `/CLAUDE.md`. — Changed it to ignore only `/.claude/settings.local.json`, so the shared workspace is committed.
- Phase 0 — The Bash guard hook blocks any shell command containing the text `fromShellCommandline`, even a doc-writing one. — Edit docs with the Edit/Write tools, not heredocs or scripts.
- Phase 1 — `php artisan wayfinder:generate` without `--with-form` breaks every `.form()` call (vue-tsc errors). — Always run `php artisan wayfinder:generate --with-form`. The Vite plugin uses `formVariants: true`.
- Phase 1 — Un-ignoring `.claude/` put its Markdown under `vp check` formatting. — Run `npm run check:fix` after editing docs, or CI fails.
- Phase 1 — `config('x')` returns `mixed`, and Larastan level 7 can't infer `collect()` template types from it. — Use the typed accessors (`config()->array()`, `->string()`, `->integer()`).
- Phase 1 — `PDO::query()`/`prepare()` are typed `PDOStatement|false` even with `ERRMODE_EXCEPTION`. — Handle the `false` branch explicitly instead of chaining.
- Phase 1 — Layout props in Inertia v3 pages come from `setLayoutProps({...})` (dynamic) or `defineOptions({ layout: {...} })` (static). — Use `setLayoutProps` when breadcrumbs depend on props.
- Phase 1 — Tests that hit authenticated routes must use `actingAsMySqlUser()` (tests/Pest.php). A plain `actingAs()` gets logged out by `EnsureMySQLCredentials`. — Use the helper.
- Phase 1 — Never store real MySQL passwords in repo files, memory or tests. — Tests use fake values like `Secret@123` with a mocked `MySQLConnector`.
- Phase 2 — `Factory::make()` still **persists** related models given as `User::factory()` attributes. Running it in tinker wrote a fake user into the dev SQLite DB (removed). — Never run factories against the dev DB. Use tests.
- Phase 2 — MySQL 8 caches `information_schema` table sizes for 24 h. — `MySQLConnection` sets `information_schema_stats_expiry = 0` per session (ignored on servers without it).
- Phase 2 — `.env.testing` replaces `.env` entirely (it would need its own `APP_KEY`). — Integration test credentials go in `.env` as `DBNOVA_TEST_DB_*`.
- Phase 3 — `@tanstack/vue-table` is **v9**: `useTable({ features: tableFeatures({...}), columns, data })`, and sizing/resizing are opt-in features. — Read `node_modules/@tanstack/vue-table/skills/` and don't use v8 (`useVueTable`) patterns.
- Phase 3 — In `<script setup>`, a prop named `table` and a `const table = useTable()` collide in the template. — Name the instance `dataTable`.
- Phase 3 — `@inertiajs/core` (where `HttpResponseError` lives) isn't a direct dependency and `@inertiajs/vue3` doesn't re-export it. — `lib/httpError.ts` duck-types `error.response.data` instead.
- Phase 3 — `env()` outside `config/` returns null once config is cached. — Test credentials live in `config('dbnova.testing.*')`.
- Phase 3 — oxlint `no-floating-promises` fails CI on fire-and-forget calls. — Prefix with `void` when the promise already handles its own errors.
- Phase 0 — `mysql` on PATH is a shell alias. — Always resolve binaries from config, never from `PATH` lookups that rely on the interactive shell.
