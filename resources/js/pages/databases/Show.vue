<script setup lang="ts">
import { Head, Link, setLayoutProps, usePage } from '@inertiajs/vue3';
import { Eye, Table2 } from '@lucide/vue';
import DropDatabaseDialog from '@/components/database/DropDatabaseDialog.vue';
import CreateTableDialog from '@/components/schema/CreateTableDialog.vue';
import RenameDatabaseDialog from '@/components/database/RenameDatabaseDialog.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { formatBytes, formatNumber } from '@/lib/format';
import { index, show } from '@/routes/databases';
import { show as tableShow } from '@/routes/tables';
import type { CollationInfo, DatabaseInfo, TableInfo } from '@/types';

const props = defineProps<{
    database: DatabaseInfo;
    tables: TableInfo[];
    renameBlockers: string[];
    columnTypes: string[];
    engines: string[];
    collations?: CollationInfo[];
}>();

setLayoutProps({
    breadcrumbs: [
        { title: 'Databases', href: index() },
        { title: props.database.name, href: show(props.database.name) },
    ],
});

const page = usePage();
</script>

<template>
    <Head :title="database.name" />

    <div class="flex flex-col gap-4 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                :title="database.name"
                :description="`${formatNumber(database.tableCount)} tables · ${formatBytes(database.sizeBytes)} · ${database.collation}`"
            />
            <div v-if="!database.isSystem" class="flex gap-2">
                <CreateTableDialog
                    :database="database.name"
                    :column-types="columnTypes"
                    :engines="engines"
                    :collations="collations"
                    :disabled="page.props.dbnova.readOnly"
                />
                <RenameDatabaseDialog
                    :database="database.name"
                    :blockers="renameBlockers"
                    :disabled="page.props.dbnova.readOnly"
                />
                <DropDatabaseDialog
                    :database="database.name"
                    :table-count="database.tableCount"
                    :disabled="page.props.dbnova.readOnly"
                />
            </div>
            <Badge v-else variant="outline">MySQL system database</Badge>
        </div>

        <Card class="overflow-hidden py-0">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b bg-muted/40 text-left">
                        <tr>
                            <th class="px-4 py-2 font-medium">Name</th>
                            <th class="px-4 py-2 font-medium">Engine</th>
                            <th
                                class="px-4 py-2 text-right font-medium"
                                title="InnoDB row counts are estimates"
                            >
                                Rows (approx.)
                            </th>
                            <th class="px-4 py-2 text-right font-medium">
                                Size
                            </th>
                            <th class="px-4 py-2 font-medium">Collation</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="table in tables"
                            :key="table.name"
                            class="hover:bg-muted/30"
                        >
                            <td class="px-4 py-2">
                                <span class="flex items-center gap-2">
                                    <Eye
                                        v-if="table.type === 'view'"
                                        class="size-4 text-muted-foreground"
                                    />
                                    <Table2
                                        v-else
                                        class="size-4 text-muted-foreground"
                                    />
                                    <Link
                                        :href="
                                            tableShow({
                                                database: database.name,
                                                table: table.name,
                                            })
                                        "
                                        class="font-medium hover:underline"
                                        >{{ table.name }}</Link
                                    >
                                </span>
                            </td>
                            <td class="px-4 py-2 text-muted-foreground">
                                {{
                                    table.type === 'view'
                                        ? 'View'
                                        : (table.engine ?? '—')
                                }}
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ formatNumber(table.approximateRows) }}
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ formatBytes(table.sizeBytes) }}
                            </td>
                            <td
                                class="px-4 py-2 font-mono text-xs text-muted-foreground"
                            >
                                {{ table.collation ?? '—' }}
                            </td>
                        </tr>
                        <tr v-if="tables.length === 0">
                            <td
                                colspan="5"
                                class="px-4 py-8 text-center text-muted-foreground"
                            >
                                This database has no tables yet.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>
    </div>
</template>
