<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { CircleAlert, CircleCheck, CircleX, Info } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { httpErrorMessage } from '@/lib/httpError';
import { run as runRoute } from '@/routes/maintenance';
import type { MaintenanceOperation, MaintenanceResult } from '@/types';

const props = defineProps<{
    database: string;
    tables: string[];
    operation: MaintenanceOperation | null;
}>();

const emit = defineEmits<{
    close: [];
    done: [operation: MaintenanceOperation];
}>();

const http = useHttp<
    Record<string, never>,
    { operation: MaintenanceOperation; results: MaintenanceResult[] }
>();

const results = ref<MaintenanceResult[] | null>(null);
const error = ref<string | null>(null);

watch(
    () => props.operation,
    () => {
        results.value = null;
        error.value = null;
    },
);

const titles: Record<MaintenanceOperation, string> = {
    optimize: 'Optimize',
    analyze: 'Analyze',
    check: 'Check',
    repair: 'Repair',
};

const descriptions: Record<MaintenanceOperation, string> = {
    optimize:
        'Rebuilds each table to reclaim free space and defragment its indexes. InnoDB rebuilds online, but a big table takes a while and needs free disk about the size of the table. Progress shows under Processes.',
    analyze:
        "Refreshes the index statistics MySQL's optimizer uses to choose query plans. It is quick and does not rewrite data.",
    check: 'Checks each table for errors. It only reads, so it is allowed in read-only mode.',
    repair: 'Repairs a damaged MyISAM, ARCHIVE or CSV table. A repair can drop rows MySQL cannot read, so export the table first if you can. InnoDB does not support REPAIR; MySQL reports that per table instead of changing anything.',
};

const title = computed(() =>
    props.operation === null
        ? ''
        : `${titles[props.operation]} ${props.tables.length === 1 ? props.tables[0] : `${props.tables.length} tables`}`,
);

const errorCount = computed(
    () => results.value?.filter((row) => row.type === 'error').length ?? 0,
);

async function run(): Promise<void> {
    if (props.operation === null) {
        return;
    }

    const operation = props.operation;
    error.value = null;
    http.transform(() => ({ operation, tables: props.tables, confirm: true }));

    try {
        const response = await http.post(runRoute.url(props.database));
        results.value = response.results;
        emit('done', operation);
    } catch (exception) {
        error.value = httpErrorMessage(
            exception,
            'MySQL could not run the maintenance.',
        );
    }
}

/** MySQL writes "db.table"; within one database the table name is enough. */
function tableName(qualified: string): string {
    const prefix = `${props.database}.`;

    return qualified.startsWith(prefix)
        ? qualified.slice(prefix.length)
        : qualified;
}

const icons: Record<string, unknown> = {
    status: CircleCheck,
    error: CircleX,
    warning: CircleAlert,
};

const iconClasses: Record<string, string> = {
    status: 'text-emerald-600 dark:text-emerald-400',
    error: 'text-destructive',
    warning: 'text-amber-600 dark:text-amber-400',
};
</script>

<template>
    <Dialog
        :open="operation !== null"
        @update:open="(open) => !open && !http.processing && emit('close')"
    >
        <DialogContent v-if="operation" class="sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription>{{
                    descriptions[operation]
                }}</DialogDescription>
            </DialogHeader>

            <p
                v-if="results === null && tables.length > 1"
                class="max-h-24 overflow-auto font-mono text-xs text-muted-foreground"
            >
                {{ tables.join(', ') }}
            </p>

            <p
                v-if="error"
                class="rounded-md border border-destructive/40 bg-destructive/10 p-3 text-sm"
                data-test="maintenance-error"
            >
                {{ error }}
            </p>

            <div
                v-if="results"
                class="max-h-80 overflow-auto rounded-md border"
                data-test="maintenance-results"
            >
                <table class="w-full text-sm">
                    <thead
                        class="sticky top-0 bg-muted text-left text-xs text-muted-foreground"
                    >
                        <tr>
                            <th class="px-3 py-2 font-medium">Table</th>
                            <th class="px-3 py-2 font-medium">Result</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="(row, i) in results"
                            :key="`${row.table}-${i}`"
                            class="align-top"
                        >
                            <td class="px-3 py-1.5 font-mono text-xs">
                                {{ tableName(row.table) }}
                            </td>
                            <td class="px-3 py-1.5">
                                <span class="flex items-start gap-1.5">
                                    <component
                                        :is="icons[row.type] ?? Info"
                                        class="mt-0.5 size-3.5 shrink-0"
                                        :class="
                                            iconClasses[row.type] ??
                                            'text-muted-foreground'
                                        "
                                    />
                                    <span>
                                        <span
                                            class="text-xs text-muted-foreground"
                                            >{{ row.type }}:</span
                                        >
                                        {{ row.message }}
                                    </span>
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <DialogFooter class="items-center">
                <span
                    v-if="results"
                    class="mr-auto text-xs text-muted-foreground"
                >
                    {{
                        errorCount === 0
                            ? 'MySQL reported no errors.'
                            : `MySQL reported ${errorCount} error${errorCount === 1 ? '' : 's'}.`
                    }}
                </span>
                <template v-if="results === null">
                    <Button
                        variant="outline"
                        :disabled="http.processing"
                        @click="emit('close')"
                    >
                        Cancel
                    </Button>
                    <Button
                        :disabled="http.processing"
                        data-test="maintenance-run"
                        @click="run"
                    >
                        <Spinner v-if="http.processing" />
                        {{ titles[operation] }}
                    </Button>
                </template>
                <Button v-else @click="emit('close')">Close</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
