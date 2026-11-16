<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { KeyRound, Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import ColumnChangeDialog from '@/components/schema/ColumnChangeDialog.vue';
import ForeignKeyDialog from '@/components/schema/ForeignKeyDialog.vue';
import IndexDialog from '@/components/schema/IndexDialog.vue';
import SchemaChangeDialog from '@/components/schema/SchemaChangeDialog.vue';
import TableOperations from '@/components/schema/TableOperations.vue';
import TableHeader from '@/components/table/TableHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import type {
    CollationInfo,
    ColumnInfo,
    ForeignKeyInfo,
    IndexInfo,
    TableInfo,
} from '@/types';

const props = defineProps<{
    database: string;
    table: TableInfo;
    columns: ColumnInfo[];
    indexes: IndexInfo[];
    foreignKeys: ForeignKeyInfo[];
    createStatement: string;
    otherTables: string[];
    columnTypes: string[];
    collations?: CollationInfo[];
}>();

const page = usePage();

// Views are edited with CREATE OR REPLACE VIEW in the SQL editor, not here.
const canEdit = computed(
    () => !page.props.dbnova.readOnly && props.table.type === 'table',
);
</script>

<template>
    <Head :title="`${table.name} structure · ${database}`" />

    <div class="flex flex-col gap-6 p-4">
        <TableHeader :database="database" :table="table" />

        <TableOperations
            v-if="canEdit"
            :database="database"
            :table="table.name"
        />

        <section class="flex flex-col gap-2">
            <div class="flex items-center justify-between gap-2">
                <h2 class="text-base font-medium">Columns</h2>
                <ColumnChangeDialog
                    v-if="canEdit"
                    :database="database"
                    :table="table.name"
                    :columns="columns"
                    :column-types="columnTypes"
                    :collations="collations"
                />
            </div>
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
                                <th v-if="canEdit" class="px-4 py-2" />
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
                                    <span
                                        v-if="column.collation"
                                        class="block text-muted-foreground"
                                        >{{ column.collation }}</span
                                    >
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
                                <td v-if="canEdit" class="px-2 py-1">
                                    <div class="flex justify-end gap-1">
                                        <ColumnChangeDialog
                                            :database="database"
                                            :table="table.name"
                                            :columns="columns"
                                            :column-types="columnTypes"
                                            :collations="collations"
                                            :column="column"
                                        />
                                        <SchemaChangeDialog
                                            :database="database"
                                            :table="table.name"
                                            :title="`Drop column ${column.name}?`"
                                            description="The column and all its values are deleted."
                                            :build-change="
                                                () => ({
                                                    type: 'drop_column',
                                                    column: column.name,
                                                })
                                            "
                                            destructive
                                            :trigger-label="`Drop ${column.name}`"
                                            trigger-size="icon"
                                            trigger-variant="ghost"
                                            :trigger-icon="Trash2"
                                        >
                                            <p class="text-sm">
                                                Preview the SQL to continue.
                                            </p>
                                        </SchemaChangeDialog>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>
        </section>

        <section class="flex flex-col gap-2">
            <div class="flex items-center justify-between gap-2">
                <h2 class="text-base font-medium">Indexes</h2>
                <IndexDialog
                    v-if="canEdit"
                    :database="database"
                    :table="table.name"
                    :columns="columns"
                />
            </div>
            <Card class="overflow-hidden py-0">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b bg-muted/40 text-left">
                            <tr>
                                <th class="px-4 py-2 font-medium">Name</th>
                                <th class="px-4 py-2 font-medium">Columns</th>
                                <th class="px-4 py-2 font-medium">Type</th>
                                <th v-if="canEdit" class="px-4 py-2" />
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
                                <td v-if="canEdit" class="px-2 py-1 text-right">
                                    <SchemaChangeDialog
                                        :database="database"
                                        :table="table.name"
                                        :title="`Drop index ${index.name}?`"
                                        :build-change="
                                            () => ({
                                                type: 'drop_index',
                                                name: index.name,
                                            })
                                        "
                                        :trigger-label="`Drop ${index.name}`"
                                        trigger-size="icon"
                                        trigger-variant="ghost"
                                        :trigger-icon="Trash2"
                                    >
                                        <p class="text-sm">
                                            Queries that use this index may
                                            become slower.
                                        </p>
                                    </SchemaChangeDialog>
                                </td>
                            </tr>
                            <tr v-if="indexes.length === 0">
                                <td
                                    :colspan="canEdit ? 4 : 3"
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

        <section v-if="table.type === 'table'" class="flex flex-col gap-2">
            <div class="flex items-center justify-between gap-2">
                <h2 class="text-base font-medium">Foreign keys</h2>
                <ForeignKeyDialog
                    v-if="canEdit"
                    :database="database"
                    :table="table.name"
                    :columns="columns"
                    :other-tables="otherTables"
                />
            </div>
            <Card class="overflow-hidden py-0">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b bg-muted/40 text-left">
                            <tr>
                                <th class="px-4 py-2 font-medium">Name</th>
                                <th class="px-4 py-2 font-medium">Columns</th>
                                <th class="px-4 py-2 font-medium">
                                    References
                                </th>
                                <th class="px-4 py-2 font-medium">
                                    On delete / update
                                </th>
                                <th v-if="canEdit" class="px-4 py-2" />
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="foreignKey in foreignKeys"
                                :key="foreignKey.name"
                            >
                                <td class="px-4 py-2 font-medium">
                                    {{ foreignKey.name }}
                                </td>
                                <td class="px-4 py-2 font-mono text-xs">
                                    {{ foreignKey.columns.join(', ') }}
                                </td>
                                <td class="px-4 py-2 font-mono text-xs">
                                    {{ foreignKey.referencedTable }}({{
                                        foreignKey.referencedColumns.join(', ')
                                    }})
                                </td>
                                <td class="px-4 py-2 text-muted-foreground">
                                    {{ foreignKey.onDelete }} /
                                    {{ foreignKey.onUpdate }}
                                </td>
                                <td v-if="canEdit" class="px-2 py-1 text-right">
                                    <SchemaChangeDialog
                                        :database="database"
                                        :table="table.name"
                                        :title="`Drop foreign key ${foreignKey.name}?`"
                                        :build-change="
                                            () => ({
                                                type: 'drop_foreign_key',
                                                name: foreignKey.name,
                                            })
                                        "
                                        :trigger-label="`Drop ${foreignKey.name}`"
                                        trigger-size="icon"
                                        trigger-variant="ghost"
                                        :trigger-icon="Trash2"
                                    >
                                        <p class="text-sm">
                                            MySQL keeps the index it created for
                                            the key.
                                        </p>
                                    </SchemaChangeDialog>
                                </td>
                            </tr>
                            <tr v-if="foreignKeys.length === 0">
                                <td
                                    :colspan="canEdit ? 5 : 4"
                                    class="px-4 py-6 text-center text-muted-foreground"
                                >
                                    No foreign keys.
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
