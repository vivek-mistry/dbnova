<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Clock, Database, HardDrive, Server } from '@lucide/vue';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { dashboard } from '@/routes';

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

const serverCards = [
    { title: 'MySQL version', icon: Server },
    { title: 'Uptime', icon: Clock },
    { title: 'Databases', icon: Database },
    { title: 'Total size', icon: HardDrive },
];
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
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
                    <Skeleton class="h-7 w-24" />
                </CardContent>
            </Card>
        </div>

        <Card class="flex-1">
            <CardHeader>
                <CardTitle>Server overview</CardTitle>
                <CardDescription>
                    Live server statistics arrive in Phase 2, once DBNova is
                    connected to MySQL through its adapter layer.
                </CardDescription>
            </CardHeader>
        </Card>
    </div>
</template>
