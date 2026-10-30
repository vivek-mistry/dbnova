# Design

## Foundations

- Built on the starter kit's shadcn-vue (new-york, neutral palette, CSS variables), Tailwind 4 and Lucide icons.
- Light and dark mode come from the existing `useAppearance` + `HandleAppearance` middleware.
- Density: data views use a compact row height (28–32 px) and monospace values (`font-mono`, tabular numbers).

## Layout

```
┌ Sidebar ───────────────┬ Header: breadcrumbs · read-only badge · user ───┐
│ DBNova logo            │                                                  │
│ ▸ Dashboard            │  Page content                                    │
│ ▾ Databases (tree)     │                                                  │
│    ▸ shop              │                                                  │
│    ▾ blog              │                                                  │
│       • posts          │                                                  │
│ ▸ SQL Editor           │                                                  │
│ ▸ Import / Export      │                                                  │
│ ▸ Processes            │                                                  │
│ ▸ System               │                                                  │
└────────────────────────┴──────────────────────────────────────────────────┘
```

- The database tree lazy-loads tables when you expand a database, and has a filter box at the top.
- Table pages have tabs: **Browse · Structure · Indexes · Foreign keys · SQL · Operations**.

## Data grid

- Sticky header and first column. Column resize. Click a header to sort (server-side). A per-column filter row.
- `NULL` shows as a muted italic badge, an empty string shows as an empty cell, and BLOBs show `[BLOB 12 KB]`.
- Double-click a cell to edit it inline. Enter saves, Esc cancels. Saves are optimistic with rollback on error, and a toast shows the MySQL message.
- Row selection allows bulk delete (with confirmation).
- The footer shows: approximate row count, page size and query time.

## SQL editor

- CodeMirror 6 with the MySQL dialect and schema autocomplete. Ctrl/Cmd+Enter runs the statement under the cursor, and Shift+Ctrl/Cmd+Enter runs all.
- Results open in tabs per statement, using the same data grid (read-only).
- A history side panel and a "Save query" option.

## Import / export

- A wizard: 1) choose file → 2) upload (progress, resumable) → 3) choose target DB → 4) preflight report → 5) import (live status card) → 6) result.
- The status card uses honest labels: "Sent to MySQL", "Statements processed", "Elapsed", "Errors".

## Destructive actions

- A dialog with a typed-name confirmation for DROP DATABASE/TABLE and TRUNCATE.
- DDL preview dialog: the generated SQL is shown in a read-only CodeMirror view before **Execute**.

## Keyboard & command palette (Phase 3/5)

- Cmd/Ctrl+K opens a command palette (jump to a database or table, open the SQL editor, start an import).
- `/` focuses the tree filter.

## Empty and loading states

- Skeletons (`components/ui/skeleton`) for deferred props and grid loading.
- Empty states with a primary action (for example "Create database").
