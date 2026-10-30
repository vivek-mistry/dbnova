<script setup lang="ts">
import { Trash2 } from '@lucide/vue';
import { ref } from 'vue';
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
import { Spinner } from '@/components/ui/spinner';

defineProps<{
    count: number;
    table: string;
    deleting: boolean;
}>();

const emit = defineEmits<{
    confirm: [done: () => void];
}>();

const open = ref(false);
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button
                size="sm"
                variant="destructive"
                data-test="delete-rows-button"
            >
                <Trash2 />
                Delete {{ count }}
            </Button>
        </DialogTrigger>
        <DialogContent>
            <DialogHeader>
                <DialogTitle>
                    Delete {{ count }} row{{ count === 1 ? '' : 's' }} from "{{
                        table
                    }}"?
                </DialogTitle>
                <DialogDescription>
                    The rows are deleted permanently. This cannot be undone.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter class="gap-2">
                <DialogClose as-child>
                    <Button variant="secondary">Cancel</Button>
                </DialogClose>
                <Button
                    variant="destructive"
                    :disabled="deleting"
                    @click="emit('confirm', () => (open = false))"
                >
                    <Spinner v-if="deleting" />
                    Delete
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
