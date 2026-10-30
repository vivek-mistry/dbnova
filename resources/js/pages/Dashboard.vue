<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Clock, Database, HardDrive, Server } from '@lucide/vue';
import { computed } from 'vue';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
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

        <Card class="flex-1">
            <CardHeader>
                <CardTitle>Server overview</CardTitle>
                <CardDescription>
                    Charts for queries per second and connections arrive in
                    Phase 8.
                </CardDescription>
            </CardHeader>
        </Card>
    </div>
</template>
