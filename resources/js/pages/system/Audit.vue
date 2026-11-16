<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, CircleCheck, CircleX } from '@lucide/vue';
import { reactive, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatNumber } from '@/lib/format';
import { index } from '@/routes/audit';
import type { AuditEntry, Paginated } from '@/types';

type Filters = {
    action: string | null;
    user: string | null;
    database: string | null;
    result: 'succeeded' | 'failed' | null;
};

const props = defineProps<{
    entries: Paginated<AuditEntry>;
    filters: Filters;
    actions: string[];
    users: string[];
    seesAll: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Audit log', href: index() }],
    },
});

/** Select items cannot have an empty value, so "any" stands for no filter. */
const ANY = 'any';

const form = reactive({
    action: props.filters.action ?? ANY,
    user: props.filters.user ?? ANY,
    database: props.filters.database ?? '',
    result: props.filters.result ?? ANY,
});

function apply(): void {
    const query: Record<string, string> = {};

    if (form.action !== ANY) {
        query.action = form.action;
    }

    if (form.user !== ANY) {
        query.user = form.user;
    }

    if (form.database.trim() !== '') {
        query.database = form.database.trim();
    }

    if (form.result !== ANY) {
        query.result = form.result;
    }

    router.get(
        index.url({ query }),
        {},
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
}

watch(() => [form.action, form.user, form.result], apply);

let databaseTimer: number | undefined;

watch(
    () => form.database,
    () => {
        window.clearTimeout(databaseTimer);
        databaseTimer = window.setTimeout(apply, 400);
    },
);

function reset(): void {
    form.action = ANY;
    form.user = ANY;
    form.database = '';
    form.result = ANY;
}

function when(iso: string): string {
    return new Date(iso).toLocaleString();
}

/** A short, readable summary of the details JSON (never row values; those are not stored). */
function summary(details: Record<string, unknown> | null): string {
    if (details === null) {
        return '';
    }

    return Object.entries(details)
        .map(([key, value]) => {
            const text = Array.isArray(value)
                ? value.join(', ')
                : typeof value === 'object' && value !== null
                  ? JSON.stringify(value)
                  : String(value);

            return `${key}: ${text}`;
        })
        .join(' · ');
}
</script>

<template>
    <Head title="Audit log" />

    <div class="flex flex-1 flex-col gap-4 p-4 lg:p-6">
        <Heading
            title="Audit log"
            :description="
                seesAll
                    ? 'Every change made through DBNova, by every account. Row values are never recorded.'
                    : 'Changes you made through DBNova. Accounts with the CREATE USER privilege see everyone\'s. Row values are never recorded.'
            "
        />

        <div class="flex flex-wrap items-center gap-3">
            <Select v-model="form.action">
                <SelectTrigger class="h-9 w-52" data-test="audit-action">
                    <SelectValue placeholder="Action" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem :value="ANY">All actions</SelectItem>
                    <SelectItem
                        v-for="action in actions"
                        :key="action"
                        :value="action"
                    >
                        {{ action }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <Select v-if="seesAll" v-model="form.user">
                <SelectTrigger class="h-9 w-44" data-test="audit-user">
                    <SelectValue placeholder="User" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem :value="ANY">All users</SelectItem>
                    <SelectItem v-for="user in users" :key="user" :value="user">
                        {{ user }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <Input
                v-model="form.database"
                placeholder="Database"
                class="h-9 w-44"
                data-test="audit-database"
            />
            <Select v-model="form.result">
                <SelectTrigger class="h-9 w-36" data-test="audit-result">
                    <SelectValue placeholder="Result" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem :value="ANY">Any result</SelectItem>
                    <SelectItem value="succeeded">Succeeded</SelectItem>
                    <SelectItem value="failed">Failed</SelectItem>
                </SelectContent>
            </Select>
            <Button variant="ghost" size="sm" @click="reset">Reset</Button>
            <span class="ml-auto text-xs text-muted-foreground tabular-nums">
                {{ formatNumber(entries.total) }}
                {{ entries.total === 1 ? 'entry' : 'entries' }}
            </span>
        </div>

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-sm" data-test="audit-table">
                <thead
                    class="bg-muted/50 text-left text-xs text-muted-foreground"
                >
                    <tr>
                        <th class="px-3 py-2 font-medium">When</th>
                        <th class="px-3 py-2 font-medium">User</th>
                        <th class="px-3 py-2 font-medium">Action</th>
                        <th class="px-3 py-2 font-medium">Target</th>
                        <th class="px-3 py-2 font-medium">Result</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr
                        v-for="entry in entries.data"
                        :key="entry.id"
                        class="align-top"
                        :data-test="`audit-${entry.id}`"
                    >
                        <td
                            class="px-3 py-2 whitespace-nowrap text-muted-foreground tabular-nums"
                        >
                            {{ when(entry.createdAt) }}
                        </td>
                        <td class="px-3 py-2">
                            <div>{{ entry.user ?? '—' }}</div>
                            <div
                                v-if="entry.ipAddress"
                                class="text-xs text-muted-foreground"
                            >
                                {{ entry.ipAddress }}
                            </div>
                        </td>
                        <td class="px-3 py-2">
                            <code class="text-xs">{{ entry.action }}</code>
                        </td>
                        <td class="max-w-md px-3 py-2">
                            <code v-if="entry.database" class="text-xs">{{
                                entry.table
                                    ? `${entry.database}.${entry.table}`
                                    : entry.database
                            }}</code>
                            <span v-else class="text-muted-foreground">—</span>
                            <div
                                v-if="entry.details"
                                class="mt-0.5 line-clamp-2 text-xs break-all text-muted-foreground"
                                :title="summary(entry.details)"
                            >
                                {{ summary(entry.details) }}
                            </div>
                        </td>
                        <td class="max-w-sm px-3 py-2">
                            <span
                                v-if="entry.succeeded"
                                class="flex items-center gap-1.5"
                            >
                                <CircleCheck
                                    class="size-4 text-emerald-600 dark:text-emerald-400"
                                />
                                Succeeded
                            </span>
                            <template v-else>
                                <span class="flex items-center gap-1.5">
                                    <CircleX class="size-4 text-destructive" />
                                    Failed
                                </span>
                                <div
                                    v-if="entry.error"
                                    class="mt-0.5 line-clamp-3 text-xs break-words text-muted-foreground"
                                    :title="entry.error"
                                >
                                    {{ entry.error }}
                                </div>
                            </template>
                        </td>
                    </tr>
                    <tr v-if="entries.data.length === 0">
                        <td
                            colspan="5"
                            class="px-3 py-8 text-center text-muted-foreground"
                        >
                            No entries match.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-if="entries.last_page > 1"
            class="flex items-center justify-end gap-2 text-sm"
        >
            <span class="text-muted-foreground tabular-nums">
                {{ formatNumber(entries.from) }}–{{
                    formatNumber(entries.to)
                }}
                of {{ formatNumber(entries.total) }}
            </span>
            <Button
                variant="outline"
                size="sm"
                :disabled="!entries.prev_page_url"
                as-child
            >
                <Link
                    v-if="entries.prev_page_url"
                    :href="entries.prev_page_url"
                    preserve-scroll
                    ><ChevronLeft /> Newer</Link
                >
                <span v-else><ChevronLeft /> Newer</span>
            </Button>
            <Button
                variant="outline"
                size="sm"
                :disabled="!entries.next_page_url"
                as-child
            >
                <Link
                    v-if="entries.next_page_url"
                    :href="entries.next_page_url"
                    preserve-scroll
                    >Older <ChevronRight
                /></Link>
                <span v-else>Older <ChevronRight /></span>
            </Button>
        </div>
    </div>
</template>
