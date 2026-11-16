<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Clock, Database, HardDrive, Server } from '@lucide/vue';
import { computed } from 'vue';
import LineChart from '@/components/charts/LineChart.vue';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { useServerMetrics } from '@/composables/useServerMetrics';
import { formatBytes, formatDuration, formatNumber } from '@/lib/format';
import { dashboard } from '@/routes';
import type { ServerInfo } from '@/types';

const props = defineProps<{
    server?: {
        info: ServerInfo | null;
        error: string | null;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});

const live = useServerMetrics();

function formatRate(value: number): string {
    return value >= 100 ? formatNumber(Math.round(value)) : value.toFixed(1);
}

function lastValue(values: (number | null)[]): number | null {
    return [...values].reverse().find((v) => v !== null) ?? null;
}

const serverCards = computed(() => {
    const info = props.server?.info;

    return [
        { title: 'MySQL version', icon: Server, value: info?.version },
        {
            title: 'Uptime',
            icon: Clock,
            value: info ? formatDuration(info.uptimeSeconds) : undefined,
        },
        {
            title: 'Databases',
            icon: Database,
            value: info ? formatNumber(info.databaseCount) : undefined,
        },
        {
            title: 'Total size',
            icon: HardDrive,
            value: info ? formatBytes(info.totalSizeBytes) : undefined,
        },
    ];
});
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <p
            v-if="server?.error"
            class="rounded-md border border-destructive/40 bg-destructive/10 p-3 text-sm"
            data-test="server-error"
        >
            {{ server.error }}
        </p>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <Card v-for="card in serverCards" :key="card.title" class="gap-2">
                <CardHeader
                    class="flex flex-row items-center justify-between gap-2"
                >
                    <CardDescription>{{ card.title }}</CardDescription>
                    <component
                        :is="card.icon"
                        class="size-4 text-muted-foreground"
                    />
                </CardHeader>
                <CardContent>
                    <Skeleton v-if="!server" class="h-7 w-24" />
                    <p v-else class="text-2xl font-semibold tabular-nums">
                        {{ card.value ?? '—' }}
                    </p>
                </CardContent>
            </Card>
        </div>

        <div class="grid gap-4 lg:grid-cols-3" data-test="live-charts">
            <Card class="gap-3">
                <CardHeader>
                    <CardDescription>Queries per second</CardDescription>
                    <CardTitle class="text-2xl tabular-nums">
                        {{
                            lastValue(live.queriesPerSecond.value) === null
                                ? '—'
                                : formatRate(
                                      lastValue(live.queriesPerSecond.value)!,
                                  )
                        }}
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <LineChart
                        label="Queries per second over the last 5 minutes"
                        :timestamps="live.timestamps.value"
                        :series="[
                            {
                                key: 'qps',
                                label: 'Queries/s',
                                color: 'var(--chart-1)',
                                values: live.queriesPerSecond.value,
                            },
                        ]"
                        :format="formatRate"
                    />
                </CardContent>
            </Card>

            <Card class="gap-3">
                <CardHeader>
                    <CardDescription>Connections</CardDescription>
                    <CardTitle class="text-2xl tabular-nums">
                        {{
                            live.latest.value
                                ? `${formatNumber(live.latest.value.threadsConnected)} / ${formatNumber(live.latest.value.maxConnections)}`
                                : '—'
                        }}
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <LineChart
                        label="Connected clients and running threads over the last 5 minutes"
                        :timestamps="live.timestamps.value"
                        :series="[
                            {
                                key: 'connected',
                                label: 'Connected clients',
                                color: 'var(--chart-1)',
                                values: live.connected.value,
                            },
                            {
                                key: 'running',
                                label: 'Threads running',
                                color: 'var(--chart-2)',
                                values: live.running.value,
                            },
                        ]"
                    />
                    <p class="mt-2 text-xs text-muted-foreground">
                        Threads running includes MySQL's own background threads
                        (such as the event scheduler), so it can exceed the
                        connected clients.
                    </p>
                </CardContent>
            </Card>

            <Card class="gap-3">
                <CardHeader>
                    <CardDescription>InnoDB buffer pool in use</CardDescription>
                    <CardTitle class="text-2xl tabular-nums">
                        {{
                            lastValue(live.bufferPoolUsed.value) === null
                                ? '—'
                                : `${lastValue(live.bufferPoolUsed.value)!.toFixed(1)}%`
                        }}
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <LineChart
                        label="InnoDB buffer pool pages in use, percent, over the last 5 minutes"
                        :timestamps="live.timestamps.value"
                        :series="[
                            {
                                key: 'buffer',
                                label: 'In use',
                                color: 'var(--chart-2)',
                                values: live.bufferPoolUsed.value,
                            },
                        ]"
                        :y-max="100"
                        :format="(v: number) => `${Math.round(v)}%`"
                    />
                </CardContent>
            </Card>
        </div>
        <p class="text-xs text-muted-foreground">
            <template v-if="live.error.value">{{ live.error.value }}</template>
            <template v-else>
                Live: sampled every 5 seconds while this page is open; the last
                5 minutes are kept in your browser only. Counts include DBNova's
                own queries.
            </template>
        </p>
    </div>
</template>
