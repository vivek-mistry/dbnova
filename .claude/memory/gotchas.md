# Gotchas & Lessons Learned

Append entries during and at the end of each phase. Use the format `Phase N — what happened — what to do instead`.

- Phase 0 — `.gitignore` (from Boost) ignored `/.claude` and `/CLAUDE.md`. — Changed it to ignore only `/.claude/settings.local.json`, so the shared workspace is committed.
- Phase 0 — The Bash guard hook blocks any shell command containing the text `fromShellCommandline`, even a doc-writing one. — Edit docs with the Edit/Write tools, not heredocs or scripts.
- Phase 0 — `mysql` on PATH is a shell alias. — Always resolve binaries from config, never from `PATH` lookups that rely on the interactive shell.
