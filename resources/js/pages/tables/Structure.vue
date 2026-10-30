<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { KeyRound } from '@lucide/vue';
import TableHeader from '@/components/table/TableHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import type { ColumnInfo, IndexInfo, TableInfo } from '@/types';

defineProps<{
    database: string;
    table: TableInfo;
    columns: ColumnInfo[];
    indexes: IndexInfo[];
    createStatement: string;
}>();
</script>

<template>
    <Head :title="`${table.name} structure · ${database}`" />

    <div class="flex flex-col gap-6 p-4">
        <TableHeader :database="database" :table="table" />

        <section class="flex flex-col gap-2">
            <h2 class="text-base font-medium">Columns</h2>
            <Card class="overflow-hidden py-0">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b bg-muted/40 text-left">
                            <tr>
                                <th class="px-4 py-2 font-medium">Name</th>
                                <th class="px-4 py-2 font-medium">Type</th>
                                <th class="px-4 py-2 font-medium">Null</th>
                                <th class="px-4 py-2 font-medium">Default</th>
                                <th class="px-4 py-2 font-medium">Extra</th>
                                <th class="px-4 py-2 font-medium">Comment</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="column in columns" :key="column.name">
                                <td class="px-4 py-2 font-medium">
                                    <span class="flex items-center gap-1.5">
                                        <KeyRound
                                            v-if="column.key === 'PRI'"
                                            class="size-3.5 text-amber-600"
                                        />
                                        {{ column.name }}
                                    </span>
                                </td>
                                <td class="px-4 py-2 font-mono text-xs">
                                    {{ column.columnType }}
                                </td>
                                <td class="px-4 py-2">
                                    {{ column.nullable ? 'Yes' : 'No' }}
                                </td>
                                <td class="px-4 py-2 font-mono text-xs">
                                    <span
                                        v-if="column.default === null"
                                        class="text-muted-foreground italic"
                                        >{{
                                            column.nullable ? 'NULL' : 'none'
                                        }}</span
                                    >
                                    <template v-else>{{
                                        column.default
                                    }}</template>
                                </td>
                                <td
                                    class="px-4 py-2 font-mono text-xs text-muted-foreground"
                                >
                                    {{ column.extra }}
                                </td>
                                <td class="px-4 py-2 text-muted-foreground">
                                    {{ column.comment }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>
        </section>

        <section class="flex flex-col gap-2">
            <h2 class="text-base font-medium">Indexes</h2>
            <Card class="overflow-hidden py-0">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b bg-muted/40 text-left">
                            <tr>
                                <th class="px-4 py-2 font-medium">Name</th>
                                <th class="px-4 py-2 font-medium">Columns</th>
                                <th class="px-4 py-2 font-medium">Type</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="index in indexes" :key="index.name">
                                <td class="px-4 py-2 font-medium">
                                    {{ index.name }}
                                    <Badge
                                        v-if="index.unique"
                                        variant="outline"
                                        class="ml-2"
                                        >unique</Badge
                                    >
                                </td>
                                <td class="px-4 py-2 font-mono text-xs">
                                    {{ index.columns.join(', ') }}
                                </td>
                                <td class="px-4 py-2 text-muted-foreground">
                                    {{ index.type }}
                                </td>
                            </tr>
                            <tr v-if="indexes.length === 0">
                                <td
                                    colspan="3"
                                    class="px-4 py-6 text-center text-muted-foreground"
                                >
                                    No indexes.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>
        </section>

        <section class="flex flex-col gap-2">
            <h2 class="text-base font-medium">CREATE statement</h2>
            <pre
                class="overflow-auto rounded-md border bg-muted p-3 font-mono text-xs"
                >{{ createStatement }}</pre>
        </section>
    </div>
</template>
