<script setup lang="ts">
import { Link, setLayoutProps } from '@inertiajs/vue3';
import { Eye, Table2 } from '@lucide/vue';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { formatBytes, formatNumber } from '@/lib/format';
import { cn } from '@/lib/utils';
import {
    index as databasesIndex,
    show as databaseShow,
} from '@/routes/databases';
import {
    show as tableShow,
    structure as tableStructure,
} from '@/routes/tables';
import { index as exportsIndex } from '@/routes/exports';
import { index as sqlIndex } from '@/routes/sql';
import type { TableInfo } from '@/types';

const props = defineProps<{
    database: string;
    table: TableInfo;
}>();

const routeArgs = { database: props.database, table: props.table.name };

setLayoutProps({
    breadcrumbs: [
        { title: 'Databases', href: databasesIndex() },
        { title: props.database, href: databaseShow(props.database) },
        { title: props.table.name, href: tableShow(routeArgs) },
    ],
});

const { isCurrentUrl } = useCurrentUrl();

const tabs = [
    { title: 'Browse', href: tableShow(routeArgs) },
    { title: 'Structure', href: tableStructure(routeArgs) },
    {
        title: 'SQL',
        href: sqlIndex({
            query: { database: props.database, table: props.table.name },
        }),
    },
    {
        title: 'Export',
        href: exportsIndex({
            query: { database: props.database, table: props.table.name },
        }),
    },
];
</script>

<template>
    <div class="flex flex-col gap-3">
        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
            <h1
                class="flex items-center gap-2 text-xl font-semibold tracking-tight"
            >
                <Eye
                    v-if="table.type === 'view'"
                    class="size-5 text-muted-foreground"
                />
                <Table2 v-else class="size-5 text-muted-foreground" />
                {{ table.name }}
            </h1>
            <p class="text-sm text-muted-foreground">
                {{ table.type === 'view' ? 'View' : table.engine }} · ~{{
                    formatNumber(table.approximateRows)
                }}
                rows · {{ formatBytes(table.sizeBytes) }}
            </p>
        </div>

        <nav class="flex gap-1 border-b" aria-label="Table sections">
            <Link
                v-for="tab in tabs"
                :key="tab.title"
                :href="tab.href"
                :class="
                    cn(
                        '-mb-px border-b-2 px-3 py-1.5 text-sm',
                        isCurrentUrl(tab.href)
                            ? 'border-primary font-medium'
                            : 'border-transparent text-muted-foreground hover:text-foreground',
                    )
                "
            >
                {{ tab.title }}
            </Link>
        </nav>
    </div>
</template>
