<script setup lang="ts">
import { useVirtualizer } from '@tanstack/vue-virtual';
import { computed, ref } from 'vue';
import DataGridCell from '@/components/data-grid/DataGridCell.vue';
import type { Cell } from '@/types';

const props = defineProps<{
    columns: string[];
    rows: Cell[][];
}>();

const ROW_HEIGHT = 28;
const COLUMN_WIDTH = 180;
const NUMBER_WIDTH = 48;

const scrollElement = ref<HTMLElement | null>(null);

const virtualizer = useVirtualizer(
    computed(() => ({
        count: props.rows.length,
        getScrollElement: () => scrollElement.value,
        estimateSize: () => ROW_HEIGHT,
        overscan: 12,
    })),
);

const virtualRows = computed(() => virtualizer.value.getVirtualItems());
const totalWidth = computed(
    () => NUMBER_WIDTH + props.columns.length * COLUMN_WIDTH,
);
</script>

<template>
    <div
        ref="scrollElement"
        class="relative min-h-0 flex-1 overflow-auto rounded-md border font-mono text-xs"
        data-test="result-grid"
    >
        <div :style="{ width: `${totalWidth}px` }">
            <div
                class="sticky top-0 z-10 flex border-b bg-muted font-sans font-medium"
            >
                <div
                    class="shrink-0 border-r px-2 py-1.5 text-right text-muted-foreground"
                    :style="{ width: `${NUMBER_WIDTH}px` }"
                >
                    #
                </div>
                <div
                    v-for="column in columns"
                    :key="column"
                    class="shrink-0 truncate border-r px-2 py-1.5"
                    :style="{ width: `${COLUMN_WIDTH}px` }"
                    :title="column"
                >
                    {{ column }}
                </div>
            </div>
            <div
                class="relative"
                :style="{ height: `${virtualizer.getTotalSize()}px` }"
            >
                <div
                    v-for="item in virtualRows"
                    :key="item.index"
                    class="absolute left-0 flex w-full border-b hover:bg-muted/40"
                    :style="{
                        height: `${ROW_HEIGHT}px`,
                        transform: `translateY(${item.start}px)`,
                    }"
                >
                    <div
                        class="flex shrink-0 items-center justify-end border-r px-2 text-muted-foreground"
                        :style="{ width: `${NUMBER_WIDTH}px` }"
                    >
                        {{ item.index + 1 }}
                    </div>
                    <div
                        v-for="(cell, index) in rows[item.index]"
                        :key="index"
                        class="flex shrink-0 items-center overflow-hidden border-r px-2"
                        :style="{ width: `${COLUMN_WIDTH}px` }"
                    >
                        <DataGridCell :cell="cell" />
                    </div>
                </div>
            </div>
            <p
                v-if="rows.length === 0"
                class="p-4 text-center font-sans text-sm text-muted-foreground"
            >
                No rows.
            </p>
        </div>
    </div>
</template>
