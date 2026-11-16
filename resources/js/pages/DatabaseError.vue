<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ServerCrash } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { index } from '@/routes/system';

defineProps<{
    message: string;
}>();
</script>

<template>
    <Head title="MySQL error" />

    <div
        class="flex h-full flex-1 flex-col items-center justify-center gap-3 p-8 text-center"
    >
        <ServerCrash class="size-10 text-destructive" />
        <h1 class="text-xl font-semibold tracking-tight">
            The MySQL server could not complete this request
        </h1>
        <p class="max-w-lg font-mono text-sm text-muted-foreground">
            {{ message }}
        </p>
        <Button variant="outline" as-child>
            <Link :href="index()">Check system health</Link>
        </Button>
    </div>
</template>
