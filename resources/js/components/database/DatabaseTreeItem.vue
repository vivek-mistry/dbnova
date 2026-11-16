<script setup lang="ts">
import { Link, useHttp } from '@inertiajs/vue3';
import { ChevronRight, Database, Eye, Table2 } from '@lucide/vue';
import { ref, watch } from 'vue';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    SidebarMenuAction,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { tables as treeTables } from '@/routes/database-tree';
import { show } from '@/routes/databases';
import { show as tableShow } from '@/routes/tables';
import type { DatabaseTreeNode, DatabaseTreeTable } from '@/types';

const props = defineProps<{
    database: DatabaseTreeNode;
}>();

const { isCurrentUrl, isCurrentOrParentUrl } = useCurrentUrl();

const open = ref(false);
const tables = ref<DatabaseTreeTable[] | null>(null);
const http = useHttp<Record<string, never>, DatabaseTreeTable[]>();

watch(open, async (isOpen) => {
    if (isOpen && tables.value === null) {
        tables.value = await http.get(treeTables.url(props.database.name));
    }
});
</script>

<template>
    <Collapsible v-model:open="open" as-child>
        <SidebarMenuItem>
            <SidebarMenuButton
                as-child
                size="sm"
                :is-active="isCurrentUrl(show(database.name))"
                :tooltip="database.name"
            >
                <Link :href="show(database.name)">
                    <Database
                        :class="{ 'text-muted-foreground': database.isSystem }"
                    />
                    <span
                        class="truncate"
                        :class="{ 'text-muted-foreground': database.isSystem }"
                        >{{ database.name }}</span
                    >
                </Link>
            </SidebarMenuButton>
            <CollapsibleTrigger as-child>
                <SidebarMenuAction
                    class="data-[state=open]:rotate-90"
                    :aria-label="`Show tables in ${database.name}`"
                >
                    <ChevronRight />
                </SidebarMenuAction>
            </CollapsibleTrigger>
            <CollapsibleContent>
                <SidebarMenuSub>
                    <SidebarMenuSubItem
                        v-if="http.processing"
                        class="px-2 py-1 text-xs text-muted-foreground"
                    >
                        Loading…
                    </SidebarMenuSubItem>
                    <SidebarMenuSubItem
                        v-else-if="tables?.length === 0"
                        class="px-2 py-1 text-xs text-muted-foreground"
                    >
                        No tables
                    </SidebarMenuSubItem>
                    <SidebarMenuSubItem
                        v-for="table in tables ?? []"
                        :key="table.name"
                    >
                        <SidebarMenuSubButton
                            as-child
                            size="sm"
                            :is-active="
                                isCurrentOrParentUrl(
                                    tableShow({
                                        database: database.name,
                                        table: table.name,
                                    }),
                                )
                            "
                        >
                            <Link
                                :href="
                                    tableShow({
                                        database: database.name,
                                        table: table.name,
                                    })
                                "
                            >
                                <Eye v-if="table.type === 'view'" />
                                <Table2 v-else />
                                <span class="truncate">{{ table.name }}</span>
                            </Link>
                        </SidebarMenuSubButton>
                    </SidebarMenuSubItem>
                </SidebarMenuSub>
            </CollapsibleContent>
        </SidebarMenuItem>
    </Collapsible>
</template>
