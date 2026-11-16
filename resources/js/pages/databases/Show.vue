<script setup lang="ts">
import { Head, Link, router, setLayoutProps, usePage } from '@inertiajs/vue3';
import { ChevronDown, Eye, SquareTerminal, Table2, Wrench } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import DropDatabaseDialog from '@/components/database/DropDatabaseDialog.vue';
import StorageBreakdown from '@/components/database/StorageBreakdown.vue';
import TableMaintenanceDialog from '@/components/database/TableMaintenanceDialog.vue';
import CreateTableDialog from '@/components/schema/CreateTableDialog.vue';
import RenameDatabaseDialog from '@/components/database/RenameDatabaseDialog.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { formatBytes, formatNumber } from '@/lib/format';
import { index, show } from '@/routes/databases';
import { index as sqlIndex } from '@/routes/sql';
import { show as tableShow } from '@/routes/tables';
import type {
    CollationInfo,
    DatabaseInfo,
    MaintenanceOperation,
    TableInfo,
} from '@/types';

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
const readOnly = computed(() => page.props.dbnova.readOnly);

/** Maintenance works on base tables; views are not selectable. */
const baseTables = computed(() =>
    props.tables.filter((table) => table.type === 'table'),
);

const selected = ref<string[]>([]);

// Forget tables that disappeared after a reload.
watch(
    () => props.tables,
    (tables) => {
        const names = new Set(tables.map((table) => table.name));
        selected.value = selected.value.filter((name) => names.has(name));
    },
);

const allSelected = computed<boolean | 'indeterminate'>(() => {
    if (selected.value.length === 0) {
        return false;
    }

    return selected.value.length === baseTables.value.length
        ? true
        : 'indeterminate';
});

function toggleAll(checked: boolean | 'indeterminate'): void {
    selected.value =
        checked === true ? baseTables.value.map((table) => table.name) : [];
}

function toggle(name: string, checked: boolean | 'indeterminate'): void {
    selected.value =
        checked === true
            ? [...selected.value, name]
            : selected.value.filter((other) => other !== name);
}

const operation = ref<MaintenanceOperation | null>(null);

function onMaintenanceDone(done: MaintenanceOperation): void {
    // OPTIMIZE and ANALYZE change sizes and row estimates.
    if (done !== 'check') {
        router.reload({ only: ['tables', 'database'] });
    }
}

const operations: {
    value: MaintenanceOperation;
    label: string;
    hint: string;
    writes: boolean;
}[] = [
    {
        value: 'analyze',
        label: 'Analyze',
        hint: 'Refresh index statistics',
        writes: true,
    },
    {
        value: 'optimize',
        label: 'Optimize',
        hint: 'Rebuild and reclaim space',
        writes: true,
    },
    {
        value: 'check',
        label: 'Check',
        hint: 'Look for errors (read-only)',
        writes: false,
    },
    {
        value: 'repair',
        label: 'Repair',
        hint: 'MyISAM, ARCHIVE and CSV only',
        writes: true,
    },
];
</script>

<template>
    <Head :title="database.name" />

    <div class="flex flex-col gap-4 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                :title="database.name"
                :description="`${formatNumber(database.tableCount)} tables · ${formatBytes(database.sizeBytes)} · ${database.collation}`"
            />
            <div class="flex gap-2">
                <Button variant="outline" size="sm" as-child>
                    <Link
                        :href="sqlIndex({ query: { database: database.name } })"
                    >
                        <SquareTerminal />
                        SQL
                    </Link>
                </Button>
            </div>
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

        <Card v-if="tables.length > 0" class="px-4 py-3">
            <StorageBreakdown :tables="tables" />
        </Card>

        <div
            v-if="baseTables.length > 0"
            class="flex min-h-9 flex-wrap items-center gap-3"
        >
            <span class="text-sm text-muted-foreground">
                {{
                    selected.length === 0
                        ? 'Select tables to run maintenance.'
                        : `${selected.length} of ${baseTables.length} tables selected`
                }}
            </span>
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="selected.length === 0"
                        data-test="maintenance-menu"
                    >
                        <Wrench />
                        Maintenance
                        <ChevronDown />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="start" class="w-60">
                    <template v-for="(op, i) in operations" :key="op.value">
                        <DropdownMenuSeparator v-if="i === 2" />
                        <DropdownMenuItem
                            :disabled="op.writes && readOnly"
                            :data-test="`maintenance-${op.value}`"
                            @select="operation = op.value"
                        >
                            <div class="flex flex-col">
                                <span>{{ op.label }}</span>
                                <span class="text-xs text-muted-foreground">{{
                                    op.writes && readOnly
                                        ? 'Not allowed in read-only mode'
                                        : op.hint
                                }}</span>
                            </div>
                        </DropdownMenuItem>
                    </template>
                </DropdownMenuContent>
            </DropdownMenu>
            <Button
                v-if="selected.length > 0"
                variant="ghost"
                size="sm"
                @click="selected = []"
            >
                Clear
            </Button>
        </div>

        <TableMaintenanceDialog
            :database="database.name"
            :tables="selected"
            :operation="operation"
            @close="operation = null"
            @done="onMaintenanceDone"
        />

        <Card class="overflow-hidden py-0">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b bg-muted/40 text-left">
                        <tr>
                            <th class="w-10 py-2 pl-4">
                                <Checkbox
                                    v-if="baseTables.length > 0"
                                    :model-value="allSelected"
                                    aria-label="Select all tables"
                                    data-test="select-all-tables"
                                    @update:model-value="toggleAll"
                                />
                            </th>
                            <th class="px-4 py-2 font-medium">Name</th>
                            <th class="px-4 py-2 font-medium">Engine</th>
                            <th
                                class="px-4 py-2 text-right font-medium"
                                title="InnoDB row counts are estimates"
                            >
                                Rows (approx.)
                            </th>
                            <th class="px-4 py-2 text-right font-medium">
                                Data
                            </th>
                            <th class="px-4 py-2 text-right font-medium">
                                Indexes
                            </th>
                            <th
                                class="px-4 py-2 text-right font-medium"
                                title="Allocated but unused space (DATA_FREE). OPTIMIZE TABLE can return some of it to the disk; InnoDB always keeps a few MB free per table, so small values are normal."
                            >
                                Free
                            </th>
                            <th class="px-4 py-2 text-right font-medium">
                                Total
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
                            <td class="py-2 pl-4">
                                <Checkbox
                                    v-if="table.type === 'table'"
                                    :model-value="selected.includes(table.name)"
                                    :aria-label="`Select ${table.name}`"
                                    :data-test="`select-table-${table.name}`"
                                    @update:model-value="
                                        (checked) => toggle(table.name, checked)
                                    "
                                />
                            </td>
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
                            <td
                                class="px-4 py-2 text-right text-muted-foreground tabular-nums"
                            >
                                {{ formatBytes(table.dataBytes) }}
                            </td>
                            <td
                                class="px-4 py-2 text-right text-muted-foreground tabular-nums"
                            >
                                {{ formatBytes(table.indexBytes) }}
                            </td>
                            <td
                                class="px-4 py-2 text-right text-muted-foreground tabular-nums"
                            >
                                {{
                                    table.freeBytes
                                        ? formatBytes(table.freeBytes)
                                        : '—'
                                }}
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
                                colspan="9"
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
