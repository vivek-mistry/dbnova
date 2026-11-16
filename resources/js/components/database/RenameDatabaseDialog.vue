<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { Pencil } from '@lucide/vue';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
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
import { update } from '@/routes/databases';

defineProps<{
    database: string;
    blockers: string[];
    disabled?: boolean;
}>();

const open = ref(false);
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button variant="outline" :disabled="disabled">
                <Pencil />
                Rename
            </Button>
        </DialogTrigger>
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Rename "{{ database }}"</DialogTitle>
                <DialogDescription>
                    MySQL has no rename command, so DBNova creates the new
                    database and moves every table into it in a single
                    statement.
                </DialogDescription>
            </DialogHeader>

            <p
                v-if="blockers.length > 0"
                class="rounded-md border border-amber-500/40 bg-amber-500/10 p-3 text-sm"
                data-test="rename-blockers"
            >
                This database contains {{ blockers.join(', ') }}, which cannot
                be moved. Only databases with plain tables can be renamed.
            </p>

            <Form
                v-else
                v-bind="update.form(database)"
                @success="open = false"
                v-slot="{ errors, processing }"
                class="space-y-6"
            >
                <div class="grid gap-2">
                    <Label for="database-new-name">New name</Label>
                    <Input
                        id="database-new-name"
                        name="name"
                        required
                        maxlength="64"
                        autocomplete="off"
                        spellcheck="false"
                        :default-value="database"
                    />
                    <InputError :message="errors.name" />
                </div>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button variant="secondary">Cancel</Button>
                    </DialogClose>
                    <Button type="submit" :disabled="processing">
                        <Spinner v-if="processing" />
                        Rename
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
