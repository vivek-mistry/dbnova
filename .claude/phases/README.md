# DBNova — Phase Index

Status values: `not started` · `in progress` · `review` · `done`. Only **one** phase is `in progress` at a time.

| #   | Phase                                     | Status      | File                                                         |
| --- | ----------------------------------------- | ----------- | ------------------------------------------------------------ |
| 0   | Architecture, docs & `.claude/` workspace | done        | [phase-00-architecture.md](phase-00-architecture.md)         |
| 1   | Foundation, layout & navigation           | done        | [phase-01-foundation.md](phase-01-foundation.md)             |
| 2   | Adapter layer & database explorer         | done        | [phase-02-adapter-explorer.md](phase-02-adapter-explorer.md) |
| 3   | Table browser & data grid                 | done        | [phase-03-table-browser.md](phase-03-table-browser.md)       |
| 4   | Schema management                         | done        | [phase-04-schema.md](phase-04-schema.md)                     |
| 5   | SQL editor                                | done        | [phase-05-sql-editor.md](phase-05-sql-editor.md)             |
| 6   | Large import engine                       | done        | [phase-06-import.md](phase-06-import.md)                     |
| 7   | Export engine                             | in progress | [phase-07-export.md](phase-07-export.md)                     |
| 8   | Monitoring & advanced operations          | not started | [phase-08-monitoring.md](phase-08-monitoring.md)             |
| 9   | Hardening & stress testing                | not started | [phase-09-hardening.md](phase-09-hardening.md)               |
| 10  | Release & self-hosted install             | not started | [phase-10-release.md](phase-10-release.md)                   |

## Dependencies needing approval (by phase)

| Phase | Package                                                                              | Purpose                             |
| ----- | ------------------------------------------------------------------------------------ | ----------------------------------- |
| 3 ✅  | `@tanstack/vue-table@^9.3.1`, `@tanstack/vue-virtual@^3.13.50` (approved, installed) | Data grid + virtualization          |
| 5 ✅  | `codemirror@^6.0.2`, `@codemirror/lang-sql@^6.10.0` (approved, installed)            | SQL editor                          |
| 8     | `apexcharts`, `vue3-apexcharts`                                                      | Dashboard charts                    |
| —     | `predis/predis` (optional)                                                           | Only if Redis queue is chosen later |

## Workflow

`/phase-start N` → build (tick tasks in the phase file) → `/phase-done N` → user review → next phase.
