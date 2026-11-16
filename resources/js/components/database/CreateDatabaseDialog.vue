<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { computed, ref } from 'vue';
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
import { store } from '@/routes/databases';
import type { CollationInfo } from '@/types';

const props = defineProps<{
    collations?: CollationInfo[];
    disabled?: boolean;
}>();

const open = ref(false);

const collationsByCharacterSet = computed(() => {
    const groups = new Map<string, CollationInfo[]>();

    for (const collation of props.collations ?? []) {
        groups.set(collation.characterSet, [
            ...(groups.get(collation.characterSet) ?? []),
            collation,
        ]);
    }

    return [...groups.entries()];
});
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button :disabled="disabled" data-test="create-database-button">
                <Plus />
                Create database
            </Button>
        </DialogTrigger>
        <DialogContent>
            <Form
                v-bind="store.form()"
                reset-on-success
                @success="open = false"
                v-slot="{ errors, processing }"
                class="space-y-6"
            >
                <DialogHeader>
                    <DialogTitle>Create database</DialogTitle>
                    <DialogDescription>
                        Create an empty database on the MySQL server.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="database-name">Name</Label>
                    <Input
                        id="database-name"
                        name="name"
                        required
                        maxlength="64"
                        autocomplete="off"
                        spellcheck="false"
                        placeholder="my_app"
                    />
                    <InputError :message="errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="database-collation">Collation</Label>
                    <select
                        id="database-collation"
                        name="collation"
                        class="h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs dark:bg-input/30"
                    >
                        <option value="">Server default</option>
                        <optgroup
                            v-for="[
                                characterSet,
                                collations,
                            ] in collationsByCharacterSet"
                            :key="characterSet"
                            :label="characterSet"
                        >
                            <option
                                v-for="collation in collations"
                                :key="collation.name"
                                :value="collation.name"
                            >
                                {{ collation.name
                                }}{{ collation.isDefault ? ' (default)' : '' }}
                            </option>
                        </optgroup>
                    </select>
                    <InputError :message="errors.collation" />
                </div>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button variant="secondary">Cancel</Button>
                    </DialogClose>
                    <Button type="submit" :disabled="processing">
                        <Spinner v-if="processing" />
                        Create
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
