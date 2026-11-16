<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Search } from '@lucide/vue';
import { computed, ref } from 'vue';
import CreateDatabaseDialog from '@/components/database/CreateDatabaseDialog.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { formatBytes, formatNumber } from '@/lib/format';
import { index, show } from '@/routes/databases';
import type { CollationInfo, DatabaseInfo } from '@/types';

const props = defineProps<{
    databases: DatabaseInfo[];
    collations?: CollationInfo[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Databases', href: index() }],
    },
});

const page = usePage();
const filter = ref('');

const filteredDatabases = computed(() => {
    const needle = filter.value.trim().toLowerCase();

    return needle === ''
        ? props.databases
        : props.databases.filter((database) =>
              database.name.toLowerCase().includes(needle),
          );
});

const totalSizeBytes = computed(() =>
    props.databases.reduce((total, database) => total + database.sizeBytes, 0),
);
</script>

<template>
    <Head title="Databases" />

    <div class="flex flex-col gap-4 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Databases"
                :description="`${databases.length} databases · ${formatBytes(totalSizeBytes)}`"
            />
            <CreateDatabaseDialog
                :collations="collations"
                :disabled="page.props.dbnova.readOnly"
            />
        </div>

        <div class="relative max-w-sm">
            <Search
                class="absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
            />
            <Input
                v-model="filter"
                placeholder="Filter databases"
                class="pl-8"
                aria-label="Filter databases"
            />
        </div>

        <Card class="overflow-hidden py-0">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b bg-muted/40 text-left">
                        <tr>
                            <th class="px-4 py-2 font-medium">Name</th>
                            <th class="px-4 py-2 text-right font-medium">
                                Tables
                            </th>
                            <th class="px-4 py-2 text-right font-medium">
                                Size
                            </th>
                            <th class="px-4 py-2 font-medium">Collation</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="database in filteredDatabases"
                            :key="database.name"
                            class="hover:bg-muted/30"
                        >
                            <td class="px-4 py-2">
                                <Link
                                    :href="show(database.name)"
                                    class="font-medium hover:underline"
                                >
                                    {{ database.name }}
                                </Link>
                                <Badge
                                    v-if="database.isSystem"
                                    variant="outline"
                                    class="ml-2"
                                >
                                    system
                                </Badge>
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ formatNumber(database.tableCount) }}
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ formatBytes(database.sizeBytes) }}
                            </td>
                            <td
                                class="px-4 py-2 font-mono text-xs text-muted-foreground"
                            >
                                {{ database.collation }}
                            </td>
                        </tr>
                        <tr v-if="filteredDatabases.length === 0">
                            <td
                                colspan="4"
                                class="px-4 py-8 text-center text-muted-foreground"
                            >
                                No databases match "{{ filter }}".
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>
    </div>
</template>
