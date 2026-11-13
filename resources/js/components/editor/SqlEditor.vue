<script setup lang="ts">
import { MySQL, sql } from '@codemirror/lang-sql';
import type { SQLNamespace } from '@codemirror/lang-sql';
import { basicSetup, EditorView } from 'codemirror';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps<{
    /** Tables and their columns for autocomplete. */
    schema?: Record<string, string[]>;
}>();

const emit = defineEmits<{
    run: [];
}>();

const model = defineModel<string>({ required: true });

const container = ref<HTMLElement | null>(null);
let view: EditorView | null = null;

const theme = EditorView.theme({
    '&': {
        height: '100%',
        fontSize: '13px',
        backgroundColor: 'var(--background)',
        color: 'var(--foreground)',
    },
    '.cm-scroller': { fontFamily: 'var(--font-mono, ui-monospace, monospace)' },
    '.cm-gutters': {
        backgroundColor: 'var(--muted)',
        color: 'var(--muted-foreground)',
        border: 'none',
    },
    '.cm-activeLine, .cm-activeLineGutter': {
        backgroundColor: 'color-mix(in oklab, var(--muted) 60%, transparent)',
    },
    '&.cm-focused': { outline: 'none' },
});

/**
 * Build the editor. It is rebuilt (keeping its text) when the autocomplete
 * schema changes, which avoids depending on CodeMirror's internal packages.
 */
function create(): void {
    view?.destroy();

    if (!container.value) {
        return;
    }

    view = new EditorView({
        parent: container.value,
        doc: model.value,
        extensions: [
            basicSetup,
            sql({
                dialect: MySQL,
                upperCaseKeywords: true,
                schema: (props.schema ?? {}) as SQLNamespace,
            }),
            theme,
            EditorView.lineWrapping,
            EditorView.updateListener.of((update) => {
                if (update.docChanged) {
                    model.value = update.state.doc.toString();
                }
            }),
        ],
    });
}

onMounted(create);
onBeforeUnmount(() => view?.destroy());

watch(
    () => props.schema,
    () => create(),
);

watch(model, (value) => {
    if (view && value !== view.state.doc.toString()) {
        view.dispatch({
            changes: { from: 0, to: view.state.doc.length, insert: value },
        });
    }
});

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) {
        event.preventDefault();
        event.stopPropagation();
        emit('run');
    }
}

defineExpose({
    /** The selected text, or "" when nothing is selected. */
    selection(): string {
        if (!view) {
            return '';
        }

        const { from, to } = view.state.selection.main;

        return view.state.sliceDoc(from, to);
    },
    focus(): void {
        view?.focus();
    },
});
</script>

<template>
    <div
        ref="container"
        class="h-full min-h-0 overflow-hidden rounded-md border"
        data-test="sql-editor"
        @keydown.capture="onKeydown"
    />
</template>
