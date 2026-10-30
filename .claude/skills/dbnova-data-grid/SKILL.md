---
name: dbnova-data-grid
description: Patterns for DBNova's table browser and virtualized data grid (TanStack Table + Virtual, server-side pagination, inline editing). Use when building or changing the data grid, table browse pages, or result views.
---

# DBNova Data Grid

Read `.claude/docs/PERFORMANCE.md` and the "Data grid" section of `.claude/docs/DESIGN.md`. Also activate the Boost `inertia-vue-development` skill.

## Server side

- The first page arrives as an Inertia prop, and later pages are fetched with `useHttp` from the JSON rows endpoint. **No full Inertia visits for paging.**
- Use keyset pagination when the table has a PK or non-null unique key, and send the cursor back opaquely (base64 JSON of key values). Fall back to offset and show a warning.
- Sorting and filtering happen server-side only. The client never sorts a partial dataset.
- TEXT/BLOB values are truncated server-side, and the full value comes on demand.
- Use approximate counts by default, with an exact count only on request.

## Client side

- `@tanstack/vue-table` in **manual** mode (`manualPagination`, `manualSorting`, `manualFiltering`).
- `@tanstack/vue-virtual` for rows (and for columns when there are more than 50).
- Store rows in `shallowRef` and replace or append arrays. Don't deep-watch them.
- Cells render text only (no `v-html`). NULL, empty and BLOB each have distinct components.
- Inline edit: optimistic update, roll back on error, and toast the MySQL message (`vue-sonner`).
- Grid primitives live in `resources/js/components/data-grid/` (PascalCase), and state lives in the `useDataGrid` composable.
- Reuse `@/components/ui/*` (shadcn) for menus, dialogs, inputs and skeletons.

## Accessibility & UX

- Keyboard navigation with arrows, Enter to edit and Esc to cancel. Sticky header and first column.
- A loading skeleton for deferred props, and an empty state with a primary action.
