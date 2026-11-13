<script setup lang="ts">
import { Head, useHttp } from '@inertiajs/vue3';
import { FileUp, Pause, Play, X } from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import ImportStatusCard from '@/components/import/ImportStatusCard.vue';
import ResetImportTargetDialog from '@/components/import/ResetImportTargetDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Skeleton } from '@/components/ui/skeleton';
import { useChunkedUpload } from '@/composables/useChunkedUpload';
import { formatBytes } from '@/lib/format';
import { httpErrorMessage } from '@/lib/httpError';
import { index } from '@/routes/import-export';
import {
    cancel as cancelRoute,
    show as showRoute,
    start as startRoute,
    store as storeRoute,
} from '@/routes/imports';
import type { ImportJobStatus, ImportStatus } from '@/types';

const props = defineProps<{
    databases?: string[];
    database: string | null;
    imports: ImportJobStatus[];
    chunkBytes: number;
    maxUploadBytes: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Import / Export', href: index() }],
    },
});

const ACCEPT = '.sql,.gz,.zip';
const POLL_MS = 2000;

const upload = useChunkedUpload();
const file = ref<File | null>(null);
const target = ref(props.database ?? '');
const job = ref<ImportJobStatus | null>(null);
const history = ref<ImportJobStatus[]>(props.imports);

const createHttp = useHttp<Record<string, never>, ImportJobStatus>();
const pollHttp = useHttp<Record<string, never>, ImportJobStatus>();
const cancelHttp = useHttp<Record<string, never>, ImportJobStatus>();
const startHttp = useHttp<Record<string, never>, ImportJobStatus>();
const speedMode = ref(false);
const trustCompound = ref(false);
const needsTrust = computed(
    () => (job.value?.preflight?.compoundProgramCount ?? 0) > 0,
);
const resetOpen = ref(false);

const uploading = computed(() => upload.state.phase === 'uploading');
const busy = computed(
    () =>
        uploading.value ||
        createHttp.processing ||
        (job.value !== null && isActive(job.value.status)),
);
const canStart = computed(
    () => file.value !== null && target.value !== '' && !busy.value,
);
const uploadPercent = computed(() => upload.progress.value * 100);

function isActive(status: ImportStatus): boolean {
    return (
        status === 'pending' ||
        status === 'preflight' ||
        status === 'queued' ||
        status === 'importing'
    );
}

function chooseFile(event: Event): void {
    const chosen = (event.target as HTMLInputElement).files?.[0] ?? null;

    if (chosen !== null && chosen.size > props.maxUploadBytes) {
        toast.error(
            `The file is larger than ${formatBytes(props.maxUploadBytes)}.`,
        );

        return;
    }

    file.value = chosen;
    job.value = null;
    upload.reset();
}

async function begin(): Promise<void> {
    if (file.value === null || target.value === '') {
        return;
    }

    job.value = null;
    const uploadId = await upload.start(file.value);

    if (uploadId !== null) {
        await createImport(uploadId);
    }
}

async function resumeUpload(): Promise<void> {
    const uploadId = await upload.resume();

    if (uploadId !== null) {
        await createImport(uploadId);
    }
}

async function createImport(uploadId: string): Promise<void> {
    createHttp.transform(() => ({ uploadId, database: target.value }));

    try {
        job.value = await createHttp.post(storeRoute.url());
        remember(job.value);
    } catch (error) {
        toast.error(
            httpErrorMessage(error, 'The import could not be created.'),
        );
    }
}

function remember(status: ImportJobStatus): void {
    history.value = [
        status,
        ...history.value.filter((item) => item.id !== status.id),
    ].slice(0, 20);
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
            httpErrorMessage(error, 'The import could not be cancelled.'),
        );
    }
}

async function startImport(): Promise<void> {
    if (job.value === null) {
        return;
    }

    startHttp.transform(() => ({
        speedMode: speedMode.value,
        trustCompound: trustCompound.value,
    }));

    try {
        job.value = await startHttp.post(startRoute.url(job.value.id));
        remember(job.value);
    } catch (error) {
        toast.error(
            httpErrorMessage(error, 'The import could not be started.'),
        );
    }
}

function onReset(updated: ImportJobStatus): void {
    job.value = updated;
    remember(updated);
    toast.success(`"${updated.database}" was dropped and created again.`);
}

async function open(item: ImportJobStatus): Promise<void> {
    try {
        job.value = await pollHttp.get(showRoute.url(item.id));
    } catch (error) {
        toast.error(httpErrorMessage(error, 'The import could not be loaded.'));
    }
}

onBeforeUnmount(() => window.clearTimeout(timer));

const statusLabels: Record<ImportStatus, string> = {
    pending: 'Waiting',
    preflight: 'Checking',
    rejected: 'Rejected',
    ready: 'Checked',
    queued: 'Queued',
    importing: 'Importing',
    completed: 'Completed',
    failed: 'Failed',
    cancelled: 'Cancelled',
    interrupted: 'Interrupted',
};

function badgeVariant(
    status: ImportStatus,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (
        status === 'rejected' ||
        status === 'failed' ||
        status === 'interrupted'
    ) {
        return 'destructive';
    }

    return status === 'completed' || status === 'ready'
        ? 'default'
        : 'secondary';
}

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-2 text-sm shadow-xs dark:bg-input/30';
</script>

<template>
    <Head title="Import" />

    <div class="flex flex-1 flex-col gap-6 p-4 lg:p-6">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Import SQL</h1>
            <p class="text-sm text-muted-foreground">
                Import a <code>.sql</code>, <code>.sql.gz</code> or
                <code>.zip</code> dump into one database. Large files upload in
                {{ formatBytes(chunkBytes) }} chunks and resume after a network
                error or page reload.
            </p>
        </div>

        <div class="grid max-w-3xl gap-4 rounded-lg border p-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="grid gap-1.5 text-sm">
                    <span class="font-medium">SQL file</span>
                    <input
                        type="file"
                        :accept="ACCEPT"
                        :disabled="busy"
                        class="text-sm file:mr-3 file:rounded-md file:border file:border-input file:bg-transparent file:px-3 file:py-1.5 file:text-sm"
                        data-test="import-file"
                        @change="chooseFile"
                    />
                    <span v-if="file" class="text-xs text-muted-foreground">
                        {{ formatBytes(file.size) }}
                    </span>
                </label>

                <label class="grid gap-1.5 text-sm">
                    <span class="font-medium">Import into database</span>
                    <Skeleton
                        v-if="databases === undefined"
                        class="h-9 w-full"
                    />
                    <select
                        v-else
                        v-model="target"
                        :class="selectClass"
                        :disabled="busy"
                        data-test="import-database"
                    >
                        <option value="" disabled>Choose a database…</option>
                        <option
                            v-for="name in databases"
                            :key="name"
                            :value="name"
                        >
                            {{ name }}
                        </option>
                    </select>
                    <span class="text-xs text-muted-foreground">
                        Create the database first if it doesn't exist yet.
                    </span>
                </label>
            </div>

            <!-- Upload progress -->
            <div
                v-if="
                    upload.state.phase !== 'idle' &&
                    upload.state.phase !== 'uploaded'
                "
                class="flex flex-col gap-2"
                data-test="upload-progress"
            >
                <div class="flex items-center justify-between text-sm">
                    <span>
                        <template v-if="upload.state.phase === 'cancelled'"
                            >Upload cancelled.</template
                        >
                        <template v-else>
                            Uploading {{ uploadPercent.toFixed(0) }}% ({{
                                upload.state.receivedChunks
                            }}
                            of {{ upload.state.totalChunks }} chunks<template
                                v-if="upload.state.bytesPerSecond > 0"
                                >,
                                {{
                                    formatBytes(upload.state.bytesPerSecond)
                                }}/s</template
                            >)
                            <span
                                v-if="upload.state.resumed"
                                class="text-muted-foreground"
                            >
                                — resumed
                            </span>
                        </template>
                    </span>
                    <div class="flex gap-1">
                        <Button
                            v-if="uploading"
                            variant="ghost"
                            size="sm"
                            @click="upload.pause()"
                        >
                            <Pause /> Pause
                        </Button>
                        <Button
                            v-if="
                                upload.state.phase === 'paused' ||
                                upload.state.phase === 'error'
                            "
                            variant="ghost"
                            size="sm"
                            @click="resumeUpload"
                        >
                            <Play /> Resume
                        </Button>
                        <Button
                            v-if="upload.state.phase !== 'cancelled'"
                            variant="ghost"
                            size="sm"
                            @click="upload.cancel()"
                        >
                            <X /> Cancel
                        </Button>
                    </div>
                </div>
                <div class="h-2 overflow-hidden rounded-full bg-muted">
                    <div
                        class="h-full bg-primary transition-[width]"
                        :style="{ width: `${uploadPercent}%` }"
                    />
                </div>
                <p v-if="upload.state.error" class="text-sm text-destructive">
                    {{ upload.state.error }}
                </p>
            </div>

            <div>
                <Button
                    :disabled="!canStart"
                    data-test="import-start"
                    @click="begin"
                >
                    <FileUp /> Upload and check
                </Button>
            </div>
        </div>

        <div v-if="job" class="max-w-3xl">
            <ImportStatusCard
                :job="job"
                :cancelling="cancelHttp.processing"
                @cancel="cancelJob"
                @dismiss="job = null"
                @reset="resetOpen = true"
            >
                <template #actions>
                    <div class="flex flex-col gap-3 border-t pt-4">
                        <label class="flex items-start gap-2 text-sm">
                            <Checkbox
                                v-model="speedMode"
                                class="mt-0.5"
                                data-test="import-speed-mode"
                            />
                            <span>
                                <span class="font-medium">Faster import</span>
                                <span
                                    class="block text-xs text-muted-foreground"
                                >
                                    Turns off foreign key and unique checks and
                                    autocommit for this import's session, and
                                    commits at the end. Data that breaks those
                                    rules is not detected, and the import is
                                    still not atomic: a failure leaves what was
                                    sent.
                                </span>
                            </span>
                        </label>
                        <label
                            v-if="needsTrust"
                            class="flex items-start gap-2 text-sm"
                        >
                            <Checkbox
                                v-model="trustCompound"
                                class="mt-0.5"
                                data-test="import-trust"
                            />
                            <span>
                                <span class="font-medium">
                                    I trust where this dump came from
                                </span>
                                <span
                                    class="block text-xs text-muted-foreground"
                                >
                                    It creates
                                    {{ job.preflight?.compoundProgramCount }}
                                    routine(s), trigger(s) or event(s) whose
                                    bodies DBNova cannot check. They can run any
                                    SQL your MySQL account is allowed to, in any
                                    database.
                                </span>
                            </span>
                        </label>
                        <div>
                            <Button
                                :disabled="
                                    startHttp.processing ||
                                    (needsTrust && !trustCompound)
                                "
                                data-test="import-run"
                                @click="startImport"
                            >
                                <Play /> Start import
                            </Button>
                        </div>
                    </div>
                </template>
            </ImportStatusCard>
            <ResetImportTargetDialog
                v-model:open="resetOpen"
                :job="job"
                @done="onReset"
            />
        </div>

        <div class="max-w-3xl">
            <h2 class="mb-2 text-sm font-medium">Recent imports</h2>
            <p
                v-if="history.length === 0"
                class="text-sm text-muted-foreground"
            >
                No imports yet.
            </p>
            <ul
                v-else
                class="divide-y rounded-lg border text-sm"
                data-test="import-history"
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
                        {{ item.fileName ?? 'Deleted file' }}
                        <span class="text-muted-foreground">→</span>
                        <code>{{ item.database }}</code>
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
