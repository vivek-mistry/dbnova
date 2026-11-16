<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, useTemplateRef } from 'vue';

export type ChartSeries = {
    key: string;
    label: string;
    /** A CSS colour, e.g. "var(--chart-1)". */
    color: string;
    /** One value per timestamp; null where there is no data (e.g. the first sample of a rate). */
    values: (number | null)[];
};

const props = withDefaults(
    defineProps<{
        series: ChartSeries[];
        /** Sample times in milliseconds, oldest first. */
        timestamps: number[];
        /** Fixed top of the y-axis (e.g. 100 for percentages); otherwise derived from the data. */
        yMax?: number;
        format?: (value: number) => string;
        height?: number;
        /** Describes the chart for screen readers. */
        label: string;
    }>(),
    {
        yMax: undefined,
        format: (value: number) =>
            Number.isInteger(value) ? String(value) : value.toFixed(1),
        height: 160,
    },
);

const PADDING = { top: 8, right: 8, bottom: 20, left: 44 };

const container = useTemplateRef('container');
const width = ref(480);
const hoverIndex = ref<number | null>(null);

let observer: ResizeObserver | undefined;

onMounted(() => {
    if (container.value) {
        width.value = container.value.clientWidth || width.value;
        observer = new ResizeObserver(([entry]) => {
            width.value = Math.max(160, entry.contentRect.width);
        });
        observer.observe(container.value);
    }
});

onBeforeUnmount(() => observer?.disconnect());

const plotWidth = computed(() => width.value - PADDING.left - PADDING.right);
const plotHeight = computed(() => props.height - PADDING.top - PADDING.bottom);

/** A "nice" top for the y-axis so gridlines land on round numbers. */
const top = computed(() => {
    if (props.yMax !== undefined) {
        return props.yMax;
    }

    const max = Math.max(
        0,
        ...props.series.flatMap((s) =>
            s.values.filter((v): v is number => v !== null),
        ),
    );

    if (max <= 0) {
        return 1;
    }

    const magnitude = 10 ** Math.floor(Math.log10(max));
    const step = [1, 2, 2.5, 5, 10].find((n) => n * magnitude >= max) ?? 10;

    return step * magnitude;
});

const ticks = computed(() => [0, 0.5, 1].map((f) => f * top.value));

const count = computed(() => props.timestamps.length);

function x(index: number): number {
    return count.value <= 1
        ? PADDING.left + plotWidth.value
        : PADDING.left + (index / (count.value - 1)) * plotWidth.value;
}

function y(value: number): number {
    return (
        PADDING.top +
        plotHeight.value -
        (Math.min(value, top.value) / top.value) * plotHeight.value
    );
}

/** Line path per series; gaps (null) break the line instead of dropping to zero. */
function path(values: (number | null)[]): string {
    let d = '';
    let drawing = false;

    values.forEach((value, index) => {
        if (value === null) {
            drawing = false;

            return;
        }

        d += `${drawing ? 'L' : 'M'}${x(index).toFixed(1)},${y(value).toFixed(1)}`;
        drawing = true;
    });

    return d;
}

/** Area under a single series, anchored to the baseline. */
function area(values: (number | null)[]): string {
    const points = values
        .map((value, index) => ({ value, index }))
        .filter((p): p is { value: number; index: number } => p.value !== null);

    if (points.length < 2) {
        return '';
    }

    const baseline = (PADDING.top + plotHeight.value).toFixed(1);
    const line = points
        .map(
            (p, i) =>
                `${i === 0 ? 'M' : 'L'}${x(p.index).toFixed(1)},${y(p.value).toFixed(1)}`,
        )
        .join('');

    return `${line}L${x(points[points.length - 1].index).toFixed(1)},${baseline}L${x(points[0].index).toFixed(1)},${baseline}Z`;
}

function latest(values: (number | null)[]): number | null {
    for (let i = values.length - 1; i >= 0; i--) {
        if (values[i] !== null) {
            return values[i];
        }
    }

    return null;
}

const spanLabel = computed(() => {
    if (count.value < 2) {
        return '';
    }

    const seconds = Math.round(
        (props.timestamps[count.value - 1] - props.timestamps[0]) / 1000,
    );

    return seconds >= 60
        ? `${Math.round(seconds / 60)} min ago`
        : `${seconds} s ago`;
});

function onMove(event: PointerEvent): void {
    if (count.value === 0) {
        return;
    }

    const bounds = (event.currentTarget as SVGElement).getBoundingClientRect();
    const relative =
        (event.clientX - bounds.left - PADDING.left) / plotWidth.value;
    hoverIndex.value = Math.min(
        count.value - 1,
        Math.max(0, Math.round(relative * (count.value - 1))),
    );
}

const tooltipLeft = computed(() => {
    if (hoverIndex.value === null) {
        return 0;
    }

    // Keep the tooltip inside the chart: flip to the left of the crosshair near the right edge.
    const at = x(hoverIndex.value);

    return at > width.value - 150 ? at - 150 : at + 10;
});
</script>

<template>
    <div ref="container" class="relative w-full">
        <!-- Legend with current values (for two or more series; one series is named by the card title). -->
        <div
            v-if="series.length > 1"
            class="mb-2 flex flex-wrap gap-x-4 gap-y-1 text-xs"
        >
            <span
                v-for="s in series"
                :key="s.key"
                class="flex items-center gap-1.5 text-muted-foreground"
            >
                <span
                    class="size-2.5 rounded-sm"
                    :style="{ background: s.color }"
                />
                {{ s.label }}
                <span class="font-medium text-foreground tabular-nums">{{
                    latest(s.values) === null ? '—' : format(latest(s.values)!)
                }}</span>
            </span>
        </div>

        <svg
            :width="width"
            :height="height"
            :viewBox="`0 0 ${width} ${height}`"
            role="img"
            :aria-label="label"
            class="block touch-none select-none"
            @pointermove="onMove"
            @pointerleave="hoverIndex = null"
        >
            <!-- Recessive grid and y-axis labels -->
            <g v-for="tick in ticks" :key="tick">
                <line
                    :x1="PADDING.left"
                    :x2="width - PADDING.right"
                    :y1="y(tick)"
                    :y2="y(tick)"
                    class="stroke-border"
                    stroke-width="1"
                    :stroke-dasharray="tick === 0 ? undefined : '2 4'"
                />
                <text
                    :x="PADDING.left - 6"
                    :y="y(tick)"
                    text-anchor="end"
                    dominant-baseline="middle"
                    class="fill-muted-foreground text-[10px] tabular-nums"
                >
                    {{ format(tick) }}
                </text>
            </g>

            <!-- Time axis: oldest and newest only -->
            <text
                v-if="spanLabel"
                :x="PADDING.left"
                :y="height - 4"
                class="fill-muted-foreground text-[10px]"
            >
                {{ spanLabel }}
            </text>
            <text
                :x="width - PADDING.right"
                :y="height - 4"
                text-anchor="end"
                class="fill-muted-foreground text-[10px]"
            >
                now
            </text>

            <path
                v-if="series.length === 1"
                :d="area(series[0].values)"
                :fill="series[0].color"
                fill-opacity="0.12"
            />
            <path
                v-for="s in series"
                :key="s.key"
                :d="path(s.values)"
                fill="none"
                :stroke="s.color"
                stroke-width="2"
                stroke-linejoin="round"
                stroke-linecap="round"
            />

            <!-- Crosshair and hover markers -->
            <g v-if="hoverIndex !== null">
                <line
                    :x1="x(hoverIndex)"
                    :x2="x(hoverIndex)"
                    :y1="PADDING.top"
                    :y2="PADDING.top + plotHeight"
                    class="stroke-muted-foreground"
                    stroke-width="1"
                    stroke-dasharray="3 3"
                />
                <template v-for="s in series" :key="s.key">
                    <circle
                        v-if="s.values[hoverIndex] !== null"
                        :cx="x(hoverIndex)"
                        :cy="y(s.values[hoverIndex]!)"
                        r="4"
                        :fill="s.color"
                        class="stroke-card"
                        stroke-width="2"
                    />
                </template>
            </g>
        </svg>

        <div
            v-if="hoverIndex !== null"
            class="pointer-events-none absolute z-10 min-w-36 rounded-md border bg-popover px-2.5 py-1.5 text-xs text-popover-foreground shadow-md"
            :style="{
                left: `${tooltipLeft}px`,
                top: `${series.length > 1 ? 28 : 4}px`,
            }"
        >
            <div class="mb-1 text-muted-foreground">
                {{ new Date(timestamps[hoverIndex]).toLocaleTimeString() }}
            </div>
            <div
                v-for="s in series"
                :key="s.key"
                class="flex items-center justify-between gap-3"
            >
                <span class="flex items-center gap-1.5">
                    <span
                        class="size-2 rounded-sm"
                        :style="{ background: s.color }"
                    />
                    {{ s.label }}
                </span>
                <span class="font-medium tabular-nums">{{
                    s.values[hoverIndex] === null
                        ? '—'
                        : format(s.values[hoverIndex]!)
                }}</span>
            </div>
        </div>

        <!-- The same data as a table for screen readers. -->
        <table class="sr-only">
            <caption>
                {{
                    label
                }}
            </caption>
            <thead>
                <tr>
                    <th>Time</th>
                    <th v-for="s in series" :key="s.key">{{ s.label }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(time, i) in timestamps" :key="time">
                    <td>{{ new Date(time).toLocaleTimeString() }}</td>
                    <td v-for="s in series" :key="s.key">
                        {{ s.values[i] === null ? '—' : format(s.values[i]!) }}
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
