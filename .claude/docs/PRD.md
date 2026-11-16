# DBNova — Product Requirements (V1)

## Vision

A self-hosted MySQL manager that feels faster and more trustworthy than phpMyAdmin. It's quick for everyday browsing, and reliable for importing and exporting production-sized databases.

## Persona

- **Solo developer / small-team operator** who runs MySQL on the same machine or VPS as DBNova. They're comfortable with a terminal for installation but want a GUI for daily work.

## V1 scope

| #   | Capability                    | Notes                                                                                  |
| --- | ----------------------------- | -------------------------------------------------------------------------------------- |
| 1   | Manage the local MySQL server | Server info, health, process list, variables                                           |
| 2   | Manage multiple databases     | List, create, drop, rename, collation                                                  |
| 3   | Table explorer                | Server-side pagination, sort, filter, virtualized grid, 10M+ rows                      |
| 4   | Record operations             | Inline edit, insert, delete (PK/unique-key tables)                                     |
| 5   | Schema management             | Tables, columns, indexes, foreign keys, with DDL preview                               |
| 6   | SQL editor                    | CodeMirror 6, multi-statement, history, cancel                                         |
| 7   | Import                        | `.sql`, `.sql.gz`, `.zip`. Chunked, resumable upload. 10 GB target. One destination DB |
| 8   | Export                        | Full DB or selected tables to `.sql` / `.sql.gz`, and tables or results to CSV         |
| 9   | Safety                        | Read-only mode, typed confirmations, audit log                                         |

## Non-goals (V1)

- Remote or multiple MySQL servers, SSH tunnels.
- Other engines (MariaDB, PostgreSQL, SQLite, SQL Server). The adapter layer prepares for them.
- Imports that span multiple databases.
- DBNova-specific roles. V1 users sign in with their MySQL account, and MySQL grants decide their access (ADR 0008).
- Automatic resume of a half-finished import.
- Real-time websockets (polling is used first).

## Success metrics / acceptance highlights

- Browse a 10M-row table: each page responds in ≤ ~200 ms on suitable hardware, and PHP memory stays flat.
- Import a 10 GB dump with PHP worker memory ≤ ~64 MB. Row counts match.
- Invalid SQL is never reported as success.
- A restarted worker leaves jobs in an accurate `interrupted` state.
- Exports of multi-GB databases keep memory flat.

## Future (post-V1)

MariaDB adapter (closest fit), then PostgreSQL. Remote connections. Roles. Reverb live updates. Scheduled backups.
