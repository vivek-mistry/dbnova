<script setup lang="ts">
import {
    CircleAlert,
    CircleCheck,
    CircleMinus,
    TriangleAlert,
} from '@lucide/vue';
import { computed, onBeforeUnmount, ref } from 'vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { formatBytes, formatNumber } from '@/lib/format';
import type { ImportJobStatus } from '@/types';

const props = defineProps<{
    job: ImportJobStatus;
    cancelling?: boolean;
}>();

defineEmits<{ cancel: []; dismiss: []; reset: [] }>();

const checking = computed(
    () => props.job.status === 'pending' || props.job.status === 'preflight',
);
const running = computed(
    () => props.job.status === 'queued' || props.job.status === 'importing',
);
/** Whether anything may have reached MySQL (the import itself started). */
const sentSomething = computed(() => props.job.startedAt !== null);
const partial = computed(
    () =>
        sentSomething.value &&
        (props.job.status === 'failed' ||
            props.job.status === 'cancelled' ||
            props.job.status === 'interrupted'),
);

/** Read from the file (and handed to MySQL while importing), as a share of the file. */
const readPercent = computed(() =>
    props.job.bytesTotal === 0
        ? 0
        : Math.min(100, (props.job.bytesScanned / props.job.bytesTotal) * 100),
);

const report = computed(() => props.job.preflight);
const listedTables = computed(() => report.value?.tables.slice(0, 12) ?? []);

const now = ref(Date.now());
const clock = window.setInterval(() => (now.value = Date.now()), 1000);
onBeforeUnmount(() => window.clearInterval(clock));

const elapsed = computed(() => {
    if (props.job.startedAt === null) {
        return null;
    }

    const end = props.job.finishedAt
        ? Date.parse(props.job.finishedAt)
        : now.value;
    const seconds = Math.max(
        0,
        Math.round((end - Date.parse(props.job.startedAt)) / 1000),
    );
    const minutes = Math.floor(seconds / 60);

    return minutes > 0 ? `${minutes}m ${seconds % 60}s` : `${seconds}s`;
});

const failureTitle = computed(
    () =>
        ({
            failed: 'The import failed',
            cancelled: 'The import was cancelled',
            interrupted: 'The import was interrupted',
        })[props.job.status as 'failed' | 'cancelled' | 'interrupted'] ??
        'The import stopped',
);
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
                    <template v-if="elapsed"> · {{ elapsed }}</template>
                </p>
            </div>
            <Button
                v-if="checking || running || job.status === 'ready'"
                variant="outline"
                size="sm"
                :disabled="cancelling || job.cancelRequested"
                data-test="import-cancel"
                @click="$emit('cancel')"
            >
                {{
                    job.cancelRequested
                        ? 'Stopping…'
                        : checking
                          ? 'Stop check'
                          : running
                            ? 'Stop import'
                            : 'Discard'
                }}
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
                    Checking the file: {{ readPercent.toFixed(0) }}% read ({{
                        formatBytes(job.bytesScanned)
                    }}
                    of {{ formatBytes(job.bytesTotal) }})
                </span>
            </div>
            <div class="h-2 overflow-hidden rounded-full bg-muted">
                <div
                    class="h-full bg-primary transition-[width]"
                    :style="{ width: `${readPercent}%` }"
                />
            </div>
            <p class="text-xs text-muted-foreground">
                Nothing is sent to MySQL while the file is checked.
            </p>
        </div>

        <!-- Queued / importing -->
        <div v-else-if="running" class="flex flex-col gap-2">
            <div class="flex items-center gap-2 text-sm">
                <Spinner />
                <span v-if="job.status === 'queued'">
                    Waiting for the queue worker to start the import…
                </span>
                <span v-else>
                    Importing: {{ readPercent.toFixed(0) }}% of the file sent to
                    MySQL
                </span>
            </div>
            <div class="h-2 overflow-hidden rounded-full bg-muted">
                <div
                    class="h-full bg-primary transition-[width]"
                    :style="{ width: `${readPercent}%` }"
                />
            </div>
            <p
                v-if="job.status === 'importing'"
                class="text-xs text-muted-foreground"
                data-test="import-progress"
            >
                {{ formatBytes(job.bytesStreamed) }} of SQL and
                {{ formatNumber(job.statementsSeen) }} statements sent to MySQL.
                "Sent" is not "committed": MySQL applies each statement as it
                runs, and a large statement can take a while.
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

        <!-- Completed -->
        <template v-else-if="job.status === 'completed'">
            <Alert>
                <CircleCheck class="text-emerald-600 dark:text-emerald-400" />
                <AlertTitle>Import completed</AlertTitle>
                <AlertDescription>
                    MySQL accepted every statement:
                    {{ formatNumber(job.statementsSeen) }} statements,
                    {{ formatBytes(job.bytesStreamed) }} of SQL<template
                        v-if="(job.options.skippedStatements ?? 0) > 0"
                        >;
                        {{ formatNumber(job.options.skippedStatements ?? 0) }}
                        USE / CREATE DATABASE statements skipped</template
                    >.
                </AlertDescription>
            </Alert>
            <Alert v-if="(job.options.warningCount ?? 0) > 0">
                <TriangleAlert class="text-amber-600 dark:text-amber-400" />
                <AlertTitle>
                    MySQL reported
                    {{ formatNumber(job.options.warningCount ?? 0) }}
                    warning(s)
                </AlertTitle>
                <AlertDescription>
                    <ul class="list-disc pl-4">
                        <li
                            v-for="warning in job.options.warnings ?? []"
                            :key="warning"
                        >
                            {{ warning }}
                        </li>
                    </ul>
                </AlertDescription>
            </Alert>
        </template>

        <!-- Stopped after sending data: partial -->
        <template v-else-if="partial">
            <Alert variant="destructive" data-test="import-partial">
                <CircleAlert />
                <AlertTitle>{{ failureTitle }}</AlertTitle>
                <AlertDescription>
                    <p v-if="job.lastError">
                        <template v-if="job.errorLine !== null"
                            >Line {{ formatNumber(job.errorLine) }}:
                        </template>
                        {{ job.lastError }}
                    </p>
                    <p class="mt-1">
                        <strong>Data may be partially imported.</strong>
                        {{ formatNumber(job.statementsSeen) }} statements ({{
                            formatBytes(job.bytesStreamed)
                        }}) were sent before it stopped, and MySQL keeps what it
                        applied: nothing is rolled back.
                    </p>
                </AlertDescription>
            </Alert>
            <div
                v-if="job.options.targetReset"
                class="text-sm text-muted-foreground"
            >
                <code>{{ job.database }}</code> was dropped and created again,
                empty.
            </div>
            <div v-else class="flex flex-wrap items-center gap-2">
                <Button
                    variant="destructive"
                    size="sm"
                    data-test="import-reset"
                    @click="$emit('reset')"
                >
                    Drop &amp; recreate <code>{{ job.database }}</code>
                </Button>
                <Button variant="outline" size="sm" @click="$emit('dismiss')">
                    Keep as is
                </Button>
            </div>
        </template>

        <!-- Stopped before anything was sent -->
        <Alert v-else-if="job.status === 'cancelled'">
            <CircleMinus />
            <AlertTitle>Cancelled</AlertTitle>
            <AlertDescription>
                <template v-if="job.lastError">{{ job.lastError }} </template>
                Nothing was sent to MySQL.
            </AlertDescription>
        </Alert>
        <Alert v-else-if="job.status === 'failed'" variant="destructive">
            <CircleAlert />
            <AlertTitle>Failed</AlertTitle>
            <AlertDescription>
                {{ job.lastError }} Nothing was sent to MySQL.
            </AlertDescription>
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
