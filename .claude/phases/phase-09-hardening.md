# Phase 9 — Security, performance & recovery hardening

**Status:** in progress (security review and fixes done; fuzzing, drills, benchmarks, PHPStan 8 next)
**Goal:** Prove the V1 acceptance tests and close security gaps before release.

## Tasks

### Security

- [x] Run the `dbnova-security-review` skill and the `dbnova-security-reviewer` agent across the whole codebase (1 HIGH, 2 MEDIUM, 4 LOW, all fixed)
- [ ] Fuzz identifiers (backticks, NUL, unicode, 65+ chars) through every endpoint that takes names
- [ ] Upload path and zip fuzzing (traversal, symlinks, zip bomb)
- [x] Route audit: every route is authenticated, and rate limits are in place (`RouteThrottleTest`)
- [x] Confirm no credentials appear in logs, exceptions, job payloads or Inertia props (grep + tests; `CredentialLeakTest`)

### Performance

- [ ] Record this Mac's spec in PERFORMANCE.md (user: benchmark on this Mac)
- [ ] Finalize the stress commands (`dbnova:stress:seed-table`, `dbnova:stress:make-dump`)
- [ ] Run the benchmarks in PERFORMANCE.md and record the hardware and results
- [ ] Frontend: profile grid scrolling at 100+ columns and 1,000-row pages

### Recovery drills

- [ ] Kill the worker mid-import → `interrupted`
- [ ] Stop MySQL mid-import → `failed` with "connection lost"
- [ ] Fill the disk during upload/extraction → a clean error, with temp files cleaned up
- [ ] Drop the network during upload → resume works

### Quality gates

- [ ] Raise Larastan beyond level 7 if feasible, with `composer ci:check` green
- [ ] Contract test suite passes for the MySQL adapter

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
