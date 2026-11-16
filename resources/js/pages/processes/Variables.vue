<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ServerTabs from '@/components/server/ServerTabs.vue';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { formatNumber } from '@/lib/format';
import { index } from '@/routes/processes';
import type { NameValueRow } from '@/types';

const props = defineProps<{
    kind: 'variables' | 'status';
    rows?: { items: NameValueRow[]; error: string | null };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Processes', href: index() }],
    },
});

const search = ref('');

const visible = computed(() => {
    const needle = search.value.trim().toLowerCase();
    const items = props.rows?.items ?? [];

    return needle === ''
        ? items
        : items.filter(
              (row) =>
                  row.name.toLowerCase().includes(needle) ||
                  row.value.toLowerCase().includes(needle),
          );
});

/** Counters are easier to read with thousands separators. */
function display(value: string): string {
    return props.kind === 'status' && /^\d{4,}$/.test(value)
        ? formatNumber(Number(value))
        : value;
}
</script>

<template>
    <Head
        :title="kind === 'variables' ? 'Server variables' : 'Server status'"
    />

    <div class="flex flex-1 flex-col gap-4 p-4 lg:p-6">
        <div class="flex flex-col gap-3">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">Server</h1>
                <p class="text-sm text-muted-foreground">
                    <template v-if="kind === 'variables'">
                        Global configuration variables (SHOW GLOBAL VARIABLES).
                        Change them in the MySQL configuration file or with SET
                        PERSIST in the SQL editor.
                    </template>
                    <template v-else>
                        Global status counters since the server started (SHOW
                        GLOBAL STATUS). The dashboard charts these as rates.
                    </template>
                </p>
            </div>
            <ServerTabs :active="kind" />
        </div>

        <div class="flex items-center gap-3">
            <Input
                v-model="search"
                :placeholder="`Filter ${kind === 'variables' ? 'variables' : 'counters'}…`"
                class="h-9 w-full max-w-sm"
                data-test="variable-search"
            />
            <span v-if="rows" class="text-xs text-muted-foreground">
                {{ formatNumber(visible.length) }} of
                {{ formatNumber(rows.items.length) }}
            </span>
        </div>

        <p
            v-if="rows?.error"
            class="rounded-md border border-destructive/40 bg-destructive/10 p-3 text-sm"
        >
            {{ rows.error }}
        </p>

        <Skeleton v-if="!rows" class="h-96 w-full" />

        <div v-else class="overflow-x-auto rounded-lg border">
            <table class="w-full text-sm" data-test="variable-table">
                <thead
                    class="bg-muted/50 text-left text-xs text-muted-foreground"
                >
                    <tr>
                        <th class="w-1/3 px-3 py-2 font-medium">Name</th>
                        <th class="px-3 py-2 font-medium">Value</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="row in visible" :key="row.name">
                        <td class="px-3 py-1.5 font-mono text-xs">
                            {{ row.name }}
                        </td>
                        <td
                            class="px-3 py-1.5 font-mono text-xs break-all tabular-nums"
                        >
                            <span
                                v-if="row.value === ''"
                                class="text-muted-foreground"
                                >(empty)</span
                            >
                            <template v-else>{{ display(row.value) }}</template>
                        </td>
                    </tr>
                    <tr v-if="visible.length === 0">
                        <td
                            colspan="2"
                            class="px-3 py-8 text-center text-muted-foreground"
                        >
                            Nothing matches.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
