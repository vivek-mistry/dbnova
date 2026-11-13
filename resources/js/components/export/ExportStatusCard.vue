<script setup lang="ts">
import { Download, Trash2, X } from '@lucide/vue';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { formatBytes } from '@/lib/format';
import type { ExportJobStatus, ExportStatus } from '@/types';

const props = defineProps<{
    job: ExportJobStatus;
    cancelling?: boolean;
    deleting?: boolean;
}>();

defineEmits<{ cancel: []; dismiss: []; delete: [] }>();

const endLabels: Record<ExportStatus, string> = {
    queued: 'Queued',
    exporting: 'Exporting',
    completed: 'Completed',
    failed: 'Failed',
    cancelled: 'Cancelled',
    interrupted: 'Interrupted',
    expired: 'Expired',
};

const running = computed(
    () => props.job.status === 'queued' || props.job.status === 'exporting',
);

const what = computed(() => {
    const tables = props.job.tables;

    if (tables === null) {
        return 'all tables';
    }

    return tables.length === 1 ? tables[0] : `${tables.length} tables`;
});

const contentLabel = computed(
    () =>
        ({
            both: 'structure and data',
            structure: 'structure only',
            data: 'data only',
        })[props.job.options.content ?? 'both'],
);

const elapsed = computed(() => {
    if (props.job.startedAt === null) {
        return null;
    }

    const end =
        props.job.finishedAt === null
            ? Date.now()
            : new Date(props.job.finishedAt).getTime();
    const seconds = Math.max(
        0,
        Math.round((end - new Date(props.job.startedAt).getTime()) / 1000),
    );

    return seconds < 60
        ? `${seconds} s`
        : `${Math.floor(seconds / 60)} min ${seconds % 60} s`;
});
</script>

<template>
    <div
        class="flex flex-col gap-3 rounded-lg border p-4"
        data-test="export-status"
    >
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="truncate font-medium">{{ job.fileName }}</p>
                <p class="text-sm text-muted-foreground">
                    <code>{{ job.database }}</code> · {{ what }} ·
                    {{ contentLabel }}
                </p>
            </div>
            <Button
                v-if="!running"
                variant="ghost"
                size="icon"
                aria-label="Close"
                @click="$emit('dismiss')"
            >
                <X />
            </Button>
        </div>

        <!-- Queued / running -->
        <template v-if="running">
            <div class="flex items-center gap-2 text-sm">
                <Spinner />
                <span v-if="job.status === 'queued'">
                    Waiting for a queue worker…
                </span>
                <span v-else-if="job.cancelRequested">Stopping…</span>
                <span v-else>
                    Dumped {{ formatBytes(job.bytesWritten) }} of SQL<template
                        v-if="job.format === 'sql.gz'"
                    >
                        ({{ formatBytes(job.fileBytes) }} compressed)</template
                    ><template v-if="elapsed"> in {{ elapsed }}</template>
                </span>
            </div>
            <p class="text-xs text-muted-foreground">
                The table data is about {{ formatBytes(job.estimatedBytes) }}
                (an estimate from MySQL's catalog; the dump is often larger or
                smaller).
            </p>
            <div>
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="cancelling || job.cancelRequested"
                    data-test="export-cancel"
                    @click="$emit('cancel')"
                >
                    <X /> Cancel export
                </Button>
            </div>
        </template>

        <!-- Completed -->
        <template v-else-if="job.status === 'completed'">
            <p class="text-sm">
                <Badge>Completed</Badge>
                <span class="ml-2">
                    {{ formatBytes(job.fileBytes)
                    }}<template v-if="job.format === 'sql.gz'">
                        ({{ formatBytes(job.bytesWritten) }} of SQL)</template
                    ><template v-if="elapsed">, took {{ elapsed }}</template>
                </span>
            </p>
            <p v-if="job.expiresAt" class="text-xs text-muted-foreground">
                The file is deleted
                {{ new Date(job.expiresAt).toLocaleString() }}. The download
                link is valid for a limited time; reopen the export to get a new
                one.
            </p>
            <div class="flex gap-2">
                <Button
                    v-if="job.downloadUrl"
                    as-child
                    data-test="export-download"
                >
                    <a :href="job.downloadUrl" download>
                        <Download /> Download
                    </a>
                </Button>
                <Button
                    variant="outline"
                    :disabled="deleting"
                    data-test="export-delete"
                    @click="$emit('delete')"
                >
                    <Trash2 /> Delete file
                </Button>
            </div>
        </template>

        <!-- Failed / cancelled / interrupted / expired -->
        <template v-else>
            <p class="text-sm">
                <Badge
                    :variant="
                        job.status === 'cancelled' || job.status === 'expired'
                            ? 'secondary'
                            : 'destructive'
                    "
                >
                    {{ endLabels[job.status] }}
                </Badge>
            </p>
            <p
                v-if="job.lastError"
                class="text-sm whitespace-pre-wrap text-destructive"
                data-test="export-error"
            >
                {{ job.lastError }}
            </p>
            <p
                v-if="job.status !== 'expired'"
                class="text-sm text-muted-foreground"
            >
                No file was kept. Nothing in the database was changed; start a
                new export to try again.
            </p>
            <p v-else class="text-sm text-muted-foreground">
                The file was deleted. Export again to get a new one.
            </p>
        </template>
    </div>
</template>
