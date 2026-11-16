# ADR 0007 — Committed `.claude/` workspace & phase workflow

**Status:** Accepted (2026-10-30)

## Context

Development happens phase by phase with Claude Code across many sessions. Context (architecture, decisions, progress, conventions) has to persist and be shared.

## Decision

- A root `CLAUDE.md` (short) imports `.claude/rules/RULES.md`, `.claude/memory/MEMORY.md`, `.claude/phases/README.md` and the locally generated `AGENTS.md`.
- `.claude/` is committed. It holds `docs/` (architecture + ADRs), `phases/` (one file per phase), `memory/`, `rules/`, `skills/`, `agents/`, `commands/` and `settings.json`. Only `settings.local.json` is gitignored.
- Phase lifecycle: `/phase-start N` → build → `/phase-done N` → user review.

## Hooks (enabled 2026-10-30 at the user's request)

Configured in `.claude/settings.json`, scripts in `.claude/hooks/`:

- `PostToolUse` on `Edit|Write` → `hooks/pint-php.sh` formats the edited PHP file with Pint.
- `PreToolUse` on `Bash` → `hooks/guard-bash.sh` blocks `mysql`/`mysqldump` commands with `-p<value>`, `--password=` or `MYSQL_PWD=`, and any Bash command that contains the text `fromShellCommandline`. Known false positive: shell commands that only _write docs_ mentioning that method get blocked too, so edit docs with the Edit/Write tools.

## Alternatives

- **A plain `docs/` folder.** Readable, but Claude doesn't auto-load it, and there are no skills or commands. Rejected.
- **Only the personal memory under `~/.claude/projects`.** Not shared with teammates or other machines. Rejected as the primary store.

## Consequences

- `.gitignore` was changed to stop ignoring `/.claude` and `/CLAUDE.md`.
- Boost-generated `AGENTS.md` stays local. If it's missing, the `@AGENTS.md` import is simply skipped.
