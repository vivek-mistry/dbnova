---
description: Close a DBNova phase (tests, quality gates, reviews, memory + docs update)
argument-hint: <phase-number>
---

Close DBNova phase $ARGUMENTS by following the `dbnova-phase-workflow` skill, section "Closing a phase":

1. Make sure every task in the phase file is ticked. List any that aren't and ask whether to defer them.
2. Run the phase's tests, then `vendor/bin/pint --dirty --format agent`, then `composer ci:check`. Report the real output, including any failures.
3. Run the `dbnova-security-review` skill. For phases 2, 3, 5, 6 and 7, also delegate to the `dbnova-performance-reviewer` agent. Fix high-severity findings, or report them to me.
4. Check each acceptance criterion and state how it was verified.
5. Fill in "Completion notes" in the phase file, append to `.claude/memory/gotchas.md` and `decisions.md`, and update any docs that changed.
6. Set the phase status to `review` in the phase file and README, and point `TASK.md` at the next phase.
7. Propose a commit message, but **don't commit** until I approve. Then stop.
