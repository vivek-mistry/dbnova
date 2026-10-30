# DBNova

DBNova is a self-hosted, modern MySQL manager (a phpMyAdmin alternative) built on Laravel 13, Inertia v3, Vue 3, TypeScript, Tailwind 4 and shadcn-vue.

V1 manages **one local MySQL server**: databases, tables, records, schema, SQL editor, and large (10 GB+) imports and exports.

## How work is organised

- Work is delivered **phase by phase**. The phase index and current status live in `.claude/phases/README.md`, and the live checklist is in `TASK.md`.
- Start a phase with `/phase-start N`, check progress with `/phase-status`, and close a phase with `/phase-done N`.
- **Never start the next phase, add a dependency, or commit without the user's approval.**
- Architecture docs live in `.claude/docs/` and decision records in `.claude/docs/adr/`. Read the relevant doc before changing that area.
- Project skills live in `.claude/skills/` (`dbnova-*`). Laravel Boost skills (testing, Inertia Vue, Wayfinder, Tailwind, Fortify) live in `.cursor/skills/`. Activate them when you work in their domain.

## Always-loaded context

@.claude/rules/RULES.md
@.claude/memory/MEMORY.md
@.claude/phases/README.md

## Laravel Boost guidelines (generated locally, not committed)

@AGENTS.md
