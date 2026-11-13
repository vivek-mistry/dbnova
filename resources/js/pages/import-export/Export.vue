<script setup lang="ts">
import { Head, useHttp } from '@inertiajs/vue3';
import { FileDown } from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import ExportStatusCard from '@/components/export/ExportStatusCard.vue';
import ImportExportTabs from '@/components/import/ImportExportTabs.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { formatBytes } from '@/lib/format';
import { httpErrorMessage } from '@/lib/httpError';
import {
    cancel as cancelRoute,
    destroy as destroyRoute,
    index,
    show as showRoute,
    store as storeRoute,
    tables as tablesRoute,
} from '@/routes/exports';
import type {
    ExportContent,
    ExportDatabase,
    ExportFormat,
    ExportJobStatus,
    ExportStatus,
    ExportTable,
} from '@/types';

const props = defineProps<{
    databases?: ExportDatabase[];
    database: string | null;
    table: string | null;
    openExport: ExportJobStatus | null;
    exports: ExportJobStatus[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Import / Export', href: index() }],
    },
});

const POLL_MS = 2000;

const source = ref(props.database ?? '');
const scope = ref<'all' | 'selected'>(props.table ? 'selected' : 'all');
const tables = ref<ExportTable[] | null>(null);
const selected = ref<string[]>([]);
const filter = ref('');
const format = ref<ExportFormat>('sql.gz');
const content = ref<ExportContent>('both');
const includePrograms = ref(true);
const csvTable = ref(props.table ?? '');
const isCsv = computed(
    () => format.value === 'csv' || format.value === 'csv.gz',
);

const job = ref<ExportJobStatus | null>(props.openExport);
const history = ref<ExportJobStatus[]>(props.exports);

const tablesHttp = useHttp<Record<string, never>, ExportTable[]>();
const createHttp = useHttp<Record<string, never>, ExportJobStatus>();
const pollHttp = useHttp<Record<string, never>, ExportJobStatus>();
const cancelHttp = useHttp<Record<string, never>, ExportJobStatus>();
const deleteHttp = useHttp<Record<string, never>, ExportJobStatus>();

const visibleTables = computed(() => {
    const needle = filter.value.trim().toLowerCase();

    return (tables.value ?? []).filter(
        (table) => needle === '' || table.name.toLowerCase().includes(needle),
    );
});

const selectedBytes = computed(() =>
    (tables.value ?? [])
        .filter((table) => selected.value.includes(table.name))
        .reduce((sum, table) => sum + (table.sizeBytes ?? 0), 0),
);

const sourceInfo = computed(
    () => props.databases?.find((db) => db.name === source.value) ?? null,
);

const canExport = computed(
    () =>
        source.value !== '' &&
        !createHttp.processing &&
        (isCsv.value
            ? csvTable.value !== ''
            : scope.value === 'all' || selected.value.length > 0),
);

// Opened from a table page (?table=): preselect that table once its list has loaded.
let pendingTable = props.table;

watch(
    source,
    async (name) => {
        tables.value = null;
        selected.value = [];
        filter.value = '';

        if (pendingTable === null) {
            csvTable.value = '';
        }

        if (name === '') {
            return;
        }

        try {
            const loaded = await tablesHttp.get(tablesRoute.url(name));

            if (source.value === name) {
                tables.value = loaded;

                if (
                    pendingTable !== null &&
                    loaded.some((table) => table.name === pendingTable)
                ) {
                    selected.value = [pendingTable];
                    csvTable.value = pendingTable;
                }

                pendingTable = null;
            }
        } catch (error) {
            toast.error(
                httpErrorMessage(error, 'The tables could not be loaded.'),
            );
        }
    },
    { immediate: true },
);

function toggle(name: string, checked: boolean | 'indeterminate'): void {
    selected.value =
        checked === true
            ? [...selected.value, name]
            : selected.value.filter((item) => item !== name);
}

function selectVisible(checked: boolean): void {
    const names = visibleTables.value.map((table) => table.name);

    selected.value = checked
        ? [...new Set([...selected.value, ...names])]
        : selected.value.filter((name) => !names.includes(name));
}

async function startExport(): Promise<void> {
    createHttp.transform(() => ({
        database: source.value,
        tables: isCsv.value
            ? [csvTable.value]
            : scope.value === 'all'
              ? []
              : selected.value,
        format: format.value,
        content: content.value,
        includePrograms: includePrograms.value,
    }));

    try {
        job.value = await createHttp.post(storeRoute.url());
        remember(job.value);
    } catch (error) {
        toast.error(
            httpErrorMessage(error, 'The export could not be started.'),
        );
    }
}

function remember(status: ExportJobStatus): void {
    history.value = [
        status,
        ...history.value.filter((item) => item.id !== status.id),
    ].slice(0, 20);
}

function isActive(status: ExportStatus): boolean {
    return status === 'queued' || status === 'exporting';
}

let timer: number | undefined;

watch(
    () => job.value?.status,
    (status) => {
        window.clearTimeout(timer);

        if (status !== undefined && isActive(status)) {
            timer = window.setTimeout(poll, POLL_MS);
        }
    },
);

async function poll(): Promise<void> {
    if (job.value === null) {
        return;
    }

    try {
        job.value = await pollHttp.get(showRoute.url(job.value.id));
        remember(job.value);
    } catch {
        // A missed poll is retried on the next tick.
    }

    if (job.value !== null && isActive(job.value.status)) {
        timer = window.setTimeout(poll, POLL_MS);
    }
}

async function cancelJob(): Promise<void> {
    if (job.value === null) {
        return;
    }

    try {
        job.value = await cancelHttp.post(cancelRoute.url(job.value.id));
        remember(job.value);
    } catch (error) {
        toast.error(
            httpErrorMessage(error, 'The export could not be cancelled.'),
        );
    }
}

async function deleteFile(): Promise<void> {
    if (job.value === null) {
        return;
    }

    try {
        job.value = await deleteHttp.delete(destroyRoute.url(job.value.id));
        remember(job.value);
        toast.success('The export file was deleted.');
    } catch (error) {
        toast.error(httpErrorMessage(error, 'The file could not be deleted.'));
    }
}

async function open(item: ExportJobStatus): Promise<void> {
    try {
        // Reloading gives a fresh signed download link.
        job.value = await pollHttp.get(showRoute.url(item.id));
    } catch (error) {
        toast.error(httpErrorMessage(error, 'The export could not be loaded.'));
    }
}

onBeforeUnmount(() => window.clearTimeout(timer));

const statusLabels: Record<ExportStatus, string> = {
    queued: 'Queued',
    exporting: 'Exporting',
    completed: 'Completed',
    failed: 'Failed',
    cancelled: 'Cancelled',
    interrupted: 'Interrupted',
    expired: 'Expired',
};

function badgeVariant(
    status: ExportStatus,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (status === 'failed' || status === 'interrupted') {
        return 'destructive';
    }

    return status === 'completed' ? 'default' : 'secondary';
}

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-2 text-sm shadow-xs dark:bg-input/30';
</script>

<template>
    <Head title="Export" />

    <div class="flex flex-1 flex-col gap-6 p-4 lg:p-6">
        <ImportExportTabs active="export" />

        <div>
            <h1 class="text-xl font-semibold tracking-tight">Export SQL</h1>
            <p class="text-sm text-muted-foreground">
                Dump a database, or some of its tables, with
                <code>mysqldump</code> in a consistent snapshot (the file names
                no database, so it imports into any database), or export one
                table as CSV. To export a query's result, use "Export full
                result" in the SQL editor.
            </p>
        </div>

        <div class="grid max-w-3xl gap-4 rounded-lg border p-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="grid gap-1.5 text-sm">
                    <span class="font-medium">Database</span>
                    <Skeleton
                        v-if="databases === undefined"
                        class="h-9 w-full"
                    />
                    <select
                        v-else
                        v-model="source"
                        :class="selectClass"
                        data-test="export-database"
                    >
                        <option value="" disabled>Choose a database…</option>
                        <option
                            v-for="db in databases"
                            :key="db.name"
                            :value="db.name"
                        >
                            {{ db.name }} ({{ formatBytes(db.sizeBytes) }})
                        </option>
                    </select>
                </label>

                <label class="grid gap-1.5 text-sm">
                    <span class="font-medium">Format</span>
                    <select
                        v-model="format"
                        :class="selectClass"
                        data-test="export-format"
                    >
                        <optgroup label="SQL dump (re-importable)">
                            <option value="sql.gz">.sql.gz — compressed</option>
                            <option value="sql">.sql</option>
                        </optgroup>
                        <optgroup label="CSV (one table, for spreadsheets)">
                            <option value="csv">.csv</option>
                            <option value="csv.gz">.csv.gz — compressed</option>
                        </optgroup>
                    </select>
                </label>
            </div>

            <label v-if="source !== '' && isCsv" class="grid gap-1.5 text-sm">
                <span class="font-medium">Table</span>
                <Skeleton v-if="tables === null" class="h-9 w-full" />
                <select
                    v-else
                    v-model="csvTable"
                    :class="selectClass"
                    data-test="export-csv-table"
                >
                    <option value="" disabled>Choose a table…</option>
                    <option
                        v-for="table in tables"
                        :key="table.name"
                        :value="table.name"
                    >
                        {{ table.name
                        }}{{ table.type === 'VIEW' ? ' (view)' : '' }}
                    </option>
                </select>
                <span class="text-xs text-muted-foreground">
                    UTF-8 with a header row. NULL is an empty field and text is
                    always quoted, so empty text stays "". Binary values are
                    written as hex (0x…). Values are written exactly as stored:
                    if the data comes from untrusted users, open the file in a
                    spreadsheet with Data → From Text rather than
                    double-clicking it, since cells starting with = + - @ can
                    run as formulas.
                </span>
            </label>

            <div v-if="source !== '' && !isCsv" class="grid gap-2 text-sm">
                <span class="font-medium">Tables</span>
                <div class="flex gap-4">
                    <label class="flex items-center gap-2">
                        <input v-model="scope" type="radio" value="all" />
                        All tables<template v-if="sourceInfo">
                            ({{ sourceInfo.tableCount }})</template
                        >
                    </label>
                    <label class="flex items-center gap-2">
                        <input
                            v-model="scope"
                            type="radio"
                            value="selected"
                            data-test="export-scope-selected"
                        />
                        Choose tables
                    </label>
                </div>

                <div
                    v-if="scope === 'selected'"
                    class="flex flex-col gap-2 rounded-md border p-2"
                >
                    <Skeleton v-if="tables === null" class="h-24 w-full" />
                    <template v-else>
                        <div class="flex items-center gap-2">
                            <Input
                                v-model="filter"
                                placeholder="Filter tables…"
                                class="h-8"
                            />
                            <Button
                                variant="ghost"
                                size="sm"
                                @click="selectVisible(true)"
                                >Select all</Button
                            >
                            <Button
                                variant="ghost"
                                size="sm"
                                @click="selectVisible(false)"
                                >None</Button
                            >
                        </div>
                        <ul
                            class="max-h-64 overflow-y-auto"
                            data-test="export-tables"
                        >
                            <li
                                v-for="table in visibleTables"
                                :key="table.name"
                            >
                                <label
                                    class="flex items-center gap-2 rounded px-1 py-1 hover:bg-muted"
                                >
                                    <Checkbox
                                        :model-value="
                                            selected.includes(table.name)
                                        "
                                        @update:model-value="
                                            toggle(table.name, $event)
                                        "
                                    />
                                    <span class="min-w-0 flex-1 truncate">
                                        {{ table.name }}
                                        <span
                                            v-if="table.type === 'VIEW'"
                                            class="text-xs text-muted-foreground"
                                            >view</span
                                        >
                                    </span>
                                    <span
                                        v-if="table.sizeBytes !== null"
                                        class="text-xs text-muted-foreground"
                                    >
                                        {{ formatBytes(table.sizeBytes) }}
                                    </span>
                                </label>
                            </li>
                            <li
                                v-if="visibleTables.length === 0"
                                class="px-1 py-1 text-muted-foreground"
                            >
                                No tables match.
                            </li>
                        </ul>
                        <p class="text-xs text-muted-foreground">
                            {{ selected.length }} selected,
                            {{ formatBytes(selectedBytes) }} on disk (data +
                            indexes)
                        </p>
                    </template>
                </div>
            </div>

            <div v-if="!isCsv" class="grid gap-4 sm:grid-cols-2">
                <label class="grid gap-1.5 text-sm">
                    <span class="font-medium">Content</span>
                    <select
                        v-model="content"
                        :class="selectClass"
                        data-test="export-content"
                    >
                        <option value="both">Structure and data</option>
                        <option value="structure">Structure only</option>
                        <option value="data">Data only</option>
                    </select>
                </label>
                <label
                    v-if="content !== 'data'"
                    class="flex items-start gap-2 pt-6 text-sm"
                >
                    <Checkbox
                        v-model="includePrograms"
                        class="mt-0.5"
                        data-test="export-programs"
                    />
                    <span>
                        <span class="font-medium">
                            Routines, triggers and events
                        </span>
                        <span class="block text-xs text-muted-foreground">
                            Routines and events always cover the whole database.
                            Importing them needs a trust confirmation.
                        </span>
                    </span>
                </label>
            </div>

            <div>
                <Button
                    :disabled="!canExport"
                    data-test="export-start"
                    @click="startExport"
                >
                    <FileDown /> Export
                </Button>
            </div>
        </div>

        <div v-if="job" class="max-w-3xl">
            <ExportStatusCard
                :job="job"
                :cancelling="cancelHttp.processing"
                :deleting="deleteHttp.processing"
                @cancel="cancelJob"
                @dismiss="job = null"
                @delete="deleteFile"
            />
        </div>

        <div class="max-w-3xl">
            <h2 class="mb-2 text-sm font-medium">Recent exports</h2>
            <p
                v-if="history.length === 0"
                class="text-sm text-muted-foreground"
            >
                No exports yet.
            </p>
            <ul
                v-else
                class="divide-y rounded-lg border text-sm"
                data-test="export-history"
            >
                <li
                    v-for="item in history"
                    :key="item.id"
                    class="flex items-center justify-between gap-3 px-3 py-2"
                >
                    <button
                        type="button"
                        class="min-w-0 truncate text-left hover:underline"
                        @click="open(item)"
                    >
                        {{ item.fileName }}
                        <span
                            v-if="item.status === 'completed'"
                            class="text-muted-foreground"
                        >
                            · {{ formatBytes(item.fileBytes) }}
                        </span>
                    </button>
                    <div class="flex shrink-0 items-center gap-3">
                        <span class="text-xs text-muted-foreground">
                            {{ new Date(item.createdAt).toLocaleString() }}
                        </span>
                        <Badge :variant="badgeVariant(item.status)">
                            {{ statusLabels[item.status] }}
                        </Badge>
                    </div>
                </li>
            </ul>
        </div>
    </div>
</template>
