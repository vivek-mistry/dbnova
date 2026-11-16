<script setup lang="ts">
import { Plus } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import type { ColumnInfo } from '@/types';

type Mode = 'default' | 'null' | 'value';

const props = defineProps<{
    columns: ColumnInfo[];
    saving: boolean;
}>();

const emit = defineEmits<{
    insert: [values: Record<string, string | null>, done: () => void];
}>();

const open = ref(false);

const editableColumns = computed(() =>
    props.columns.filter(
        (column) =>
            !column.isBinary &&
            !column.extra.toUpperCase().includes('GENERATED'),
    ),
);

function initialMode(column: ColumnInfo): Mode {
    return column.default !== null ||
        column.nullable ||
        column.extra.includes('auto_increment')
        ? 'default'
        : 'value';
}

const modes = ref<Record<string, Mode>>({});
const values = ref<Record<string, string>>({});

function reset(): void {
    modes.value = Object.fromEntries(
        editableColumns.value.map((column) => [
            column.name,
            initialMode(column),
        ]),
    );
    values.value = Object.fromEntries(
        editableColumns.value.map((column) => [column.name, '']),
    );
}

function submit(): void {
    const payload: Record<string, string | null> = {};

    for (const column of editableColumns.value) {
        const mode = modes.value[column.name];

        if (mode === 'null') {
            payload[column.name] = null;
        } else if (mode === 'value') {
            payload[column.name] = values.value[column.name] ?? '';
        }
    }

    emit('insert', payload, () => (open.value = false));
}

const selectClass =
    'h-8 rounded-md border border-input bg-transparent px-2 text-xs shadow-xs dark:bg-input/30';
</script>

<template>
    <Dialog v-model:open="open" @update:open="(isOpen) => isOpen && reset()">
        <DialogTrigger as-child>
            <Button size="sm" variant="outline">
                <Plus />
                Insert row
            </Button>
        </DialogTrigger>
        <DialogContent class="sm:max-w-2xl">
            <form class="space-y-4" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>Insert row</DialogTitle>
                    <DialogDescription>
                        Columns set to "Default" are left out, so MySQL uses
                        their default or auto-increment value.
                    </DialogDescription>
                </DialogHeader>

                <div class="max-h-[60vh] space-y-2 overflow-y-auto pr-1">
                    <div
                        v-for="column in editableColumns"
                        :key="column.name"
                        class="grid grid-cols-[minmax(0,10rem)_7rem_minmax(0,1fr)] items-center gap-2"
                    >
                        <label
                            :for="`insert-${column.name}`"
                            class="truncate font-mono text-xs"
                            :title="column.columnType"
                        >
                            {{ column.name }}
                            <span
                                class="block text-[10px] text-muted-foreground"
                            >
                                {{ column.columnType }}
                            </span>
                        </label>
                        <select
                            v-model="modes[column.name]"
                            :class="selectClass"
                            :aria-label="`${column.name} value type`"
                        >
                            <option value="default">Default</option>
                            <option v-if="column.nullable" value="null">
                                NULL
                            </option>
                            <option value="value">Value</option>
                        </select>
                        <Input
                            v-if="modes[column.name] === 'value'"
                            :id="`insert-${column.name}`"
                            v-model="values[column.name]"
                            class="h-8 font-mono text-xs"
                        />
                        <span
                            v-else
                            class="text-xs text-muted-foreground italic"
                        >
                            {{
                                modes[column.name] === 'null'
                                    ? 'NULL'
                                    : (column.default ??
                                      (column.extra.includes('auto_increment')
                                          ? 'auto increment'
                                          : 'column default'))
                            }}
                        </span>
                    </div>
                </div>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button variant="secondary">Cancel</Button>
                    </DialogClose>
                    <Button type="submit" :disabled="saving">
                        <Spinner v-if="saving" />
                        Insert
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
