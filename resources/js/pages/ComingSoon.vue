<script setup lang="ts">
import { Head, setLayoutProps, usePage } from '@inertiajs/vue3';
import { Construction } from '@lucide/vue';

const props = defineProps<{
    title: string;
    phase: number;
}>();

setLayoutProps({
    breadcrumbs: [
        {
            title: props.title,
            href: usePage().url,
        },
    ],
});
</script>

<template>
    <Head :title="props.title" />

    <div
        class="flex h-full flex-1 flex-col items-center justify-center gap-3 p-8 text-center"
    >
        <Construction class="size-10 text-muted-foreground" />
        <h1 class="text-xl font-semibold tracking-tight">{{ props.title }}</h1>
        <p class="max-w-md text-sm text-muted-foreground">
            This section is coming in Phase {{ props.phase }} of the DBNova
            roadmap.
        </p>
    </div>
</template>
