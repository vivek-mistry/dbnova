# DBNova — Hard Rules

These rules are non-negotiable. If a task seems to need breaking one, stop and ask the user.

## Managed-database access
1. **Never** use Laravel's `DB` facade, Eloquent, Query Builder or a `config/database.php` connection to talk to the **managed** MySQL server. Those are for the internal SQLite app database only.
2. All managed-database work goes through the adapter contracts in `app/Database/Contracts`. Controllers and services depend on interfaces, never on `MySQL*` classes directly.
3. Interactive work (browse, schema, SQL editor) uses the adapter's **raw PDO** connection. Bulk work (import, export) uses the `mysql` / `mysqldump` **CLI** through `ProcessRunner`.
4. Identifiers (database, table, column and index names) are quoted **only** through `IdentifierQuoter`. Values are always bound parameters. Never interpolate user input into SQL.

## Processes & shell
5. Never build shell command strings. Process arguments are always **arrays** passed to Symfony Process. Never use `Process::fromShellCommandline`, `exec`, `shell_exec`, `system`, `passthru` or backticks.
6. Credentials are never passed in argv or logged. Use a temporary `--defaults-extra-file` (mode 0600) and delete it afterwards.
7. Binary paths come from `config('dbnova.binaries.*')`. Never assume `mysql` is on `PATH`, because shell aliases are invisible to Process.

## Memory & performance
8. Never load an entire SQL file, upload, export or table into PHP memory. Stream with fixed-size chunks (≤ 1–16 MB).
9. Table data is always server-side paginated. Large result sets use unbuffered PDO cursors. Lists in the UI are virtualized.
10. Long-running work (import, export, large exports to CSV) runs in a queued job, never inside an HTTP request.

## Honesty & safety
11. Never report an import as "committed" or show a % of rows committed. Report bytes streamed to MySQL. Never claim rollback that MySQL can't provide.
12. Destructive actions (DROP, TRUNCATE, DELETE without WHERE, ALTER that drops data) need explicit confirmation and are recorded in the audit log.
13. Read-only mode, when enabled, must block every write path **in the adapter**, not just in the UI.

## Process & conventions
14. Follow the phase workflow (`dbnova-phase-workflow` skill). Do one phase at a time, and stop for review at the end of each phase.
15. Get the user's approval before adding any composer or npm dependency, creating a new top-level folder, committing, or pushing.
16. Use `php artisan make:* --no-interaction`, run `vendor/bin/pint --dirty --format agent` after PHP edits, and write Pest tests for behaviour changes.
17. Frontend: pages use lowercase folders (`pages/databases/Index.vue`), components are PascalCase, use shadcn components from `@/components/ui`, and import routes through Wayfinder (`@/routes`, `@/actions`).
18. When you learn something non-obvious, add it to `.claude/memory/` (decisions, gotchas, environment).
