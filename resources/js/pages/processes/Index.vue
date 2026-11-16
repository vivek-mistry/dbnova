<script setup lang="ts">
import { Head, useHttp, usePage } from '@inertiajs/vue3';
import { CircleStop, EllipsisVertical, Pause, Play, Unplug } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import ServerTabs from '@/components/server/ServerTabs.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { httpErrorMessage } from '@/lib/httpError';
import {
    index,
    kill as killRoute,
    list as listRoute,
} from '@/routes/processes';
import type { ProcessList, ProcessRow } from '@/types';

const props = defineProps<{
    processes?: ProcessList;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Processes', href: index() }],
    },
});

const POLL_MS = 2000;

const page = usePage();
const readOnly = computed(() => page.props.dbnova.readOnly);

const data = ref<ProcessList | null>(props.processes ?? null);
const search = ref('');
const showSleeping = ref(false);
const live = ref(true);
const updatedAt = ref<Date | null>(props.processes ? new Date() : null);

const listHttp = useHttp<Record<string, never>, ProcessList>();
const killHttp = useHttp<
    Record<string, never>,
    { killed: boolean; message: string }
>();

const pending = ref<{ row: ProcessRow; mode: 'query' | 'connection' } | null>(
    null,
);

watch(
    () => props.processes,
    (loaded) => {
        if (loaded && data.value === null) {
            data.value = loaded;
            updatedAt.value = new Date();
        }
    },
);

const rows = computed(() => {
    const needle = search.value.trim().toLowerCase();

    return (data.value?.processes ?? []).filter((row) => {
        if (!showSleeping.value && row.command === 'Sleep') {
            return false;
        }

        if (needle === '') {
            return true;
        }

        return [row.id, row.user, row.host, row.database, row.state, row.info]
            .filter((value) => value !== null)
            .some((value) => String(value).toLowerCase().includes(needle));
    });
});

const sleepingCount = computed(
    () =>
        (data.value?.processes ?? []).filter((row) => row.command === 'Sleep')
            .length,
);

let timer: number | undefined;

async function refresh(): Promise<void> {
    window.clearTimeout(timer);

    try {
        data.value = await listHttp.get(listRoute.url());
        updatedAt.value = new Date();
    } catch {
        // A missed refresh is retried on the next tick.
    }

    schedule();
}

function schedule(): void {
    window.clearTimeout(timer);

    if (live.value && document.visibilityState === 'visible') {
        timer = window.setTimeout(refresh, POLL_MS);
    }
}

function onVisibility(): void {
    if (document.visibilityState === 'visible' && live.value) {
        void refresh();
    } else {
        window.clearTimeout(timer);
    }
}

watch(live, (on) => (on ? void refresh() : window.clearTimeout(timer)));

onMounted(() => {
    document.addEventListener('visibilitychange', onVisibility);
    schedule();
});

onBeforeUnmount(() => {
    window.clearTimeout(timer);
    document.removeEventListener('visibilitychange', onVisibility);
});

/** A statement is running: "Query" (text protocol) or "Execute" (a prepared statement). */
function canStopQuery(row: ProcessRow): boolean {
    return (
        (row.command === 'Query' || row.command === 'Execute') &&
        row.info !== null
    );
}

/** "42 s", "3 min 5 s", "2 h 4 min": a process list needs seconds. */
function elapsed(seconds: number): string {
    if (seconds < 60) {
        return `${seconds} s`;
    }

    if (seconds < 3600) {
        return `${Math.floor(seconds / 60)} min ${seconds % 60} s`;
    }

    return `${Math.floor(seconds / 3600)} h ${Math.floor((seconds % 3600) / 60)} min`;
}

async function confirmKill(): Promise<void> {
    if (pending.value === null) {
        return;
    }

    const { row, mode } = pending.value;
    killHttp.transform(() => ({ mode, confirm: true }));

    try {
        const response = await killHttp.post(killRoute.url(row.id));
        (response.killed ? toast.success : toast.info)(response.message);
        pending.value = null;
        await refresh();
    } catch (error) {
        toast.error(
            httpErrorMessage(error, 'The process could not be stopped.'),
        );
    }
}
</script>

<template>
    <Head title="Processes" />

    <div class="flex flex-1 flex-col gap-4 p-4 lg:p-6">
        <div class="flex flex-col gap-3">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">Server</h1>
                <p class="text-sm text-muted-foreground">
                    What the MySQL server is doing right now. Without the
                    PROCESS privilege, MySQL shows only your own account's
                    connections.
                </p>
            </div>
            <ServerTabs active="processes" />
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <Input
                v-model="search"
                placeholder="Filter by id, user, host, database or SQL…"
                class="h-9 w-full max-w-sm"
                data-test="process-search"
            />
            <label class="flex items-center gap-2 text-sm">
                <Checkbox v-model="showSleeping" data-test="process-sleeping" />
                Show idle connections ({{ sleepingCount }})
            </label>
            <div
                class="ml-auto flex items-center gap-2 text-xs text-muted-foreground"
            >
                <Spinner v-if="listHttp.processing" class="size-3" />
                <span v-if="updatedAt">
                    Updated {{ updatedAt.toLocaleTimeString() }}
                </span>
                <Button
                    variant="outline"
                    size="sm"
                    data-test="process-live"
                    @click="live = !live"
                >
                    <template v-if="live"><Pause /> Pause</template>
                    <template v-else><Play /> Resume</template>
                </Button>
            </div>
        </div>

        <p
            v-if="data?.error"
            class="rounded-md border border-destructive/40 bg-destructive/10 p-3 text-sm"
        >
            {{ data.error }}
        </p>

        <Skeleton v-if="data === null" class="h-64 w-full" />

        <div v-else class="overflow-x-auto rounded-lg border">
            <table class="w-full text-sm" data-test="process-table">
                <thead
                    class="bg-muted/50 text-left text-xs text-muted-foreground"
                >
                    <tr>
                        <th class="px-3 py-2 font-medium">Id</th>
                        <th class="px-3 py-2 font-medium">User</th>
                        <th class="px-3 py-2 font-medium">Database</th>
                        <th class="px-3 py-2 font-medium">Command</th>
                        <th class="px-3 py-2 text-right font-medium">Time</th>
                        <th class="px-3 py-2 font-medium">State / SQL</th>
                        <th class="px-3 py-2">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr
                        v-for="row in rows"
                        :key="row.id"
                        class="align-top"
                        :data-test="`process-${row.id}`"
                    >
                        <td class="px-3 py-2 font-mono tabular-nums">
                            {{ row.id }}
                        </td>
                        <td class="px-3 py-2">
                            <div>{{ row.user }}</div>
                            <div
                                v-if="row.host"
                                class="text-xs text-muted-foreground"
                            >
                                {{ row.host }}
                            </div>
                        </td>
                        <td class="px-3 py-2">
                            <code v-if="row.database">{{ row.database }}</code>
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                        <td class="px-3 py-2">
                            <div class="flex flex-wrap items-center gap-1.5">
                                {{ row.command }}
                                <Badge
                                    v-if="row.isCurrentConnection"
                                    variant="secondary"
                                    >DBNova (you)</Badge
                                >
                            </div>
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">
                            {{ elapsed(row.timeSeconds) }}
                        </td>
                        <td class="max-w-xl px-3 py-2">
                            <div
                                v-if="row.state"
                                class="text-xs text-muted-foreground"
                            >
                                {{ row.state }}
                            </div>
                            <pre
                                v-if="row.info"
                                class="line-clamp-3 font-mono text-xs break-all whitespace-pre-wrap"
                                :title="row.info"
                                >{{ row.info }}</pre>
                        </td>
                        <td class="px-3 py-2 text-right whitespace-nowrap">
                            <template
                                v-if="!row.isCurrentConnection && !readOnly"
                            >
                                <Button
                                    v-if="canStopQuery(row)"
                                    variant="outline"
                                    size="sm"
                                    data-test="process-stop-query"
                                    @click="pending = { row, mode: 'query' }"
                                >
                                    <CircleStop /> Stop query
                                </Button>
                                <DropdownMenu>
                                    <DropdownMenuTrigger as-child>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            :aria-label="`More actions for process ${row.id}`"
                                        >
                                            <EllipsisVertical />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end">
                                        <DropdownMenuItem
                                            class="text-destructive"
                                            data-test="process-kill-connection"
                                            @select="
                                                pending = {
                                                    row,
                                                    mode: 'connection',
                                                }
                                            "
                                        >
                                            <Unplug /> Kill connection
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </template>
                        </td>
                    </tr>
                    <tr v-if="rows.length === 0">
                        <td
                            colspan="7"
                            class="px-3 py-8 text-center text-muted-foreground"
                        >
                            <template v-if="search"
                                >No processes match.</template
                            >
                            <template v-else
                                >No active queries. Tick "Show idle connections"
                                to see sleeping ones.</template
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Dialog
            :open="pending !== null"
            @update:open="(open) => !open && (pending = null)"
        >
            <DialogContent v-if="pending">
                <DialogHeader>
                    <DialogTitle>
                        {{
                            pending.mode === 'query'
                                ? `Stop the query on connection ${pending.row.id}?`
                                : `Kill connection ${pending.row.id}?`
                        }}
                    </DialogTitle>
                    <DialogDescription>
                        <template v-if="pending.mode === 'query'">
                            MySQL ends the running statement and the client
                            stays connected. If the statement was changing data,
                            InnoDB rolls back that statement, which can take a
                            while for large changes.
                        </template>
                        <template v-else>
                            MySQL disconnects
                            <strong>{{ pending.row.user }}</strong
                            ><template v-if="pending.row.host">
                                ({{ pending.row.host }})</template
                            >. An open transaction is rolled back, and the
                            application must reconnect.
                        </template>
                    </DialogDescription>
                </DialogHeader>
                <pre
                    v-if="pending.row.info"
                    class="max-h-40 overflow-auto rounded-md bg-muted p-2 font-mono text-xs whitespace-pre-wrap"
                    >{{ pending.row.info }}</pre>
                <DialogFooter>
                    <Button variant="outline" @click="pending = null">
                        Cancel
                    </Button>
                    <Button
                        variant="destructive"
                        :disabled="killHttp.processing"
                        data-test="process-confirm-kill"
                        @click="confirmKill"
                    >
                        <Spinner v-if="killHttp.processing" />
                        {{
                            pending.mode === 'query'
                                ? 'Stop query'
                                : 'Kill connection'
                        }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
