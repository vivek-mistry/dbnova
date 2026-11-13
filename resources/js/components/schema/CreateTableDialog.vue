<script setup lang="ts">
import { Plus, X } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import ColumnFields from '@/components/schema/ColumnFields.vue';
import SchemaChangeDialog from '@/components/schema/SchemaChangeDialog.vue';
import { columnPayload, emptyColumn } from '@/lib/columnForm';
import type { CollationInfo, ColumnForm } from '@/types';

defineProps<{
    database: string;
    columnTypes: string[];
    engines: string[];
    collations?: CollationInfo[];
    disabled?: boolean;
}>();

const name = ref('');
const engine = ref('InnoDB');
const collation = ref('');
const columns = ref<ColumnForm[]>([]);
const primaryKey = ref<string[]>([]);

function reset(): void {
    name.value = '';
    engine.value = 'InnoDB';
    collation.value = '';
    columns.value = [
        emptyColumn({
            name: 'id',
            type: 'bigint',
            length: '',
            unsigned: true,
            nullable: false,
            autoIncrement: true,
        }),
    ];
    primaryKey.value = ['id'];
}

function togglePrimary(column: string): void {
    primaryKey.value = primaryKey.value.includes(column)
        ? primaryKey.value.filter((current) => current !== column)
        : [...primaryKey.value, column];
}

function buildChange() {
    return {
        type: 'create_table',
        columns: columns.value.map(columnPayload),
        primaryKey: primaryKey.value.filter((key) =>
            columns.value.some((column) => column.name === key),
        ),
        engine: engine.value,
        collation: collation.value || null,
    };
}

const selectClass =
    'h-8 rounded-md border border-input bg-transparent px-2 text-sm shadow-xs dark:bg-input/30';
</script>

<template>
    <SchemaChangeDialog
        :database="database"
        :table="name || 'new_table'"
        title="Create table"
        :build-change="buildChange"
        trigger-label="Create table"
        trigger-variant="default"
        :trigger-icon="Plus"
        :disabled="disabled"
        wide
        @opened="reset"
    >
        <div class="grid gap-3 sm:grid-cols-3">
            <div class="grid gap-1.5">
                <Label for="create-table-name">Table name</Label>
                <Input
                    id="create-table-name"
                    v-model="name"
                    class="h-8 font-mono"
                    maxlength="64"
                    required
                />
            </div>
            <div class="grid gap-1.5">
                <Label for="create-table-engine">Engine</Label>
                <select
                    id="create-table-engine"
                    v-model="engine"
                    :class="selectClass"
                >
                    <option v-for="option in engines" :key="option">
                        {{ option }}
                    </option>
                </select>
            </div>
            <div class="grid gap-1.5">
                <Label for="create-table-collation">Collation</Label>
                <select
                    id="create-table-collation"
                    v-model="collation"
                    :class="selectClass"
                >
                    <option value="">Database default</option>
                    <option
                        v-for="option in collations ?? []"
                        :key="option.name"
                        :value="option.name"
                    >
                        {{ option.name }}
                    </option>
                </select>
            </div>
        </div>

        <div
            v-for="(column, index) in columns"
            :key="index"
            class="space-y-2 rounded-md border p-3"
        >
            <div class="flex items-center justify-between gap-2">
                <label class="flex items-center gap-1.5 text-sm">
                    <input
                        type="checkbox"
                        :checked="primaryKey.includes(column.name)"
                        :disabled="column.name === ''"
                        @change="togglePrimary(column.name)"
                    />
                    Primary key
                </label>
                <Button
                    v-if="columns.length > 1"
                    type="button"
                    variant="ghost"
                    size="icon"
                    :aria-label="`Remove column ${column.name || index + 1}`"
                    @click="columns.splice(index, 1)"
                >
                    <X />
                </Button>
            </div>
            <ColumnFields
                v-model="columns[index]!"
                :column-types="columnTypes"
                :collations="collations"
                :id-prefix="`create-column-${index}`"
            />
        </div>

        <Button
            type="button"
            variant="outline"
            size="sm"
            @click="columns.push(emptyColumn())"
        >
            <Plus />
            Add column
        </Button>
    </SchemaChangeDialog>
</template>
