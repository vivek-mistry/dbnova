# Phase 0 — Architecture, documentation & `.claude/` workspace

**Status:** done
**Goal:** Finalize the architecture and set up the shared Claude workspace before any application code is written.

## Tasks
- [x] Inspect the repo: versions, conventions, environment (`memory/conventions.md`, `memory/environment.md`)
- [x] Confirm decisions with the user: PDO+CLI, SQLite queue, single admin
- [x] Root `CLAUDE.md` + `TASK.md`
- [x] `.gitignore`: commit `.claude/` and `CLAUDE.md`, ignore `.claude/settings.local.json`
- [x] Docs: PRD, ARCHITECTURE, DATABASE_ADAPTERS, IMPORT_EXPORT_ENGINE, SECURITY, PERFORMANCE, DESIGN, TESTING, INSTALL
- [x] ADRs 0001–0007
- [x] Phase files 0–10 with tasks + acceptance criteria
- [x] Memory: index, decisions, conventions, environment, gotchas
- [x] Rules: `rules/RULES.md`
- [x] Skills: phase-workflow, adapter, import-export, data-grid, security-review
- [x] Agents: security-reviewer, performance-reviewer
- [x] Commands: `/phase-start`, `/phase-status`, `/phase-done`
- [x] `settings.json` (permissions allowlist)
- [x] Hooks enabled: `hooks/guard-bash.sh`, `hooks/pint-php.sh` (added)
- [x] User review & approval
- [x] Commit

## Acceptance criteria
- Every decision lists its reasoning and alternatives (ADRs).
- A fresh Claude session loads `CLAUDE.md`, and `/phase-status` lists the phases.
- Every phase file has tasks, acceptance criteria and dependencies.

## Open questions
- _(resolved)_ Hooks: enabled.
- _(resolved)_ Target hardware: the user has a target spec. Collect its details at the start of Phase 9 and record them in PERFORMANCE.md.

## Completion notes
- Shipped: CLAUDE.md, TASK.md, and the `.claude/` workspace (docs, 7 ADRs, 11 phase files, memory, rules, 5 skills, 2 agents, 3 commands, settings + 2 hooks).
- Deviation: `.gitignore` changed so `.claude/` and `CLAUDE.md` are committed.
- Hooks verified by hand: the guard blocks `-p<pw>`, `--password=` and `MYSQL_PWD=`, allows `--defaults-extra-file`, and Pint runs on PHP files only.
