# Phase 9 — Security, performance & recovery hardening

**Status:** not started
**Goal:** Prove the V1 acceptance tests and close security gaps before release.

## Tasks

### Security

- [ ] Run the `dbnova-security-review` skill and the `dbnova-security-reviewer` agent across the whole codebase
- [ ] Fuzz identifiers (backticks, NUL, unicode, 65+ chars) through every endpoint that takes names
- [ ] Upload path and zip fuzzing (traversal, symlinks, zip bomb)
- [ ] Route audit: every route is authenticated, and rate limits are in place
- [ ] Confirm no credentials appear in logs, exceptions, job payloads or Inertia props (grep + tests)

### Performance

- [ ] Get the target hardware spec from the user (confirmed to exist) and record it in PERFORMANCE.md
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

## Acceptance criteria

- All "Important acceptance tests" from the PRD pass and are documented.
- No open high-severity security findings.

## Completion notes

_(filled by /phase-done)_
