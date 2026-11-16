<script setup lang="ts">
import { Copy, Eraser, Pencil, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import SchemaChangeDialog from '@/components/schema/SchemaChangeDialog.vue';

defineProps<{
    database: string;
    table: string;
}>();

const newName = ref('');
const copyName = ref('');
const copyWithData = ref(false);
</script>

<template>
    <div class="flex flex-wrap gap-2">
        <SchemaChangeDialog
            :database="database"
            :table="table"
            :title="`Rename ${table}`"
            :build-change="() => ({ type: 'rename_table', newName })"
            trigger-label="Rename"
            :trigger-icon="Pencil"
            @opened="newName = table"
        >
            <div class="grid gap-1.5">
                <Label for="rename-table">New name</Label>
                <Input
                    id="rename-table"
                    v-model="newName"
                    class="h-8 font-mono"
                    maxlength="64"
                />
            </div>
        </SchemaChangeDialog>

        <SchemaChangeDialog
            :database="database"
            :table="table"
            :title="`Copy ${table}`"
            description="Copies the structure (columns, indexes). Foreign keys and triggers are not copied."
            :build-change="
                () => ({
                    type: 'copy_table',
                    newName: copyName,
                    withData: copyWithData,
                })
            "
            trigger-label="Copy"
            :trigger-icon="Copy"
            @opened="
                () => {
                    copyName = `${table}_copy`;
                    copyWithData = false;
                }
            "
        >
            <div class="grid gap-1.5">
                <Label for="copy-table">New table name</Label>
                <Input
                    id="copy-table"
                    v-model="copyName"
                    class="h-8 font-mono"
                    maxlength="64"
                />
            </div>
            <label class="flex items-center gap-1.5 text-sm">
                <input v-model="copyWithData" type="checkbox" />
                Copy the rows too
            </label>
        </SchemaChangeDialog>

        <SchemaChangeDialog
            :database="database"
            :table="table"
            :title="`Empty ${table}?`"
            description="TRUNCATE deletes every row and resets AUTO_INCREMENT."
            :build-change="() => ({ type: 'truncate_table' })"
            destructive
            trigger-label="Truncate"
            :trigger-icon="Eraser"
        >
            <p class="text-sm">
                Preview the SQL to continue. You will confirm with the table
                name and your MySQL password.
            </p>
        </SchemaChangeDialog>

        <SchemaChangeDialog
            :database="database"
            :table="table"
            :title="`Drop ${table}?`"
            description="DROP TABLE deletes the table and all its rows."
            :build-change="() => ({ type: 'drop_table' })"
            destructive
            trigger-label="Drop"
            trigger-variant="destructive"
            :trigger-icon="Trash2"
        >
            <p class="text-sm">
                Preview the SQL to continue. You will confirm with the table
                name and your MySQL password.
            </p>
        </SchemaChangeDialog>
    </div>
</template>
