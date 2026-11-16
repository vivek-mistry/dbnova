<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { index as exportsIndex } from '@/routes/exports';
import { index as importIndex } from '@/routes/import-export';

defineProps<{ active: 'import' | 'export' }>();

const tabClass = (isActive: boolean): string =>
    [
        'rounded-md px-3 py-1.5 text-sm font-medium transition-colors',
        isActive
            ? 'bg-background text-foreground shadow-xs'
            : 'text-muted-foreground hover:text-foreground',
    ].join(' ');
</script>

<template>
    <nav
        class="inline-flex w-fit gap-1 rounded-lg bg-muted p-1"
        aria-label="Import or export"
    >
        <Link
            :href="importIndex()"
            :class="tabClass(active === 'import')"
            :aria-current="active === 'import' ? 'page' : undefined"
            data-test="tab-import"
        >
            Import
        </Link>
        <Link
            :href="exportsIndex()"
            :class="tabClass(active === 'export')"
            :aria-current="active === 'export' ? 'page' : undefined"
            data-test="tab-export"
        >
            Export
        </Link>
    </nav>
</template>
