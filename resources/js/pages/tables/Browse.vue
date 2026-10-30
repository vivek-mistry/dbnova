<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import DataGrid from '@/components/data-grid/DataGrid.vue';
import TableHeader from '@/components/table/TableHeader.vue';
import type { ColumnInfo, TableInfo, TablePage } from '@/types';

const props = defineProps<{
    database: string;
    table: TableInfo;
    columns: ColumnInfo[];
    keyColumns: string[];
    pageSize: number;
    firstPage: TablePage;
}>();

const page = usePage();

// Rows can be changed only in tables whose rows can be told apart by a key.
const editable =
    !page.props.dbnova.readOnly &&
    props.table.type === 'table' &&
    props.keyColumns.length > 0;
</script>

<template>
    <Head :title="`${table.name} · ${database}`" />

    <div class="flex h-[calc(100svh-4rem)] flex-col gap-4 p-4">
        <TableHeader :database="database" :table="table" />

        <p v-if="keyColumns.length === 0" class="text-xs text-muted-foreground">
            This {{ table.type }} has no primary or unique key, so rows are
            paged by offset and full values of long cells cannot be loaded.
        </p>

        <DataGrid
            :key="`${database}.${table.name}`"
            :database="database"
            :table="table.name"
            :columns="columns"
            :key-columns="keyColumns"
            :page-size="pageSize"
            :first-page="firstPage"
            :approximate-rows="table.approximateRows"
            :editable="editable"
        />
    </div>
</template>
