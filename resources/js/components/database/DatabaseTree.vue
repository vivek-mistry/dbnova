<script setup lang="ts">
import { router, useHttp } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import DatabaseTreeItem from '@/components/database/DatabaseTreeItem.vue';
import {
    SidebarGroup,
    SidebarGroupContent,
    SidebarGroupLabel,
    SidebarInput,
    SidebarMenu,
    SidebarMenuSkeleton,
} from '@/components/ui/sidebar';
import { index as treeIndex } from '@/routes/database-tree';
import type { DatabaseTreeNode } from '@/types';

const databases = ref<DatabaseTreeNode[] | null>(null);
const filter = ref('');
const http = useHttp<Record<string, never>, DatabaseTreeNode[]>();

const filteredDatabases = computed(() => {
    const needle = filter.value.trim().toLowerCase();

    return (databases.value ?? []).filter((database) =>
        database.name.toLowerCase().includes(needle),
    );
});

async function loadDatabases(): Promise<void> {
    databases.value = await http.get(treeIndex.url());
}

let stopListening: (() => void) | undefined;

onMounted(() => {
    loadDatabases();

    // Databases are created, renamed and dropped from the databases pages.
    stopListening = router.on('success', (event) => {
        if (event.detail.page.component.startsWith('databases/')) {
            loadDatabases();
        }
    });
});

onUnmounted(() => stopListening?.());
</script>

<template>
    <SidebarGroup class="group-data-[collapsible=icon]:hidden">
        <SidebarGroupLabel>Databases</SidebarGroupLabel>
        <SidebarGroupContent class="space-y-2">
            <SidebarInput
                v-model="filter"
                placeholder="Filter databases"
                aria-label="Filter databases"
            />
            <SidebarMenu>
                <template v-if="databases === null">
                    <SidebarMenuSkeleton v-for="n in 5" :key="n" show-icon />
                </template>
                <DatabaseTreeItem
                    v-for="database in filteredDatabases"
                    :key="database.name"
                    :database="database"
                />
            </SidebarMenu>
        </SidebarGroupContent>
    </SidebarGroup>
</template>
