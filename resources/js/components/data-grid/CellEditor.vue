<script setup lang="ts">
import { onMounted, ref } from 'vue';

const props = defineProps<{
    value: string | null;
    nullable: boolean;
}>();

const emit = defineEmits<{
    save: [value: string | null];
    cancel: [];
}>();

const input = ref<HTMLInputElement | null>(null);
const draft = ref(props.value ?? '');
let finished = false;

onMounted(() => {
    input.value?.focus();
    input.value?.select();
});

function save(value: string | null): void {
    if (finished) {
        return;
    }

    finished = true;

    if (value === props.value) {
        emit('cancel');
    } else {
        emit('save', value);
    }
}

function cancel(): void {
    finished = true;
    emit('cancel');
}
</script>

<template>
    <div class="flex h-full w-full items-center gap-1" @click.stop>
        <input
            ref="input"
            v-model="draft"
            class="h-6 min-w-0 flex-1 rounded border border-primary bg-background px-1 font-mono text-xs outline-none"
            :placeholder="value === null ? 'NULL' : ''"
            aria-label="Cell value"
            @keydown.enter.prevent="save(draft)"
            @keydown.escape.prevent="cancel"
            @blur="save(draft)"
        />
        <button
            v-if="nullable"
            type="button"
            class="shrink-0 rounded bg-muted px-1 text-[11px] text-muted-foreground hover:text-foreground"
            title="Set to NULL"
            @mousedown.prevent="save(null)"
        >
            NULL
        </button>
    </div>
</template>
