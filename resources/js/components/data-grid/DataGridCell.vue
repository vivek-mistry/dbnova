<script setup lang="ts">
import { computed } from 'vue';
import { formatBytes } from '@/lib/format';
import type { Cell } from '@/types';

const props = defineProps<{
    cell: Cell;
}>();

const emit = defineEmits<{
    expand: [];
}>();

const kind = computed(() => {
    const cell = props.cell;

    if (cell === null) {
        return 'null';
    }

    if (typeof cell === 'object') {
        return 'binary' in cell ? 'binary' : 'truncated';
    }

    return cell === '' ? 'empty' : 'value';
});
</script>

<template>
    <span
        v-if="kind === 'null'"
        class="rounded bg-muted px-1 text-[11px] text-muted-foreground italic"
        >NULL</span
    >
    <span
        v-else-if="kind === 'empty'"
        class="text-muted-foreground/60"
        title="Empty string"
        >''</span
    >
    <button
        v-else-if="
            kind === 'binary' &&
            typeof cell === 'object' &&
            cell !== null &&
            'binary' in cell
        "
        type="button"
        class="rounded bg-blue-500/10 px-1 text-[11px] text-blue-700 hover:underline dark:text-blue-300"
        @click="emit('expand')"
    >
        [BLOB {{ formatBytes(cell.binary) }}]
    </button>
    <span
        v-else-if="
            kind === 'truncated' &&
            typeof cell === 'object' &&
            cell !== null &&
            'preview' in cell
        "
        class="flex min-w-0 items-center gap-1"
    >
        <span class="truncate">{{ cell.preview }}</span>
        <button
            type="button"
            class="shrink-0 rounded bg-muted px-1 text-[11px] text-muted-foreground hover:text-foreground"
            title="Show full value"
            @click="emit('expand')"
        >
            …
        </button>
    </span>
    <span v-else class="truncate">{{ cell }}</span>
</template>
