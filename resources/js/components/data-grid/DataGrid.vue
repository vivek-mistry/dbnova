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
import CellValueDialog from '@/components/data-grid/CellValueDialog.vue';
import DataGridCell from '@/components/data-grid/DataGridCell.vue';
import FilterBar from '@/components/data-grid/FilterBar.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useDataGrid } from '@/composables/useDataGrid';
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
}>();

const ROW_HEIGHT = 30;

const grid = useDataGrid({
    database: props.database,
    table: props.table,
    pageSize: props.pageSize,
    firstPage: props.firstPage,
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

const keyIndexes = computed(() =>
    props.keyColumns.map((name) =>
        props.columns.findIndex((column) => column.name === name),
    ),
);

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
const totalWidth = computed(() => dataTable.getTotalSize());

// Fetch the next page as the user nears the end of the loaded rows.
watch(virtualRows, (items) => {
    const last = items[items.length - 1];

    if (last && last.index >= tableRows.value.length - 20) {
        grid.loadMore();
    }
});

watch(
    () => [grid.sort.value, grid.filters.value],
    () => scrollElement.value?.scrollTo({ top: 0 }),
);

const dialogOpen = ref(false);
const dialogColumn = ref<string | null>(null);
const dialogRowKey = ref<string | null>(null);

function expand(row: Cell[], columnName: string): void {
    dialogColumn.value = columnName;
    dialogRowKey.value = encodeRowKey(row, keyIndexes.value);
    dialogOpen.value = true;
}

function startResize(handler: (event: unknown) => void, event: Event): void {
    event.stopPropagation();
    handler(event);
}
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col gap-3">
        <FilterBar
            :columns="columns"
            :filters="grid.filters.value"
            @change="grid.applyFilters"
        />

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
                        :style="{
                            height: `${ROW_HEIGHT}px`,
                            transform: `translateY(${virtualRow.start}px)`,
                        }"
                        role="row"
                    >
                        <div
                            v-for="cell in tableRows[
                                virtualRow.index
                            ]?.getAllCells() ?? []"
                            :key="cell.id"
                            class="flex shrink-0 items-center overflow-hidden border-r px-2"
                            :style="{ width: `${cell.column.getSize()}px` }"
                            role="cell"
                        >
                            <DataGridCell
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
