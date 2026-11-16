<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { TriangleAlert } from '@lucide/vue';
import type { Component } from 'vue';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import type { ButtonVariants } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useSchemaChange } from '@/composables/useSchemaChange';
import type { SchemaChangePayload } from '@/types';

const props = withDefaults(
    defineProps<{
        database: string;
        /** The table the change applies to (the new name for "create table"). */
        table: string;
        title: string;
        description?: string;
        buildChange: () => SchemaChangePayload | null;
        destructive?: boolean;
        triggerLabel: string;
        triggerVariant?: ButtonVariants['variant'];
        triggerSize?: ButtonVariants['size'];
        triggerIcon?: Component;
        disabled?: boolean;
        wide?: boolean;
    }>(),
    {
        description: undefined,
        destructive: false,
        triggerVariant: 'outline',
        triggerSize: 'sm',
        triggerIcon: undefined,
        disabled: false,
        wide: false,
    },
);

const emit = defineEmits<{
    opened: [];
}>();

const open = ref(false);
const step = ref<'form' | 'preview'>('form');
const change = ref<SchemaChangePayload | null>(null);
const confirmation = ref('');
const password = ref('');

const schema = useSchemaChange(props.database);

function onOpenChange(isOpen: boolean): void {
    if (isOpen) {
        step.value = 'form';
        confirmation.value = '';
        password.value = '';
        schema.reset();
        emit('opened');
    }
}

async function showPreview(): Promise<void> {
    change.value = props.buildChange();

    if (change.value && (await schema.preview(props.table, change.value))) {
        step.value = 'preview';
    }
}

async function run(allowLocking = false): Promise<void> {
    if (!change.value) {
        return;
    }

    const result = await schema.apply(props.table, change.value, {
        allowLocking,
        confirmation: confirmation.value,
        password: password.value,
    });

    if (result) {
        toast.success(result.message);
        open.value = false;

        if (result.redirect) {
            router.visit(result.redirect);
        } else {
            router.reload();
        }
    }
}
</script>

<template>
    <Dialog v-model:open="open" @update:open="onOpenChange">
        <DialogTrigger as-child>
            <Button
                :variant="triggerVariant"
                :size="triggerSize"
                :disabled="disabled"
                :title="triggerSize === 'icon' ? triggerLabel : undefined"
                :aria-label="triggerLabel"
            >
                <component :is="triggerIcon" v-if="triggerIcon" />
                <template v-if="triggerSize !== 'icon'">{{
                    triggerLabel
                }}</template>
            </Button>
        </DialogTrigger>
        <DialogContent :class="wide ? 'sm:max-w-3xl' : 'sm:max-w-xl'">
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription v-if="description">{{
                    description
                }}</DialogDescription>
            </DialogHeader>

            <form
                v-if="step === 'form'"
                class="space-y-4"
                @submit.prevent="showPreview"
            >
                <div class="max-h-[60vh] space-y-4 overflow-y-auto pr-1">
                    <slot />
                </div>
                <p v-if="schema.error.value" class="text-sm text-destructive">
                    {{ schema.error.value }}
                </p>
                <DialogFooter class="gap-2">
                    <Button
                        type="button"
                        variant="secondary"
                        @click="open = false"
                        >Cancel</Button
                    >
                    <Button type="submit" :disabled="schema.previewing()">
                        <Spinner v-if="schema.previewing()" />
                        Preview SQL
                    </Button>
                </DialogFooter>
            </form>

            <div v-else class="space-y-4">
                <pre
                    class="max-h-72 overflow-auto rounded-md border bg-muted p-3 font-mono text-xs whitespace-pre-wrap"
                    data-test="ddl-preview"
                    >{{
                        schema.plan.value?.statements
                            .map((statement) => `${statement};`)
                            .join('\n\n')
                    }}</pre>

                <ul
                    v-if="schema.plan.value?.warnings.length"
                    class="space-y-1 text-sm"
                >
                    <li
                        v-for="warning in schema.plan.value.warnings"
                        :key="warning"
                        class="flex gap-2 rounded-md border border-amber-500/40 bg-amber-500/10 p-2"
                    >
                        <TriangleAlert
                            class="mt-0.5 size-4 shrink-0 text-amber-600"
                        />
                        {{ warning }}
                    </li>
                </ul>

                <div v-if="destructive" class="space-y-3">
                    <div class="grid gap-2">
                        <Label for="schema-confirmation">
                            Type <span class="font-mono">{{ table }}</span> to
                            confirm
                        </Label>
                        <Input
                            id="schema-confirmation"
                            v-model="confirmation"
                            autocomplete="off"
                            spellcheck="false"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="schema-password">Your MySQL password</Label>
                        <PasswordInput
                            id="schema-password"
                            v-model="password"
                            autocomplete="current-password"
                        />
                    </div>
                </div>

                <p
                    v-if="schema.error.value"
                    class="rounded-md border p-2 text-sm"
                    :class="
                        schema.needsLockingConfirmation.value
                            ? 'border-amber-500/40 bg-amber-500/10'
                            : 'border-destructive/40 bg-destructive/10'
                    "
                    data-test="schema-error"
                >
                    {{ schema.error.value }}
                </p>

                <DialogFooter class="gap-2">
                    <Button
                        type="button"
                        variant="secondary"
                        @click="step = 'form'"
                        >Back</Button
                    >
                    <Button
                        v-if="schema.needsLockingConfirmation.value"
                        variant="destructive"
                        :disabled="schema.applying()"
                        @click="run(true)"
                    >
                        <Spinner v-if="schema.applying()" />
                        Run anyway (locks the table)
                    </Button>
                    <Button
                        v-else
                        :variant="destructive ? 'destructive' : 'default'"
                        :disabled="schema.applying()"
                        data-test="run-ddl"
                        @click="run()"
                    >
                        <Spinner v-if="schema.applying()" />
                        Run
                    </Button>
                </DialogFooter>
            </div>
        </DialogContent>
    </Dialog>
</template>
