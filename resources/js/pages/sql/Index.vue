<script setup lang="ts">
import { Head, router, useHttp, usePage } from '@inertiajs/vue3';
import {
    CircleAlert,
    CircleCheck,
    CircleMinus,
    FileDown,
    Play,
    Square,
    Star,
    TriangleAlert,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import ResultGrid from '@/components/editor/ResultGrid.vue';
import SqlEditor from '@/components/editor/SqlEditor.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { formatNumber } from '@/lib/format';
import { httpErrorMessage } from '@/lib/httpError';
import {
    cancel as cancelRoute,
    index,
    run as runRoute,
    schema as schemaRoute,
} from '@/routes/sql';
import {
    index as exportsIndex,
    query as exportQueryRoute,
} from '@/routes/exports';
import { save as saveRoute } from '@/routes/sql/history';
import type {
    DestructiveStatement,
    ExportJobStatus,
    QueryHistoryItem,
    QueryRunResponse,
} from '@/types';

const props = defineProps<{
    databases?: string[];
    database: string | null;
    initialSql: string;
    rowCap: number;
    timeoutSeconds: number;
    history: QueryHistoryItem[];
    saved: QueryHistoryItem[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'SQL Editor', href: index() }],
    },
});

const page = usePage();
const editor = ref<InstanceType<typeof SqlEditor> | null>(null);

const sql = ref(props.initialSql);
const database = ref(props.database ?? '');
const schema = ref<Record<string, string[]> | undefined>(undefined);
const history = ref<QueryHistoryItem[]>(props.history);
const saved = ref<QueryHistoryItem[]>(props.saved);
const panel = ref<'history' | 'saved'>('history');

const result = ref<QueryRunResponse | null>(null);
const activeStatement = ref(0);
const error = ref<string | null>(null);
const destructive = ref<DestructiveStatement[]>([]);
const runId = ref<string | null>(null);

const runHttp = useHttp<Record<string, never>, QueryRunResponse>();
const cancelHttp = useHttp<Record<string, never>, { cancelled: boolean }>();
const schemaHttp = useHttp<
    Record<string, never>,
    { tables: Record<string, string[]> }
>();
const saveHttp = useHttp<Record<string, never>, QueryHistoryItem>();
const exportHttp = useHttp<Record<string, never>, ExportJobStatus>();

/**
 * Run the current statement again in a queued job and write every row
 * (not just the capped preview) to a CSV file.
 */
async function exportFullResult(): Promise<void> {
    if (current.value === null || result.value === null) {
        return;
    }

    const statementSql = current.value.sql;
    exportHttp.transform(() => ({
        sql: statementSql,
        database: result.value?.history.database ?? null,
        format: 'csv',
    }));

    try {
        const started = await exportHttp.post(exportQueryRoute.url());
        toast.success('CSV export started', {
            description:
                'It runs in the background; download it when it is ready.',
            action: {
                label: 'View',
                onClick: () =>
                    router.visit(
                        exportsIndex({ query: { export: started.id } }),
                    ),
            },
        });
    } catch (exception) {
        toast.error(
            httpErrorMessage(exception, 'The export could not be started.'),
        );
    }
}

const running = computed(() => runHttp.processing);
const current = computed(
    () => result.value?.statements[activeStatement.value] ?? null,
);

watch(
    database,
    async (name) => {
        schema.value = undefined;

        if (name === '') {
            return;
        }

        try {
            schema.value = (
                await schemaHttp.get(
                    schemaRoute.url({ query: { database: name } }),
                )
            ).tables;
        } catch {
            // Autocomplete is optional; the editor still works without it.
        }
    },
    { immediate: true },
);

async function run(confirmDestructive = false): Promise<void> {
    if (running.value) {
        return;
    }

    const selection = editor.value?.selection() ?? '';
    const text = selection.trim() !== '' ? selection : sql.value;

    error.value = null;
    destructive.value = [];
    runId.value = crypto.randomUUID();
    runHttp.transform(() => ({
        sql: text,
        database: database.value || null,
        runId: runId.value,
        confirmDestructive,
    }));

    try {
        const response = await runHttp.post(runRoute.url());
        result.value = response;
        activeStatement.value = Math.max(
            0,
            response.statements.findIndex((s) => s.status === 'error'),
        );

        if (activeStatement.value === 0 && response.succeeded) {
            activeStatement.value = response.statements.length - 1;
        }

        history.value = [response.history, ...history.value].slice(0, 50);
    } catch (exception) {
        const status = (
            exception as { response?: { status?: number; data?: string } }
        ).response;

        if (status?.status === 409) {
            destructive.value =
                (
                    JSON.parse(status.data ?? '{}') as {
                        destructive?: DestructiveStatement[];
                    }
                ).destructive ?? [];
        } else {
            error.value = httpErrorMessage(exception, 'The SQL could not run.');
        }
    } finally {
        runId.value = null;
    }
}

async function cancel(): Promise<void> {
    if (!runId.value) {
        return;
    }

    cancelHttp.transform(() => ({ runId: runId.value }));

    try {
        const response = await cancelHttp.post(cancelRoute.url());

        if (!response.cancelled) {
            toast.info('The query had already finished.');
        }
    } catch (exception) {
        toast.error(httpErrorMessage(exception, 'Could not cancel the query.'));
    }
}

function load(item: QueryHistoryItem): void {
    sql.value = item.sql;
    database.value = item.database ?? '';
    editor.value?.focus();
}

const saving = ref<QueryHistoryItem | null>(null);
const saveName = ref('');

function startSaving(item: QueryHistoryItem): void {
    saving.value = item;
    saveName.value = item.name ?? '';
}

async function save(name: string): Promise<void> {
    const item = saving.value;

    if (!item) {
        return;
    }

    saveHttp.transform(() => ({ name }));

    try {
        const updated = await saveHttp.patch(saveRoute.url(item.id));
        history.value = history.value.map((entry) =>
            entry.id === updated.id ? updated : entry,
        );
        saved.value = [
            ...saved.value.filter((entry) => entry.id !== updated.id),
            ...(updated.saved ? [updated] : []),
        ].sort((a, b) => (a.name ?? '').localeCompare(b.name ?? ''));
        saving.value = null;
        toast.success(
            updated.saved ? 'Query saved.' : 'Query removed from saved.',
        );
    } catch (exception) {
        toast.error(httpErrorMessage(exception, 'Could not save the query.'));
    }
}

const selectClass =
    'h-8 rounded-md border border-input bg-transparent px-2 text-sm shadow-xs dark:bg-input/30';
</script>

<template>
    <Head title="SQL Editor" />

    <div class="flex h-[calc(100svh-4rem)] gap-4 p-4">
        <div class="flex min-w-0 flex-1 flex-col gap-3">
            <div class="flex flex-wrap items-center gap-2">
                <select
                    v-model="database"
                    :class="selectClass"
                    aria-label="Database"
                    data-test="sql-database"
                >
                    <option value="">No default database</option>
                    <option
                        v-for="name in databases ??
                        (database ? [database] : [])"
                        :key="name"
                        :value="name"
                    >
                        {{ name }}
                    </option>
                </select>
                <Button
                    size="sm"
                    :disabled="running || sql.trim() === ''"
                    data-test="run-sql"
                    @click="run()"
                >
                    <Spinner v-if="running" />
                    <Play v-else />
                    Run
                </Button>
                <Button
                    v-if="running"
                    size="sm"
                    variant="outline"
                    :disabled="cancelHttp.processing"
                    @click="cancel"
                >
                    <Square />
                    Cancel
                </Button>
                <span class="text-xs text-muted-foreground">
                    Ctrl/⌘ + Enter runs the selection, or everything. Up to
                    {{ formatNumber(rowCap) }} rows per result; SELECTs stop
                    after {{ timeoutSeconds }} s.
                    <template v-if="page.props.dbnova.readOnly">
                        Read-only mode: only SELECT, SHOW, DESCRIBE and EXPLAIN
                        run.
                    </template>
                </span>
            </div>

            <div class="h-[38vh] min-h-40 shrink-0">
                <SqlEditor
                    ref="editor"
                    v-model="sql"
                    :schema="schema"
                    @run="run()"
                />
            </div>

            <p
                v-if="error"
                class="rounded-md border border-destructive/40 bg-destructive/10 p-2 text-sm"
                data-test="sql-error"
            >
                {{ error }}
            </p>

            <div
                v-if="result"
                class="flex min-h-0 flex-1 flex-col gap-2"
                data-test="sql-results"
            >
                <div class="flex flex-wrap gap-1 border-b">
                    <button
                        v-for="(statement, position) in result.statements"
                        :key="position"
                        type="button"
                        class="-mb-px flex items-center gap-1 border-b-2 px-2 py-1 text-xs"
                        :class="
                            position === activeStatement
                                ? 'border-primary font-medium'
                                : 'border-transparent text-muted-foreground'
                        "
                        @click="activeStatement = position"
                    >
                        <CircleCheck
                            v-if="statement.status === 'ok'"
                            class="size-3.5 text-emerald-600"
                        />
                        <CircleAlert
                            v-else-if="statement.status === 'error'"
                            class="size-3.5 text-destructive"
                        />
                        <CircleMinus
                            v-else
                            class="size-3.5 text-muted-foreground"
                        />
                        {{ position + 1 }} · {{ statement.keyword || 'SQL' }}
                    </button>
                    <span
                        class="ml-auto self-center text-xs text-muted-foreground"
                    >
                        {{ result.durationMs }} ms total
                    </span>
                </div>

                <template v-if="current">
                    <pre
                        class="max-h-16 shrink-0 overflow-auto rounded bg-muted px-2 py-1 font-mono text-xs whitespace-pre-wrap text-muted-foreground"
                        >{{ current.sql }}</pre>

                    <p
                        v-if="current.status === 'error'"
                        class="rounded-md border border-destructive/40 bg-destructive/10 p-2 text-sm"
                        data-test="statement-error"
                    >
                        Line {{ current.line }}: {{ current.error }}
                        <span
                            v-if="current.errorCode"
                            class="text-xs text-muted-foreground"
                            >(MySQL {{ current.errorCode }})</span
                        >
                    </p>
                    <p
                        v-else-if="current.status === 'skipped'"
                        class="text-sm text-muted-foreground"
                    >
                        Not run, because an earlier statement failed.
                    </p>
                    <template v-else>
                        <p class="text-xs text-muted-foreground">
                            <template v-if="current.rows">
                                {{ formatNumber(current.rows.length) }} row{{
                                    current.rows.length === 1 ? '' : 's'
                                }}
                                <template v-if="current.rowsTruncated">
                                    shown, more were available (add a LIMIT or
                                    export the full result)
                                </template>
                            </template>
                            <template v-else>
                                {{ formatNumber(current.affectedRows ?? 0) }}
                                row{{ current.affectedRows === 1 ? '' : 's' }}
                                affected
                                <template v-if="current.lastInsertId">
                                    · last insert id
                                    {{ current.lastInsertId }}</template
                                >
                            </template>
                            · {{ current.durationMs }} ms
                            <Button
                                v-if="current.rows"
                                variant="link"
                                size="sm"
                                class="h-auto p-0 text-xs"
                                :disabled="exportHttp.processing"
                                data-test="export-full-result"
                                @click="exportFullResult"
                            >
                                <FileDown class="size-3.5" /> Export full result
                                (CSV)
                            </Button>
                        </p>
                        <ul
                            v-if="current.warnings.length"
                            class="space-y-1 text-xs"
                        >
                            <li
                                v-for="(warning, index) in current.warnings"
                                :key="index"
                                class="flex gap-1.5 text-amber-700 dark:text-amber-400"
                            >
                                <TriangleAlert class="size-3.5 shrink-0" />
                                {{ warning.level }} {{ warning.code }}:
                                {{ warning.message }}
                            </li>
                        </ul>
                        <ResultGrid
                            v-if="current.columns && current.rows"
                            :columns="current.columns"
                            :rows="current.rows"
                        />
                    </template>
                </template>
            </div>
        </div>

        <aside class="hidden w-72 shrink-0 flex-col gap-2 lg:flex">
            <div class="flex gap-1 border-b text-sm">
                <button
                    v-for="tab in ['history', 'saved'] as const"
                    :key="tab"
                    type="button"
                    class="-mb-px border-b-2 px-2 py-1 capitalize"
                    :class="
                        panel === tab
                            ? 'border-primary font-medium'
                            : 'border-transparent text-muted-foreground'
                    "
                    @click="panel = tab"
                >
                    {{ tab }}
                </button>
            </div>
            <ul class="min-h-0 flex-1 space-y-1 overflow-y-auto">
                <li
                    v-for="item in panel === 'history' ? history : saved"
                    :key="item.id"
                    class="group rounded-md border p-2 text-xs hover:bg-muted/40"
                >
                    <div class="flex items-center gap-1">
                        <CircleAlert
                            v-if="item.status === 'error'"
                            class="size-3 text-destructive"
                        />
                        <span class="truncate font-medium">{{
                            item.name ?? item.database ?? 'No database'
                        }}</span>
                        <button
                            type="button"
                            class="ml-auto text-muted-foreground hover:text-foreground"
                            :aria-label="
                                item.saved ? 'Rename or unsave' : 'Save query'
                            "
                            @click="startSaving(item)"
                        >
                            <Star
                                class="size-3.5"
                                :class="{
                                    'fill-amber-400 text-amber-500': item.saved,
                                }"
                            />
                        </button>
                    </div>
                    <button
                        type="button"
                        class="mt-1 block w-full truncate text-left font-mono text-muted-foreground"
                        :title="item.sql"
                        @click="load(item)"
                    >
                        {{ item.sql }}
                    </button>
                </li>
                <li
                    v-if="(panel === 'history' ? history : saved).length === 0"
                    class="p-4 text-center text-xs text-muted-foreground"
                >
                    {{
                        panel === 'history'
                            ? 'Queries you run appear here.'
                            : 'Star a query in History to save it.'
                    }}
                </li>
            </ul>
        </aside>

        <Dialog
            :open="destructive.length > 0"
            @update:open="(open) => !open && (destructive = [])"
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Run destructive SQL?</DialogTitle>
                    <DialogDescription>
                        These statements drop objects or change every row of a
                        table. They cannot be undone.
                    </DialogDescription>
                </DialogHeader>
                <ul class="space-y-1 text-sm">
                    <li
                        v-for="statement in destructive"
                        :key="statement.line"
                        class="rounded bg-muted px-2 py-1 font-mono text-xs"
                    >
                        Line {{ statement.line }}: {{ statement.sql }}
                    </li>
                </ul>
                <DialogFooter class="gap-2">
                    <Button variant="secondary" @click="destructive = []"
                        >Cancel</Button
                    >
                    <Button
                        variant="destructive"
                        data-test="confirm-destructive"
                        @click="run(true)"
                    >
                        Run anyway
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog
            :open="saving !== null"
            @update:open="(open) => !open && (saving = null)"
        >
            <DialogContent>
                <form class="space-y-4" @submit.prevent="save(saveName)">
                    <DialogHeader>
                        <DialogTitle>Save query</DialogTitle>
                        <DialogDescription>
                            Saved queries are only visible to your MySQL
                            account.
                        </DialogDescription>
                    </DialogHeader>
                    <Input
                        v-model="saveName"
                        maxlength="100"
                        placeholder="Name"
                        aria-label="Query name"
                        autofocus
                    />
                    <DialogFooter class="gap-2">
                        <Button
                            v-if="saving?.saved"
                            type="button"
                            variant="outline"
                            @click="save('')"
                        >
                            Unsave
                        </Button>
                        <Button
                            type="submit"
                            :disabled="
                                saveName.trim() === '' || saveHttp.processing
                            "
                        >
                            Save
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
