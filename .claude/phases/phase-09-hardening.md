# Phase 9 — Security, performance & recovery hardening

**Status:** done (931aed7)
**Goal:** Prove the V1 acceptance tests and close security gaps before release.

## Tasks

### Security

- [x] Run the `dbnova-security-review` skill and the `dbnova-security-reviewer` agent across the whole codebase (1 HIGH, 2 MEDIUM, 4 LOW, all fixed)
- [x] Fuzz identifiers (backticks, NUL, unicode, 65+ chars) through every endpoint that takes names
- [x] Upload path and zip fuzzing (traversal, symlinks, zip bomb)
- [x] Route audit: every route is authenticated, and rate limits are in place (`RouteThrottleTest`)
- [x] Confirm no credentials appear in logs, exceptions, job payloads or Inertia props (grep + tests; `CredentialLeakTest`)

### Performance

- [x] Record this Mac's spec in PERFORMANCE.md (user: benchmark on this Mac)
- [x] Finalize the stress commands (`dbnova:stress:seed-table`, `dbnova:stress:make-dump`)
- [x] Run the benchmarks in PERFORMANCE.md and record the hardware and results
- [x] Frontend: profile grid scrolling at 100+ columns and 1,000-row pages

### Recovery drills

- [x] Kill the worker mid-import → `interrupted`
- [x] Stop MySQL mid-import → `failed` with "connection lost"
- [x] Fill the disk during upload/extraction → a clean error, with temp files cleaned up
- [x] Drop the network during upload → resume works

### Quality gates

- [x] Raise Larastan beyond level 7 if feasible, with `composer ci:check` green
- [x] Contract test suite passes for the MySQL adapter

**User decisions (2026-11-16):** the MySQL-stop drill runs against a throwaway `mysqld` on another port; the disk-full drill uses a 200 MB RAM disk; benchmarks are recorded for this Mac; PHPStan: try level 8 and keep it only if the fixes are real.

## Acceptance criteria

- All "Important acceptance tests" from the PRD pass and are documented.
- No open high-severity security findings.

## Completion notes

### Security review (2026-11-16)

Findings from the reviewer agent (range f24892a..HEAD, plus whole-codebase sweeps), all fixed with tests:

- **HIGH-1:** passwords in `laravel.log` via trace arguments. Fixed with `#[\SensitiveParameter]` on every credential parameter; `CredentialLeakTest` fails without the fix.
- **MEDIUM-1:** an anonymous `''@host` match became the typed DBNova user. `verify()` now requires `CURRENT_USER()` to match the name.
- **MEDIUM-2:** unthrottled password re-checks (`user/confirm-password`, `schema/apply`). Fixed by a 10/min per-username failure limit inside `verify()`.
- **LOW-1:** raw MySQL errors in the audit log (passwords, row values). Fixed by `AuditLogger::redact()`.
- **LOW-2:** `sql/run` and other write routes had no throttle. All are throttled now, with per-route prefixes; the test suite caught that unprefixed throttles share one counter.
- **LOW-3:** the export caps could be raced. Fixed with a per-user `Cache::lock`.
- **LOW-4:** REPAIR ran without a confirmation. It now needs `confirm: true`.
- **Notes:**
    - `local` disk `serve` is set to false.
    - Process ids over 18 digits return 404, not 500.

### Fuzzing, drills, benchmarks, static analysis (2026-11-16)

- **Identifier fuzzing** (`tests/Feature/Security/IdentifierFuzzTest.php`, real MySQL): 16 hostile names, plus invalid UTF-8, sent to 15 endpoints, with a canary database checked afterwards.
    - It found that emoji and trailing-space names gave **503**. Emoji: MySQL error "Conversion … into utf8mb3 impossible". Trailing space: information_schema's PAD SPACE matched `t ` to `t`, and the next query failed.
    - `IdentifierQuoter` now refuses names outside the BMP, names with a trailing space and invalid UTF-8. `EnsureValidRouteNames` turns such names in the URL into a 404, and the `TableName`/`DatabaseName` rules refuse them too.
- **Upload and zip fuzzing:** hostile upload file names are stored as metadata only (UUID paths). Added Windows-drive and backslash-traversal zip entries, an encrypted zip, and a 64 MB zip bomb streamed with < 24 MB memory and nothing extracted.
- **Recovery drills:** see PERFORMANCE.md.
    - Two real bugs were fixed: a full disk during an upload gave **500** (now 507 and resumable), and during an export the ENOSPC warning escaped the job (now a clean failure that deletes the partial file).
    - `DBNOVA_WORK_DIR` was added so the working files can live on another volume. The disk drill is `tests/Feature/Drills/DiskFullDrillTest.php`, opt-in via `DBNOVA_DRILL_SMALL_VOLUME`.
- **Benchmarks** on the M2: 10M-row browse in 3–8 ms at any depth; a 2 GB `.sql.gz` import in 180 s with a 57 MB peak.
- **Grid:** column virtualization took 120-column vertical scrolling from about 20 fps to 60 fps.
- **PHPStan:** level 8 passes with no changes and is now the project level. Level 9 reports 121 errors and level 10 reports 193 (strict `mixed`), left as is.
- **Contract coverage:** `AdapterContractIntegrationTest` covers the contract methods no other MySQL integration test reached. Every public contract method now runs against MySQL.
- 704 tests pass against real MySQL (2 opt-in drills are skipped unless a small volume is given), and `composer ci:check` is green.
