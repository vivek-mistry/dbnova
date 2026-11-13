<script setup lang="ts">
import { Pencil, Plus } from '@lucide/vue';
import { ref } from 'vue';
import { Label } from '@/components/ui/label';
import ColumnFields from '@/components/schema/ColumnFields.vue';
import SchemaChangeDialog from '@/components/schema/SchemaChangeDialog.vue';
import { columnFromInfo, columnPayload, emptyColumn } from '@/lib/columnForm';
import type { CollationInfo, ColumnForm, ColumnInfo } from '@/types';

const props = defineProps<{
    database: string;
    table: string;
    columns: ColumnInfo[];
    columnTypes: string[];
    collations?: CollationInfo[];
    /** The column to edit; omit to add a new one. */
    column?: ColumnInfo;
}>();

const form = ref<ColumnForm>(emptyColumn());
const position = ref('');

function reset(): void {
    form.value = props.column ? columnFromInfo(props.column) : emptyColumn();
    position.value = '';
}

function buildChange() {
    const placement = {
        first: position.value === '__first',
        after:
            position.value !== '' && position.value !== '__first'
                ? position.value
                : null,
    };

    return props.column
        ? {
              type: 'modify_column',
              from: props.column.name,
              column: columnPayload(form.value),
              ...placement,
          }
        : {
              type: 'add_column',
              column: columnPayload(form.value),
              ...placement,
          };
}
</script>

<template>
    <SchemaChangeDialog
        :database="database"
        :table="table"
        :title="column ? `Edit column ${column.name}` : 'Add column'"
        :description="
            column
                ? 'MySQL rewrites the whole column definition; anything left out reverts to its default.'
                : undefined
        "
        :build-change="buildChange"
        :trigger-label="column ? `Edit ${column.name}` : 'Add column'"
        :trigger-size="column ? 'icon' : 'sm'"
        :trigger-variant="column ? 'ghost' : 'outline'"
        :trigger-icon="column ? Pencil : Plus"
        @opened="reset"
    >
        <ColumnFields
            v-model="form"
            :column-types="columnTypes"
            :collations="collations"
            :id-prefix="column ? `edit-${column.name}` : 'add-column'"
        />
        <div class="grid gap-1.5">
            <Label :for="column ? `position-${column.name}` : 'add-position'"
                >Position</Label
            >
            <select
                :id="column ? `position-${column.name}` : 'add-position'"
                v-model="position"
                class="h-8 rounded-md border border-input bg-transparent px-2 text-sm shadow-xs dark:bg-input/30"
            >
                <option value="">
                    {{ column ? 'Keep current position' : 'At the end' }}
                </option>
                <option value="__first">First</option>
                <template v-for="other in columns" :key="other.name">
                    <option
                        v-if="other.name !== column?.name"
                        :value="other.name"
                    >
                        After {{ other.name }}
                    </option>
                </template>
            </select>
        </div>
    </SchemaChangeDialog>
</template>
