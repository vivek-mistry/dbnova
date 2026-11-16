<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { formatBytes } from '@/lib/format';
import { httpErrorMessage } from '@/lib/httpError';
import { value as valueRoute } from '@/routes/tables';
import type { CellValue } from '@/types';

const props = defineProps<{
    database: string;
    table: string;
    column: string | null;
    rowKey: string | null;
}>();

const open = defineModel<boolean>('open', { required: true });

const value = ref<CellValue | null>(null);
const error = ref<string | null>(null);
const http = useHttp<Record<string, never>, CellValue>();

watch(open, async (isOpen) => {
    if (!isOpen || props.column === null) {
        return;
    }

    value.value = null;
    error.value = null;

    if (props.rowKey === null) {
        error.value =
            'This table has no primary or unique key, so the full value cannot be loaded.';

        return;
    }

    const query = new URLSearchParams({
        column: props.column,
        key: props.rowKey,
    });

    try {
        value.value = await http.get(
            `${valueRoute.url({ database: props.database, table: props.table })}?${query}`,
        );
    } catch (exception) {
        error.value = httpErrorMessage(exception, 'Could not load the value.');
    }
});
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-3xl">
            <DialogHeader>
                <DialogTitle class="font-mono">{{ column }}</DialogTitle>
                <DialogDescription>Full cell value</DialogDescription>
            </DialogHeader>

            <div v-if="http.processing" class="flex justify-center py-8">
                <Spinner />
            </div>
            <p v-else-if="error" class="text-sm text-destructive">
                {{ error }}
            </p>
            <template v-else-if="value && 'text' in value">
                <pre
                    class="max-h-[60vh] overflow-auto rounded-md bg-muted p-3 font-mono text-xs break-all whitespace-pre-wrap"
                    >{{ value.text }}</pre>
            </template>
            <template v-else-if="value && 'hex' in value">
                <p class="text-sm text-muted-foreground">
                    {{ formatBytes(value.bytes) }} of binary data. The first
                    {{ formatBytes(Math.min(value.bytes, 1024)) }} as hex:
                </p>
                <pre
                    class="max-h-[60vh] overflow-auto rounded-md bg-muted p-3 font-mono text-xs break-all whitespace-pre-wrap"
                    >{{ value.hex }}</pre>
            </template>
        </DialogContent>
    </Dialog>
</template>
