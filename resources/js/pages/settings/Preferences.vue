<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatNumber } from '@/lib/format';
import { edit, update } from '@/routes/preferences';
import type { PreferenceField } from '@/types';

type Key = 'page_size' | 'query_row_cap' | 'query_timeout_ms';

const props = defineProps<{
    fields: Record<Key, PreferenceField>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Preferences', href: edit() }],
    },
});

const meta: Record<
    Key,
    { label: string; help: string; unit: string; scale: number }
> = {
    page_size: {
        label: 'Rows per page',
        help: 'How many rows the table browser loads at a time.',
        unit: 'rows',
        scale: 1,
    },
    query_row_cap: {
        label: 'SQL editor row limit',
        help: 'The SQL editor shows at most this many rows of a result. Export the full result as CSV for more.',
        unit: 'rows',
        scale: 1,
    },
    query_timeout_ms: {
        label: 'Query timeout',
        help: 'MySQL stops a SELECT that runs longer than this (max_execution_time). It does not apply to exports or imports.',
        unit: 'seconds',
        scale: 1000,
    },
};

const keys = Object.keys(meta) as Key[];

function shown(key: Key, value: number): number {
    return Math.round(value / meta[key].scale);
}
</script>

<template>
    <Head title="Preferences" />

    <h1 class="sr-only">Preferences</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Preferences"
            description="Limits for browsing tables and running queries. They apply to your account only; the server administrator sets the maximums."
        />

        <Form
            v-slot="{ errors, processing }"
            v-bind="update.form()"
            :transform="
                (data) =>
                    Object.fromEntries(
                        keys.map((key) => [
                            key,
                            data[key] === '' || data[key] === undefined
                                ? null
                                : Number(data[key]) * meta[key].scale,
                        ]),
                    )
            "
            :options="{ preserveScroll: true }"
            class="space-y-6"
        >
            <div v-for="key in keys" :key="key" class="grid gap-2">
                <Label :for="key">{{ meta[key].label }}</Label>
                <div class="flex items-center gap-2">
                    <Input
                        :id="key"
                        :name="key"
                        type="number"
                        inputmode="numeric"
                        class="w-40"
                        :min="shown(key, fields[key].min)"
                        :max="shown(key, fields[key].max)"
                        :default-value="
                            fields[key].isDefault
                                ? ''
                                : String(shown(key, fields[key].value))
                        "
                        :placeholder="`${formatNumber(shown(key, fields[key].default))} (default)`"
                        :data-test="`preference-${key}`"
                    />
                    <span class="text-sm text-muted-foreground">{{
                        meta[key].unit
                    }}</span>
                </div>
                <p class="text-xs text-muted-foreground">
                    {{ meta[key].help }} Allowed:
                    {{ formatNumber(shown(key, fields[key].min)) }}–{{
                        formatNumber(shown(key, fields[key].max))
                    }}. Leave empty for the default.
                </p>
                <InputError :message="errors[key]" />
            </div>

            <Button :disabled="processing" data-test="preferences-save">
                Save
            </Button>
        </Form>
    </div>
</template>
