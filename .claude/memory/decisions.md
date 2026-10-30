# Decision Log

Newest entries go at the bottom. Use the format `YYYY-MM-DD — Decision — Why — ADR`.

- 2026-10-30 — The product is named **DBNova**. — The user chose it. — —
- 2026-10-30 — V1 is self-hosted, local MySQL only, with one destination database per import. — Finalized scope. — —
- 2026-10-30 — Hybrid access: raw PDO for interactive work, `mysql`/`mysqldump` CLI for import/export. — CLI batch output loses types and can't tell NULL from 'NULL', and a process per request is slow. The CLI streams huge files best. — ADR 0001
- 2026-10-30 — SQLite stores app metadata. — Keeps it independent of the managed server, with zero extra services. — ADR 0002
- 2026-10-30 — The queue uses the database driver (SQLite) by default, with Redis optional. — No new dependency or service, and the self-hosted install is simpler. — ADR 0003
- 2026-10-30 — Single admin, registration disabled, admin created with `dbnova:install`. — A DB tool must be locked down by default. — ADR 0004
- 2026-10-30 — Import progress uses polling first, and Reverb comes later. — Simpler, and enough for a single operator. — ADR 0005
- 2026-10-30 — Imports are streamed through a token-aware scanner. Multi-database dumps are rejected, and there's no rollback claim. — Correctness and honesty. — ADR 0006
- 2026-10-30 — The project's Claude workspace lives in a committed `.claude/` (docs, phases, memory, skills, agents, commands). — The user asked for it, so every session shares context. — ADR 0007
- 2026-10-30 — Claude Code hooks enabled (Bash credential guard, Pint on PHP edits). — The user opted in. — ADR 0007
- 2026-10-30 — Phase 0 approved and committed. Work happens on phase branches (`phase/NN-name`) because main is the default branch. — Commit policy. — —
