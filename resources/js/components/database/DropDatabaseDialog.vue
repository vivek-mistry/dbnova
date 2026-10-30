<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { Trash2 } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
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
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { destroy } from '@/routes/databases';

defineProps<{
    database: string;
    tableCount: number;
    disabled?: boolean;
}>();
</script>

<template>
    <Dialog>
        <DialogTrigger as-child>
            <Button variant="destructive" :disabled="disabled">
                <Trash2 />
                Drop
            </Button>
        </DialogTrigger>
        <DialogContent>
            <Form
                v-bind="destroy.form(database)"
                :reset-on-error="['password']"
                v-slot="{ errors, processing }"
                class="space-y-6"
            >
                <DialogHeader>
                    <DialogTitle>Drop "{{ database }}"?</DialogTitle>
                    <DialogDescription>
                        This permanently deletes the database and its
                        {{ tableCount }} table{{ tableCount === 1 ? '' : 's' }}.
                        It cannot be undone.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="drop-confirmation">
                        Type <span class="font-mono">{{ database }}</span> to
                        confirm
                    </Label>
                    <Input
                        id="drop-confirmation"
                        name="confirmation"
                        required
                        autocomplete="off"
                        spellcheck="false"
                    />
                    <InputError :message="errors.confirmation" />
                </div>

                <div class="grid gap-2">
                    <Label for="drop-password">Your MySQL password</Label>
                    <PasswordInput
                        id="drop-password"
                        name="password"
                        required
                        autocomplete="current-password"
                    />
                    <InputError :message="errors.password" />
                </div>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button variant="secondary">Cancel</Button>
                    </DialogClose>
                    <Button
                        type="submit"
                        variant="destructive"
                        :disabled="processing"
                        data-test="drop-database-button"
                    >
                        <Spinner v-if="processing" />
                        Drop database
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
