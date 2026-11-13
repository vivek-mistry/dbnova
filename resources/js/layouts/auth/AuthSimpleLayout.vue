<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div
        class="relative isolate flex min-h-svh flex-col items-center justify-center overflow-hidden bg-background px-4 py-10"
    >
        <!-- Soft glow and a faint dot grid that fades out towards the edges. -->
        <div
            aria-hidden="true"
            class="pointer-events-none absolute inset-0 -z-10 bg-[radial-gradient(color-mix(in_oklab,var(--foreground)_12%,transparent)_1px,transparent_1px)] [mask-image:radial-gradient(ellipse_at_center,black_20%,transparent_70%)] [background-size:22px_22px]"
        />
        <div
            aria-hidden="true"
            class="pointer-events-none absolute top-[-12rem] left-1/2 -z-10 size-[36rem] -translate-x-1/2 rounded-full bg-[radial-gradient(circle,color-mix(in_oklab,var(--primary)_14%,transparent),transparent_65%)]"
        />

        <div class="w-full max-w-sm">
            <Link
                :href="home()"
                class="mx-auto mb-8 flex w-fit items-center gap-2.5"
            >
                <span
                    class="flex size-10 items-center justify-center rounded-xl bg-primary text-primary-foreground shadow-sm ring-1 ring-foreground/10"
                >
                    <AppLogoIcon class="size-5 fill-current" />
                </span>
                <span class="text-xl font-semibold tracking-tight">DBNova</span>
            </Link>

            <div
                class="rounded-2xl border bg-card/80 p-6 shadow-xl shadow-black/5 backdrop-blur-sm sm:p-8 dark:shadow-black/40"
            >
                <div class="mb-6 space-y-1.5 text-center">
                    <h1 class="text-lg font-semibold tracking-tight">
                        {{ title }}
                    </h1>
                    <p v-if="description" class="text-sm text-muted-foreground">
                        {{ description }}
                    </p>
                </div>

                <slot />
            </div>

            <p class="mt-6 text-center text-xs text-muted-foreground">
                Self-hosted MySQL manager
            </p>
        </div>
    </div>
</template>
