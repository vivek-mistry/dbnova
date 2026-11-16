<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { httpErrorMessage } from '@/lib/httpError';
import { resetTarget } from '@/routes/imports';
import type { ImportJobStatus } from '@/types';

const props = defineProps<{ job: ImportJobStatus }>();
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ done: [job: ImportJobStatus] }>();

const confirmation = ref('');
const password = ref('');
const error = ref<string | null>(null);
const http = useHttp<Record<string, never>, ImportJobStatus>();

watch(open, (isOpen) => {
    if (isOpen) {
        confirmation.value = '';
        password.value = '';
        error.value = null;
    }
});

async function submit(): Promise<void> {
    error.value = null;
    http.transform(() => ({
        confirmation: confirmation.value,
        password: password.value,
    }));

    try {
        const updated = await http.post(resetTarget.url(props.job.id));
        open.value = false;
        emit('done', updated);
    } catch (exception) {
        password.value = '';
        error.value = httpErrorMessage(
            exception,
            'The database could not be reset.',
        );
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent>
            <form class="space-y-6" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>
                        Drop and recreate "{{ job.database }}"?
                    </DialogTitle>
                    <DialogDescription>
                        This deletes everything in the database, including
                        tables that were there before the import, and creates it
                        again, empty, with the same collation. It cannot be
                        undone.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="reset-confirmation">
                        Type
                        <span class="font-mono">{{ job.database }}</span> to
                        confirm
                    </Label>
                    <Input
                        id="reset-confirmation"
                        v-model="confirmation"
                        required
                        autocomplete="off"
                        spellcheck="false"
                    />
                </div>

                <div class="grid gap-2">
                    <Label for="reset-password">Your MySQL password</Label>
                    <PasswordInput
                        id="reset-password"
                        v-model="password"
                        required
                        autocomplete="current-password"
                    />
                    <InputError :message="error ?? undefined" />
                </div>

                <DialogFooter class="gap-2">
                    <Button
                        type="button"
                        variant="secondary"
                        @click="open = false"
                    >
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        variant="destructive"
                        :disabled="http.processing"
                        data-test="reset-target-button"
                    >
                        <Spinner v-if="http.processing" />
                        Drop and recreate
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
