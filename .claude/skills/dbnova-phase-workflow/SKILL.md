---
name: dbnova-phase-workflow
description: How to start, track and finish a DBNova development phase. Use when beginning work on a phase, ticking off phase tasks, closing a phase, or when the user asks about phase status or what's next.
---

# DBNova Phase Workflow

DBNova is built one phase at a time. Phase files live in `.claude/phases/phase-NN-*.md`, and the index is `.claude/phases/README.md`.

## Starting a phase

1. Read `.claude/phases/README.md`. Confirm the previous phase is `done` (or the user explicitly overrides this).
2. Read the phase file **and** every doc listed under "Read first".
3. Read `.claude/rules/RULES.md` and `.claude/memory/gotchas.md`.
4. List the phase's **new dependencies** and get explicit user approval before installing any.
5. Set the phase status to `in progress` in both the phase file and README, and update `TASK.md` (current phase, "Now" list).
6. Show the user the task list and any open questions. Resolve the open questions before coding.

## During the phase

- Tick `- [x]` in the phase file as each task finishes. Don't batch the updates up until the end.
- New tasks discovered along the way go into the phase file under the right heading, marked `(added)`.
- Decisions go in `.claude/memory/decisions.md`. Significant architectural changes get a new ADR in `.claude/docs/adr/`.
- If a doc in `.claude/docs/` becomes inaccurate, update it in the same phase.
- Out-of-scope ideas go under "Open questions" in the relevant future phase file, not into the current phase's code.

## Closing a phase

1. Run the phase's tests, then `vendor/bin/pint --dirty --format agent`, then `composer ci:check`.
2. Run the `dbnova-security-review` skill. For phases that touch data paths (2, 3, 5, 6, 7), also run the `dbnova-performance-reviewer` agent.
3. Check every acceptance criterion and note how it was verified.
4. Fill in "Completion notes" in the phase file: what shipped, deviations, follow-ups, test results.
5. Append lessons to `.claude/memory/gotchas.md`.
6. Set the status to `review` (README + phase file), and point `TASK.md` at the next phase.
7. Propose a commit message. **Commit only when the user approves.**
8. Stop. Don't start the next phase until the user approves.
