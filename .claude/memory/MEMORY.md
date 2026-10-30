# DBNova — Project Memory Index

Each file keeps one kind of knowledge. Read the one you need, append to it when you learn something, and keep this index to one line per file.

- [decisions.md](decisions.md) — dated log of every product and architecture decision and why it was made (detail lives in `../docs/adr/`)
- [conventions.md](conventions.md) — repo conventions found in the starter kit (folders, naming, Wayfinder, shadcn, tests)
- [environment.md](environment.md) — local machine facts: MySQL binaries/aliases, PHP version, services
- [gotchas.md](gotchas.md) — lessons learned and traps hit during each phase

Key facts to keep in mind:

- The managed MySQL server is reached through raw PDO (interactive) and CLI (bulk), **never** Laravel DB/Eloquent. See ADR 0001.
- App metadata, queue, cache and sessions live in SQLite. See ADR 0002 and ADR 0003.
- Users sign in with their **MySQL account** (no app accounts). Credentials live encrypted in the session. See ADR 0008, which supersedes 0004.
