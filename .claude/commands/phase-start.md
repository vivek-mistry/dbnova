---
description: Start a DBNova development phase (loads the phase file, checks prerequisites, asks for approvals)
argument-hint: <phase-number>
---

Start DBNova phase $ARGUMENTS.

Follow the `dbnova-phase-workflow` skill, section "Starting a phase":
1. Open `.claude/phases/README.md` and find phase $ARGUMENTS. If the previous phase isn't `done`, tell me and ask whether to continue anyway.
2. Read the phase file and every doc listed under "Read first", plus `.claude/rules/RULES.md` and `.claude/memory/gotchas.md`.
3. Show me a short summary: the goal, the task list, any new dependencies that need my approval, and the open questions.
4. Ask for approval on the dependencies and open questions. **Don't write application code** until I confirm.
5. After I confirm, mark the phase `in progress` in the phase file and README, update `TASK.md`, and begin.
