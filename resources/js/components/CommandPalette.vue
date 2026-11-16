<script setup lang="ts">
import { router, useHttp } from '@inertiajs/vue3';
import type { InertiaLinkProps } from '@inertiajs/vue3';
import { CornerDownLeft, Database, Search, Table2 } from '@lucide/vue';
import { useMagicKeys, whenever } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { mainNavItems } from '@/lib/navigation';
import { toUrl } from '@/lib/utils';
import {
    index as treeIndex,
    tables as treeTables,
} from '@/routes/database-tree';
import { show as databaseShow } from '@/routes/databases';
import { show as tableShow } from '@/routes/tables';
import type { DatabaseTreeNode, DatabaseTreeTable } from '@/types';

type Item = {
    id: string;
    label: string;
    hint: string;
    href: NonNullable<InertiaLinkProps['href']>;
    kind: 'page' | 'database' | 'table';
};

const MAX_ITEMS = 50;

const open = ref(false);
const query = ref('');
const activeIndex = ref(0);
const databases = ref<DatabaseTreeNode[] | null>(null);
const tablesByDatabase = ref<Record<string, DatabaseTreeTable[]>>({});

const databasesHttp = useHttp<Record<string, never>, DatabaseTreeNode[]>();
const tablesHttp = useHttp<Record<string, never>, DatabaseTreeTable[]>();

const keys = useMagicKeys({
    passive: false,
    onEventFired(event) {
        if (
            (event.metaKey || event.ctrlKey) &&
            event.key === 'k' &&
            event.type === 'keydown'
        ) {
            event.preventDefault();
        }
    },
});

whenever(
    () => keys['Meta+K']?.value || keys['Ctrl+K']?.value,
    () => (open.value = !open.value),
);

watch(open, async (isOpen) => {
    query.value = '';
    activeIndex.value = 0;

    if (isOpen && databases.value === null) {
        databases.value = await databasesHttp.get(treeIndex.url());
    }
});

// Typing "database." lists that database's tables.
const databasePrefix = computed(() => {
    const dot = query.value.indexOf('.');

    return dot === -1 ? null : query.value.slice(0, dot);
});

watch(databasePrefix, async (name) => {
    const database = databases.value?.find((item) => item.name === name);

    if (database && !tablesByDatabase.value[database.name]) {
        const tables = await tablesHttp.get(treeTables.url(database.name));
        tablesByDatabase.value = {
            ...tablesByDatabase.value,
            [database.name]: tables,
        };
    }
});

const items = computed<Item[]>(() => {
    const needle = query.value.trim().toLowerCase();
    const prefix = databasePrefix.value;

    if (prefix !== null) {
        const tableNeedle = needle.slice(prefix.length + 1);

        return (tablesByDatabase.value[prefix] ?? [])
            .filter((table) => table.name.toLowerCase().includes(tableNeedle))
            .slice(0, MAX_ITEMS)
            .map((table) => ({
                id: `table:${prefix}.${table.name}`,
                label: table.name,
                hint: `${prefix} · ${table.type}`,
                href: tableShow({ database: prefix, table: table.name }),
                kind: 'table' as const,
            }));
    }

    const pages = mainNavItems
        .filter((item) => item.title.toLowerCase().includes(needle))
        .map((item) => ({
            id: `page:${item.title}`,
            label: item.title,
            hint: 'Page',
            href: item.href,
            kind: 'page' as const,
        }));

    const databaseItems = (databases.value ?? [])
        .filter((database) => database.name.toLowerCase().includes(needle))
        .map((database) => ({
            id: `database:${database.name}`,
            label: database.name,
            hint: 'Database · type "." to list its tables',
            href: databaseShow(database.name),
            kind: 'database' as const,
        }));

    return [...pages, ...databaseItems].slice(0, MAX_ITEMS);
});

watch(items, () => (activeIndex.value = 0));

function move(step: number): void {
    const count = items.value.length;

    if (count > 0) {
        activeIndex.value = (activeIndex.value + step + count) % count;
    }
}

function choose(item: Item | undefined): void {
    if (item) {
        open.value = false;
        router.visit(toUrl(item.href));
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="top-[20%] translate-y-0 gap-0 p-0 sm:max-w-lg">
            <DialogTitle class="sr-only">Jump to</DialogTitle>
            <div class="flex items-center gap-2 border-b px-3">
                <Search class="size-4 shrink-0 text-muted-foreground" />
                <input
                    v-model="query"
                    class="h-11 flex-1 bg-transparent text-sm outline-none"
                    placeholder="Jump to a page, database, or database.table"
                    aria-label="Jump to"
                    autofocus
                    @keydown.down.prevent="move(1)"
                    @keydown.up.prevent="move(-1)"
                    @keydown.enter.prevent="choose(items[activeIndex])"
                />
            </div>
            <ul class="max-h-80 overflow-y-auto p-1" role="listbox">
                <li
                    v-for="(item, index) in items"
                    :key="item.id"
                    role="option"
                    :aria-selected="index === activeIndex"
                    class="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm"
                    :class="{ 'bg-accent': index === activeIndex }"
                    @mouseenter="activeIndex = index"
                    @click="choose(item)"
                >
                    <Database
                        v-if="item.kind === 'database'"
                        class="size-4 text-muted-foreground"
                    />
                    <Table2
                        v-else-if="item.kind === 'table'"
                        class="size-4 text-muted-foreground"
                    />
                    <CornerDownLeft
                        v-else
                        class="size-4 text-muted-foreground"
                    />
                    <span class="truncate">{{ item.label }}</span>
                    <span
                        class="ml-auto truncate text-xs text-muted-foreground"
                    >
                        {{ item.hint }}
                    </span>
                </li>
                <li
                    v-if="items.length === 0"
                    class="px-2 py-6 text-center text-sm text-muted-foreground"
                >
                    {{
                        databasesHttp.processing || tablesHttp.processing
                            ? 'Loading…'
                            : 'No matches'
                    }}
                </li>
            </ul>
            <p class="border-t px-3 py-1.5 text-[11px] text-muted-foreground">
                ↑↓ to move · Enter to open · Esc to close · Cmd/Ctrl+K to toggle
            </p>
        </DialogContent>
    </Dialog>
</template>
