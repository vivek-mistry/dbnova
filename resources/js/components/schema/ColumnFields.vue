<script setup lang="ts">
import { computed } from 'vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    INTEGER_TYPES,
    LENGTH_TYPES,
    NUMERIC_TYPES,
    TEXT_TYPES,
    TIMESTAMP_TYPES,
} from '@/lib/columnForm';
import type { CollationInfo, ColumnForm } from '@/types';

const props = defineProps<{
    columnTypes: string[];
    collations?: CollationInfo[];
    idPrefix: string;
}>();

const column = defineModel<ColumnForm>({ required: true });

const type = computed(() => column.value.type);
const isList = computed(() => type.value === 'enum' || type.value === 'set');
const lengthHint = computed(
    () =>
        ({
            varchar: 'e.g. 255',
            char: 'e.g. 36',
            decimal: 'e.g. 10,2',
            float: 'e.g. 8,2',
            double: 'e.g. 16,4',
            datetime: '0–6 (fractional seconds)',
            timestamp: '0–6 (fractional seconds)',
            time: '0–6 (fractional seconds)',
            bit: '1–64',
        })[type.value] ?? '',
);

const inputClass =
    'h-8 rounded-md border border-input bg-transparent px-2 text-sm shadow-xs dark:bg-input/30';
const id = (field: string) => `${props.idPrefix}-${field}`;
</script>

<template>
    <div class="grid gap-3 sm:grid-cols-2">
        <div class="grid gap-1.5">
            <Label :for="id('name')">Name</Label>
            <Input
                :id="id('name')"
                v-model="column.name"
                class="h-8 font-mono"
                maxlength="64"
                required
                autocomplete="off"
                spellcheck="false"
            />
        </div>
        <div class="grid grid-cols-[1fr_7rem] gap-2">
            <div class="grid gap-1.5">
                <Label :for="id('type')">Type</Label>
                <select
                    :id="id('type')"
                    v-model="column.type"
                    :class="inputClass"
                >
                    <option v-for="option in columnTypes" :key="option">
                        {{ option }}
                    </option>
                </select>
            </div>
            <div v-if="LENGTH_TYPES.includes(type)" class="grid gap-1.5">
                <Label :for="id('length')">Length</Label>
                <Input
                    :id="id('length')"
                    v-model="column.length"
                    class="h-8 font-mono"
                    :placeholder="lengthHint"
                    :title="lengthHint"
                />
            </div>
        </div>

        <div v-if="isList" class="grid gap-1.5 sm:col-span-2">
            <Label :for="id('values')">Allowed values (one per line)</Label>
            <textarea
                :id="id('values')"
                v-model="column.valuesText"
                rows="3"
                class="rounded-md border border-input bg-transparent px-2 py-1 font-mono text-sm shadow-xs dark:bg-input/30"
            />
        </div>

        <div class="grid gap-1.5">
            <Label :for="id('default-kind')">Default</Label>
            <div class="flex gap-2">
                <select
                    :id="id('default-kind')"
                    v-model="column.defaultKind"
                    :class="inputClass"
                >
                    <option value="none">None</option>
                    <option v-if="column.nullable" value="null">NULL</option>
                    <option value="value">Value</option>
                    <option
                        v-if="TIMESTAMP_TYPES.includes(type)"
                        value="current_timestamp"
                    >
                        CURRENT_TIMESTAMP
                    </option>
                </select>
                <Input
                    v-if="column.defaultKind === 'value'"
                    v-model="column.defaultValue"
                    class="h-8 flex-1 font-mono"
                    aria-label="Default value"
                />
            </div>
        </div>

        <div v-if="TEXT_TYPES.includes(type)" class="grid gap-1.5">
            <Label :for="id('collation')">Collation</Label>
            <select
                :id="id('collation')"
                v-model="column.collation"
                :class="inputClass"
            >
                <option value="">Table default</option>
                <option
                    v-for="collation in collations ?? []"
                    :key="collation.name"
                    :value="collation.name"
                >
                    {{ collation.name }}
                </option>
            </select>
        </div>

        <div class="flex flex-wrap gap-x-4 gap-y-2 text-sm sm:col-span-2">
            <label class="flex items-center gap-1.5">
                <input v-model="column.nullable" type="checkbox" />
                Nullable
            </label>
            <label
                v-if="NUMERIC_TYPES.includes(type)"
                class="flex items-center gap-1.5"
            >
                <input v-model="column.unsigned" type="checkbox" />
                Unsigned
            </label>
            <label
                v-if="INTEGER_TYPES.includes(type)"
                class="flex items-center gap-1.5"
            >
                <input v-model="column.autoIncrement" type="checkbox" />
                Auto increment
            </label>
            <label
                v-if="TIMESTAMP_TYPES.includes(type)"
                class="flex items-center gap-1.5"
            >
                <input
                    v-model="column.onUpdateCurrentTimestamp"
                    type="checkbox"
                />
                ON UPDATE CURRENT_TIMESTAMP
            </label>
        </div>

        <div class="grid gap-1.5 sm:col-span-2">
            <Label :for="id('comment')">Comment</Label>
            <Input
                :id="id('comment')"
                v-model="column.comment"
                class="h-8"
                maxlength="1024"
            />
        </div>
    </div>
</template>
