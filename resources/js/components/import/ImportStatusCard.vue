<script setup lang="ts">
import {
    CircleAlert,
    CircleCheck,
    CircleMinus,
    TriangleAlert,
} from '@lucide/vue';
import { computed } from 'vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { formatBytes, formatNumber } from '@/lib/format';
import type { ImportJobStatus } from '@/types';

const props = defineProps<{
    job: ImportJobStatus;
    cancelling?: boolean;
}>();

defineEmits<{ cancel: []; dismiss: [] }>();

const checking = computed(
    () => props.job.status === 'pending' || props.job.status === 'preflight',
);

const scannedPercent = computed(() =>
    props.job.bytesTotal === 0
        ? 0
        : Math.min(100, (props.job.bytesScanned / props.job.bytesTotal) * 100),
);

const report = computed(() => props.job.preflight);
const listedTables = computed(() => report.value?.tables.slice(0, 12) ?? []);
</script>

<template>
    <div
        class="flex flex-col gap-4 rounded-lg border p-4"
        data-test="import-status"
    >
        <div class="flex items-center justify-between gap-2">
            <div class="min-w-0">
                <p class="truncate text-sm font-medium">
                    {{ job.fileName ?? 'Uploaded file' }}
                    <span class="text-muted-foreground">→</span>
                    <code>{{ job.database }}</code>
                </p>
                <p class="text-xs text-muted-foreground">
                    {{ formatBytes(job.bytesTotal) }}
                </p>
            </div>
            <Button
                v-if="checking || job.status === 'ready'"
                variant="outline"
                size="sm"
                :disabled="cancelling || job.cancelRequested"
                @click="$emit('cancel')"
            >
                {{ checking ? 'Stop check' : 'Discard' }}
            </Button>
            <Button v-else variant="ghost" size="sm" @click="$emit('dismiss')">
                Close
            </Button>
        </div>

        <!-- Checking -->
        <div v-if="checking" class="flex flex-col gap-2">
            <div class="flex items-center gap-2 text-sm">
                <Spinner />
                <span v-if="job.status === 'pending'">
                    Waiting for the queue worker to start checking the file…
                </span>
                <span v-else>
                    Checking the file: {{ scannedPercent.toFixed(0) }}% read ({{
                        formatBytes(job.bytesScanned)
                    }}
                    of {{ formatBytes(job.bytesTotal) }})
                </span>
            </div>
            <div class="h-2 overflow-hidden rounded-full bg-muted">
                <div
                    class="h-full bg-primary transition-[width]"
                    :style="{ width: `${scannedPercent}%` }"
                />
            </div>
            <p class="text-xs text-muted-foreground">
                Nothing is sent to MySQL while the file is checked.
            </p>
        </div>

        <!-- Rejected -->
        <Alert v-else-if="job.status === 'rejected'" variant="destructive">
            <CircleAlert />
            <AlertTitle>This file can't be imported</AlertTitle>
            <AlertDescription>
                <ul class="list-disc pl-4">
                    <li
                        v-for="problem in report?.problems ?? []"
                        :key="problem"
                    >
                        {{ problem }}
                    </li>
                </ul>
                <p class="mt-1">Nothing was sent to MySQL.</p>
            </AlertDescription>
        </Alert>

        <!-- Cancelled / failed during the check -->
        <Alert v-else-if="job.status === 'cancelled'">
            <CircleMinus />
            <AlertTitle>Cancelled</AlertTitle>
            <AlertDescription>
                The import was cancelled before anything was sent to MySQL.
            </AlertDescription>
        </Alert>
        <Alert v-else-if="job.status === 'failed'" variant="destructive">
            <CircleAlert />
            <AlertTitle>Failed</AlertTitle>
            <AlertDescription>{{ job.lastError }}</AlertDescription>
        </Alert>

        <!-- Ready -->
        <template v-else-if="job.status === 'ready' && report">
            <Alert>
                <CircleCheck class="text-emerald-600 dark:text-emerald-400" />
                <AlertTitle>The file passed the check</AlertTitle>
                <AlertDescription>
                    {{ formatNumber(report.statementCount) }} statements,
                    {{ formatNumber(report.tableCount) }} tables{{
                        report.storedObjectCount > 0
                            ? `, ${formatNumber(report.storedObjectCount)} views/triggers/routines`
                            : ''
                    }}, {{ formatBytes(report.sqlBytes) }} of SQL.
                </AlertDescription>
            </Alert>

            <Alert v-if="report.sourceDatabase !== null">
                <TriangleAlert class="text-amber-600 dark:text-amber-400" />
                <AlertTitle>
                    The dump was made for
                    <code>{{ report.sourceDatabase }}</code>
                </AlertTitle>
                <AlertDescription>
                    Its {{ formatNumber(report.skippedStatements) }} USE /
                    CREATE DATABASE statements will be skipped, and everything
                    will be imported into <code>{{ job.database }}</code
                    >.
                </AlertDescription>
            </Alert>

            <Alert v-if="(job.conflicts ?? []).length > 0">
                <TriangleAlert class="text-amber-600 dark:text-amber-400" />
                <AlertTitle>
                    {{ job.conflicts?.length }} table(s) already exist in
                    <code>{{ job.database }}</code>
                </AlertTitle>
                <AlertDescription>
                    {{ job.conflicts?.slice(0, 10).join(', ')
                    }}{{ (job.conflicts?.length ?? 0) > 10 ? ', …' : '' }}. A
                    dump made by mysqldump drops and recreates them.
                </AlertDescription>
            </Alert>

            <Alert v-for="warning in report.warnings" :key="warning">
                <TriangleAlert class="text-amber-600 dark:text-amber-400" />
                <AlertDescription>{{ warning }}</AlertDescription>
            </Alert>

            <div
                v-if="listedTables.length > 0"
                class="text-xs text-muted-foreground"
            >
                Tables:
                <code v-for="(table, index) in listedTables" :key="table">
                    {{ table }}{{ index < listedTables.length - 1 ? ', ' : '' }}
                </code>
                <span v-if="report.tableCount > listedTables.length">
                    and
                    {{ formatNumber(report.tableCount - listedTables.length) }}
                    more
                </span>
            </div>

            <slot name="actions" />
        </template>
    </div>
</template>
