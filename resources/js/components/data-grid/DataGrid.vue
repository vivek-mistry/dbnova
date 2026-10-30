<script setup lang="ts">
import { ArrowDown, ArrowUp, KeyRound } from '@lucide/vue';
import {
    columnResizingFeature,
    columnSizingFeature,
    tableFeatures,
    useTable,
} from '@tanstack/vue-table';
import type { ColumnDef } from '@tanstack/vue-table';
import { useVirtualizer } from '@tanstack/vue-virtual';
import { computed, ref, watch } from 'vue';
import CellEditor from '@/components/data-grid/CellEditor.vue';
import CellValueDialog from '@/components/data-grid/CellValueDialog.vue';
import DataGridCell from '@/components/data-grid/DataGridCell.vue';
import DeleteRowsDialog from '@/components/data-grid/DeleteRowsDialog.vue';
import FilterBar from '@/components/data-grid/FilterBar.vue';
import InsertRowDialog from '@/components/data-grid/InsertRowDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useDataGrid } from '@/composables/useDataGrid';
import { useRecordEditor } from '@/composables/useRecordEditor';
import { formatNumber } from '@/lib/format';
import { encodeRowKey } from '@/lib/rowKey';
import type { Cell, ColumnInfo, TablePage } from '@/types';

const props = defineProps<{
    database: string;
    table: string;
    columns: ColumnInfo[];
    keyColumns: string[];
    pageSize: number;
    firstPage: TablePage;
    approximateRows: number | null;
    editable: boolean;
}>();

const ROW_HEIGHT = 30;
const GUTTER_WIDTH = 32;

const grid = useDataGrid({
    database: props.database,
    table: props.table,
    pageSize: props.pageSize,
    firstPage: props.firstPage,
});

const keyIndexes = computed(() =>
    props.keyColumns.map((name) =>
        props.columns.findIndex((column) => column.name === name),
    ),
);

const editor = useRecordEditor({
    database: props.database,
    table: props.table,
    rows: grid.rows,
    keyIndexes: () => keyIndexes.value,
});

const features = tableFeatures({ columnSizingFeature, columnResizingFeature });

const columnDefs: ColumnDef<typeof features, Cell[]>[] = props.columns.map(
    (column, index) => ({
        id: column.name,
        header: column.name,
        accessorFn: (row: Cell[]) => row[index],
        size: column.isBinary ? 120 : 180,
        minSize: 60,
        maxSize: 800,
    }),
);

const dataTable = useTable({
    features,
    columns: columnDefs,
    data: grid.rows,
    columnResizeMode: 'onChange',
});

const scrollElement = ref<HTMLElement | null>(null);
const tableRows = computed(() => dataTable.getRowModel().rows);

const rowVirtualizer = useVirtualizer(
    computed(() => ({
        count: tableRows.value.length,
        getScrollElement: () => scrollElement.value,
        estimateSize: () => ROW_HEIGHT,
        overscan: 12,
    })),
);

const virtualRows = computed(() => rowVirtualizer.value.getVirtualItems());
const totalHeight = computed(() => rowVirtualizer.value.getTotalSize());
const totalWidth = computed(
    () => dataTable.getTotalSize() + (props.editable ? GUTTER_WIDTH : 0),
);

/* Selection */

const selectedKeys = ref(new Set<string>());

function rowKey(rowIndex: number): string | null {
    const row = grid.rows.value[rowIndex];

    return row ? encodeRowKey(row, keyIndexes.value) : null;
}

function isSelected(rowIndex: number): boolean {
    const key = rowKey(rowIndex);

    return key !== null && selectedKeys.value.has(key);
}

function toggleSelected(rowIndex: number): void {
    const key = rowKey(rowIndex);

    if (key === null) {
        return;
    }

    const next = new Set(selectedKeys.value);

    if (next.has(key)) {
        next.delete(key);
    } else {
        next.add(key);
    }

    selectedKeys.value = next;
}

const allLoadedSelected = computed(
    () =>
        grid.rows.value.length > 0 &&
        selectedKeys.value.size === grid.rows.value.length,
);

function toggleAllLoaded(): void {
    selectedKeys.value = allLoadedSelected.value
        ? new Set()
        : new Set(
              grid.rows.value
                  .map((row) => encodeRowKey(row, keyIndexes.value))
                  .filter((key): key is string => key !== null),
          );
}

// Fetch the next page as the user nears the end of the loaded rows.
watch(virtualRows, (items) => {
    const last = items[items.length - 1];

    if (last && last.index >= tableRows.value.length - 20) {
        void grid.loadMore();
    }
});

watch(
    () => [grid.sort.value, grid.filters.value],
    () => {
        scrollElement.value?.scrollTo({ top: 0 });
        selectedKeys.value = new Set();
    },
);

/* Full-value dialog */

const dialogOpen = ref(false);
const dialogColumn = ref<string | null>(null);
const dialogRowKey = ref<string | null>(null);

function expand(row: Cell[], columnName: string): void {
    dialogColumn.value = columnName;
    dialogRowKey.value = encodeRowKey(row, keyIndexes.value);
    dialogOpen.value = true;
}

/* Inline editing */

const editing = ref<{ rowIndex: number; columnIndex: number } | null>(null);

function canEdit(cell: Cell, column: ColumnInfo): boolean {
    return (
        props.editable &&
        !column.isBinary &&
        !column.extra.toUpperCase().includes('GENERATED') &&
        (cell === null || typeof cell !== 'object')
    );
}

function startEditing(rowIndex: number, columnIndex: number): void {
    const column = props.columns[columnIndex];
    const cell = grid.rows.value[rowIndex]?.[columnIndex] ?? null;

    if (column && canEdit(cell, column)) {
        editing.value = { rowIndex, columnIndex };
    }
}

function editorValue(rowIndex: number, columnIndex: number): string | null {
    const cell = grid.rows.value[rowIndex]?.[columnIndex] ?? null;

    return cell === null ? null : String(cell);
}

function saveEdit(value: string | null): void {
    const target = editing.value;
    const column = target ? props.columns[target.columnIndex] : undefined;
    editing.value = null;

    if (target && column) {
        void editor.updateCell(
            target.rowIndex,
            target.columnIndex,
            column.name,
            value,
        );
    }
}

async function deleteSelected(done: () => void): Promise<void> {
    if (await editor.deleteRows([...selectedKeys.value])) {
        selectedKeys.value = new Set();
        done();
    }
}

async function insert(
    values: Record<string, string | null>,
    done: () => void,
): Promise<void> {
    if (await editor.insertRow(values)) {
        done();
        await grid.reload();
    }
}

function startResize(handler: (event: unknown) => void, event: Event): void {
    event.stopPropagation();
    handler(event);
}
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col gap-3">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <FilterBar
                :columns="columns"
                :filters="grid.filters.value"
                @change="grid.applyFilters"
            />
            <div v-if="editable" class="flex gap-2">
                <DeleteRowsDialog
                    v-if="selectedKeys.size > 0"
                    :count="selectedKeys.size"
                    :table="table"
                    :deleting="editor.deleting()"
                    @confirm="deleteSelected"
                />
                <InsertRowDialog
                    :columns="columns"
                    :saving="editor.inserting()"
                    @insert="insert"
                />
            </div>
        </div>

        <p
            v-if="grid.error.value"
            class="rounded-md border border-destructive/40 bg-destructive/10 p-2 text-sm"
            data-test="grid-error"
        >
            {{ grid.error.value }}
        </p>

        <div
            ref="scrollElement"
            class="relative min-h-[320px] flex-1 overflow-auto rounded-md border font-mono text-xs"
            data-test="data-grid"
        >
            <div :style="{ width: `${totalWidth}px` }">
                <div
                    class="sticky top-0 z-10 flex border-b bg-muted font-sans text-xs font-medium"
                    role="row"
                >
                    <div
                        v-if="editable"
                        class="flex shrink-0 items-center justify-center border-r"
                        :style="{ width: `${GUTTER_WIDTH}px` }"
                    >
                        <input
                            type="checkbox"
                            :checked="allLoadedSelected"
                            aria-label="Select all loaded rows"
                            @change="toggleAllLoaded"
                        />
                    </div>
                    <div
                        v-for="header in dataTable.getHeaderGroups()[0]
                            ?.headers ?? []"
                        :key="header.id"
                        class="relative flex shrink-0 cursor-pointer items-center gap-1 border-r px-2 py-1.5 select-none hover:bg-muted-foreground/10"
                        :style="{ width: `${header.getSize()}px` }"
                        role="columnheader"
                        :aria-sort="
                            grid.sort.value?.column === header.id
                                ? grid.sort.value.direction === 'asc'
                                    ? 'ascending'
                                    : 'descending'
                                : 'none'
                        "
                        @click="grid.toggleSort(header.id)"
                    >
                        <KeyRound
                            v-if="keyColumns.includes(header.id)"
                            class="size-3 shrink-0 text-amber-600"
                        />
                        <span class="truncate">{{ header.id }}</span>
                        <ArrowUp
                            v-if="
                                grid.sort.value?.column === header.id &&
                                grid.sort.value.direction === 'asc'
                            "
                            class="size-3 shrink-0"
                        />
                        <ArrowDown
                            v-else-if="grid.sort.value?.column === header.id"
                            class="size-3 shrink-0"
                        />
                        <div
                            class="absolute top-0 right-0 h-full w-1.5 cursor-col-resize hover:bg-primary/40"
                            :class="{
                                'bg-primary/60': header.column.getIsResizing(),
                            }"
                            @mousedown="
                                startResize(header.getResizeHandler(), $event)
                            "
                            @touchstart="
                                startResize(header.getResizeHandler(), $event)
                            "
                            @click.stop
                        />
                    </div>
                </div>

                <div
                    class="relative"
                    :style="{ height: `${totalHeight}px` }"
                    role="rowgroup"
                >
                    <div
                        v-for="virtualRow in virtualRows"
                        :key="virtualRow.key as number"
                        class="absolute left-0 flex w-full border-b hover:bg-muted/40"
                        :class="{
                            'bg-primary/5': isSelected(virtualRow.index),
                        }"
                        :style="{
                            height: `${ROW_HEIGHT}px`,
                            transform: `translateY(${virtualRow.start}px)`,
                        }"
                        role="row"
                    >
                        <div
                            v-if="editable"
                            class="flex shrink-0 items-center justify-center border-r"
                            :style="{ width: `${GUTTER_WIDTH}px` }"
                        >
                            <input
                                type="checkbox"
                                :checked="isSelected(virtualRow.index)"
                                aria-label="Select row"
                                @change="toggleSelected(virtualRow.index)"
                            />
                        </div>
                        <div
                            v-for="(cell, columnIndex) in tableRows[
                                virtualRow.index
                            ]?.getAllCells() ?? []"
                            :key="cell.id"
                            class="flex shrink-0 items-center overflow-hidden border-r px-2"
                            :class="{
                                'cursor-text':
                                    editable && !columns[columnIndex]?.isBinary,
                            }"
                            :style="{ width: `${cell.column.getSize()}px` }"
                            role="cell"
                            @dblclick="
                                startEditing(virtualRow.index, columnIndex)
                            "
                        >
                            <CellEditor
                                v-if="
                                    editing?.rowIndex === virtualRow.index &&
                                    editing.columnIndex === columnIndex
                                "
                                :value="
                                    editorValue(virtualRow.index, columnIndex)
                                "
                                :nullable="
                                    columns[columnIndex]?.nullable ?? false
                                "
                                @save="saveEdit"
                                @cancel="editing = null"
                            />
                            <DataGridCell
                                v-else
                                :cell="cell.getValue() as Cell"
                                @expand="
                                    expand(
                                        tableRows[virtualRow.index]!.original,
                                        cell.column.id,
                                    )
                                "
                            />
                        </div>
                    </div>
                </div>

                <p
                    v-if="tableRows.length === 0 && !grid.loading.value"
                    class="p-6 text-center font-sans text-sm text-muted-foreground"
                >
                    No rows{{
                        grid.filters.value.length ? ' match the filters' : ''
                    }}.
                </p>
            </div>
        </div>

        <div
            class="flex flex-wrap items-center gap-3 text-xs text-muted-foreground"
            data-test="grid-footer"
        >
            <span>
                {{ formatNumber(tableRows.length) }} rows loaded{{
                    grid.lastPage.value.hasMore ? ' (scroll for more)' : ''
                }}
            </span>
            <span v-if="grid.exactCount.value !== null">
                · {{ formatNumber(grid.exactCount.value) }} rows match
            </span>
            <span v-else-if="grid.filters.value.length === 0">
                · ~{{ formatNumber(approximateRows) }} rows (estimate)
            </span>
            <Button
                v-if="grid.exactCount.value === null"
                variant="link"
                size="sm"
                class="h-auto p-0 text-xs"
                @click="grid.countRows"
            >
                Count exactly
            </Button>
            <Badge
                variant="outline"
                :title="
                    grid.lastPage.value.strategy === 'keyset'
                        ? 'Paging by key: deep pages are as fast as the first'
                        : 'Paging by offset: deep pages get slower on big tables'
                "
            >
                {{ grid.lastPage.value.strategy }}
            </Badge>
            <span>{{ grid.lastPage.value.durationMs }} ms</span>
            <span v-if="editable">· Double-click a cell to edit</span>
            <Spinner v-if="grid.loading.value" class="size-3" />
        </div>

        <CellValueDialog
            v-model:open="dialogOpen"
            :database="database"
            :table="table"
            :column="dialogColumn"
            :row-key="dialogRowKey"
        />
    </div>
</template>
