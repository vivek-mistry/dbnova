<script setup lang="ts">
import { Filter as FilterIcon, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { ColumnFilter, ColumnInfo, FilterOperator } from '@/types';

const props = defineProps<{
    columns: ColumnInfo[];
    filters: ColumnFilter[];
}>();

const emit = defineEmits<{
    change: [filters: ColumnFilter[]];
}>();

const operators: { value: FilterOperator; label: string }[] = [
    { value: '=', label: '=' },
    { value: '!=', label: '≠' },
    { value: 'like', label: 'LIKE' },
    { value: 'not_like', label: 'NOT LIKE' },
    { value: '>', label: '>' },
    { value: '>=', label: '≥' },
    { value: '<', label: '<' },
    { value: '<=', label: '≤' },
    { value: 'is_null', label: 'IS NULL' },
    { value: 'is_not_null', label: 'IS NOT NULL' },
];

const column = ref(props.columns[0]?.name ?? '');
const operator = ref<FilterOperator>('=');
const value = ref('');

const needsValue = computed(
    () => operator.value !== 'is_null' && operator.value !== 'is_not_null',
);

const selectClass =
    'h-8 rounded-md border border-input bg-transparent px-2 text-sm shadow-xs dark:bg-input/30';

function operatorLabel(filterOperator: FilterOperator): string {
    return (
        operators.find((option) => option.value === filterOperator)?.label ??
        filterOperator
    );
}

function add(): void {
    if (column.value === '') {
        return;
    }

    emit('change', [
        ...props.filters,
        {
            column: column.value,
            operator: operator.value,
            value: needsValue.value ? value.value : '',
        },
    ]);
    value.value = '';
}

function remove(index: number): void {
    emit(
        'change',
        props.filters.filter((_, filterIndex) => filterIndex !== index),
    );
}
</script>

<template>
    <div class="flex flex-col gap-2">
        <form class="flex flex-wrap items-center gap-2" @submit.prevent="add">
            <FilterIcon class="size-4 text-muted-foreground" />
            <select
                v-model="column"
                :class="selectClass"
                aria-label="Filter column"
            >
                <option v-for="option in columns" :key="option.name">
                    {{ option.name }}
                </option>
            </select>
            <select
                v-model="operator"
                :class="selectClass"
                aria-label="Filter operator"
            >
                <option
                    v-for="option in operators"
                    :key="option.value"
                    :value="option.value"
                >
                    {{ option.label }}
                </option>
            </select>
            <Input
                v-if="needsValue"
                v-model="value"
                class="h-8 w-48"
                :placeholder="operator.includes('like') ? '%text%' : 'Value'"
                aria-label="Filter value"
            />
            <Button type="submit" size="sm" variant="secondary">
                Add filter
            </Button>
        </form>

        <div v-if="filters.length > 0" class="flex flex-wrap gap-2">
            <span
                v-for="(filter, index) in filters"
                :key="index"
                class="flex items-center gap-1 rounded-md border bg-muted/40 px-2 py-0.5 font-mono text-xs"
            >
                {{ filter.column }} {{ operatorLabel(filter.operator) }}
                <template
                    v-if="
                        filter.operator !== 'is_null' &&
                        filter.operator !== 'is_not_null'
                    "
                    >'{{ filter.value }}'</template
                >
                <button
                    type="button"
                    class="text-muted-foreground hover:text-foreground"
                    :aria-label="`Remove filter on ${filter.column}`"
                    @click="remove(index)"
                >
                    <X class="size-3" />
                </button>
            </span>
            <button
                type="button"
                class="text-xs text-muted-foreground hover:text-foreground"
                @click="emit('change', [])"
            >
                Clear all
            </button>
        </div>
    </div>
</template>
