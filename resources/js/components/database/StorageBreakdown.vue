<script setup lang="ts">
import { computed, ref } from 'vue';
import { formatBytes } from '@/lib/format';
import type { TableInfo } from '@/types';

const props = defineProps<{
    tables: TableInfo[];
}>();

const totals = computed(() => {
    let data = 0;
    let index = 0;
    let free = 0;

    for (const table of props.tables) {
        data += table.dataBytes ?? 0;
        index += table.indexBytes ?? 0;
        free += table.freeBytes ?? 0;
    }

    return { data, index, free, total: data + index };
});

const segments = computed(() => {
    const { data, index, total } = totals.value;

    return [
        {
            key: 'data',
            label: 'Data',
            bytes: data,
            share: total === 0 ? 0 : data / total,
            color: 'var(--chart-1)',
        },
        {
            key: 'index',
            label: 'Indexes',
            bytes: index,
            share: total === 0 ? 0 : index / total,
            color: 'var(--chart-2)',
        },
    ];
});

const hovered = ref<string | null>(null);

function percent(share: number): string {
    return share > 0 && share < 0.01 ? '<1%' : `${Math.round(share * 100)}%`;
}
</script>

<template>
    <div class="flex flex-col gap-2" data-test="storage-breakdown">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="text-sm font-medium">Storage</h2>
            <span class="text-xs text-muted-foreground tabular-nums">
                {{ formatBytes(totals.total) }} on disk<template
                    v-if="totals.free > 0"
                >
                    · {{ formatBytes(totals.free) }} allocated but
                    unused</template
                >
            </span>
        </div>

        <!-- One bar, data vs indexes; a 2px surface gap separates the segments. -->
        <div
            v-if="totals.total > 0"
            class="flex h-3 w-full gap-0.5 overflow-hidden rounded"
            role="img"
            :aria-label="`Data ${formatBytes(totals.data)}, indexes ${formatBytes(totals.index)}`"
        >
            <div
                v-for="segment in segments.filter((s) => s.bytes > 0)"
                :key="segment.key"
                class="h-full min-w-1 transition-opacity first:rounded-l last:rounded-r"
                :class="
                    hovered !== null && hovered !== segment.key
                        ? 'opacity-40'
                        : ''
                "
                :style="{
                    width: `${segment.share * 100}%`,
                    background: segment.color,
                }"
                :title="`${segment.label}: ${formatBytes(segment.bytes)} (${percent(segment.share)})`"
                @pointerenter="hovered = segment.key"
                @pointerleave="hovered = null"
            />
        </div>
        <div v-else class="h-3 w-full rounded bg-muted" />

        <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs">
            <span
                v-for="segment in segments"
                :key="segment.key"
                class="flex items-center gap-1.5 text-muted-foreground"
                @pointerenter="hovered = segment.key"
                @pointerleave="hovered = null"
            >
                <span
                    class="size-2.5 rounded-sm"
                    :style="{ background: segment.color }"
                />
                {{ segment.label }}
                <span class="font-medium text-foreground tabular-nums">
                    {{ formatBytes(segment.bytes) }}
                </span>
                <span class="tabular-nums">{{ percent(segment.share) }}</span>
            </span>
        </div>
    </div>
</template>
