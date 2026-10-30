<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { CircleAlert, CircleCheck, CircleX, RefreshCw } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { index } from '@/routes/system';
import type { SystemCheck, SystemCheckStatus } from '@/types';

const props = defineProps<{
    checks: SystemCheck[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'System',
                href: index(),
            },
        ],
    },
});

const statusIcons = {
    ok: CircleCheck,
    warning: CircleAlert,
    failed: CircleX,
} satisfies Record<SystemCheckStatus, unknown>;

const statusClasses: Record<SystemCheckStatus, string> = {
    ok: 'text-emerald-600 dark:text-emerald-400',
    warning: 'text-amber-600 dark:text-amber-400',
    failed: 'text-destructive',
};

const statusLabels: Record<SystemCheckStatus, string> = {
    ok: 'OK',
    warning: 'Warning',
    failed: 'Failed',
};

const failedCount = computed(
    () => props.checks.filter((check) => check.status === 'failed').length,
);

const refreshing = ref(false);

function refresh(): void {
    router.reload({
        only: ['checks'],
        onStart: () => (refreshing.value = true),
        onFinish: () => (refreshing.value = false),
    });
}
</script>

<template>
    <Head title="System" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-start justify-between gap-4">
            <Heading
                title="System health"
                description="Runtime requirements for DBNova and the MySQL server it manages."
            />
            <Button
                variant="outline"
                size="sm"
                :disabled="refreshing"
                @click="refresh"
            >
                <RefreshCw :class="{ 'animate-spin': refreshing }" />
                Re-run checks
            </Button>
        </div>

        <p
            v-if="failedCount > 0"
            class="text-sm text-destructive"
            data-test="failed-summary"
        >
            {{ failedCount }} check{{ failedCount === 1 ? '' : 's' }} failed.
            DBNova features that depend on them will not work until fixed.
        </p>

        <Card class="py-0">
            <CardContent class="divide-y p-0">
                <div
                    v-for="check in checks"
                    :key="check.name"
                    class="flex items-start gap-3 px-4 py-3"
                >
                    <component
                        :is="statusIcons[check.status]"
                        class="mt-0.5 size-5 shrink-0"
                        :class="statusClasses[check.status]"
                    />
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <span class="font-medium">{{ check.name }}</span>
                            <Badge
                                :variant="
                                    check.status === 'failed'
                                        ? 'destructive'
                                        : 'outline'
                                "
                            >
                                {{ statusLabels[check.status] }}
                            </Badge>
                        </div>
                        <p
                            class="mt-0.5 font-mono text-xs break-all text-muted-foreground"
                        >
                            {{ check.detail }}
                        </p>
                        <p v-if="check.hint" class="mt-1 text-sm">
                            {{ check.hint }}
                        </p>
                    </div>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
