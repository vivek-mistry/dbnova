<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { cn } from '@/lib/utils';
import { index as processesIndex } from '@/routes/processes';
import { status, variables } from '@/routes/server';

defineProps<{ active: 'processes' | 'variables' | 'status' }>();

const tabs = [
    { key: 'processes', title: 'Processes', href: processesIndex() },
    { key: 'variables', title: 'Variables', href: variables() },
    { key: 'status', title: 'Status', href: status() },
] as const;
</script>

<template>
    <nav class="flex gap-1 border-b" aria-label="Server sections">
        <Link
            v-for="tab in tabs"
            :key="tab.key"
            :href="tab.href"
            :aria-current="active === tab.key ? 'page' : undefined"
            :class="
                cn(
                    '-mb-px border-b-2 px-3 py-1.5 text-sm',
                    active === tab.key
                        ? 'border-primary font-medium'
                        : 'border-transparent text-muted-foreground hover:text-foreground',
                )
            "
        >
            {{ tab.title }}
        </Link>
    </nav>
</template>
