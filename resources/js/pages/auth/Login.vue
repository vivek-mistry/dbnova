<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ArrowRight, Lock, ShieldCheck, User } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/login';

defineOptions({
    layout: {
        title: 'Welcome back',
        description: 'Sign in with your MySQL username and password',
    },
});

defineProps<{
    server: string;
    status?: string;
}>();
</script>

<template>
    <Head title="Log in" />

    <div
        v-if="status"
        class="mb-4 text-center text-sm font-medium text-green-600"
    >
        {{ status }}
    </div>

    <Form
        v-bind="store.form()"
        :reset-on-success="['password']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-5"
    >
        <div
            class="flex items-center justify-center gap-2 rounded-full border bg-muted/50 px-3 py-1.5 text-xs text-muted-foreground"
            data-test="mysql-server"
        >
            <span class="size-1.5 rounded-full bg-emerald-500" />
            <span>MySQL server</span>
            <span class="font-mono text-foreground">{{ server }}</span>
        </div>

        <div class="grid gap-2">
            <Label for="username">Username</Label>
            <div class="relative">
                <User
                    class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    id="username"
                    type="text"
                    name="username"
                    required
                    autofocus
                    :tabindex="1"
                    autocomplete="username"
                    autocapitalize="none"
                    spellcheck="false"
                    placeholder="root"
                    class="h-10 pl-9"
                />
            </div>
            <InputError :message="errors.username" />
        </div>

        <div class="grid gap-2">
            <Label for="password">Password</Label>
            <div class="relative">
                <Lock
                    class="pointer-events-none absolute top-1/2 left-3 z-10 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <PasswordInput
                    id="password"
                    name="password"
                    :tabindex="2"
                    autocomplete="current-password"
                    placeholder="••••••••"
                    class="h-10 pl-9"
                />
            </div>
            <InputError :message="errors.password" />
        </div>

        <Button
            type="submit"
            class="mt-1 h-10 w-full"
            :tabindex="3"
            :disabled="processing"
            data-test="login-button"
        >
            <Spinner v-if="processing" />
            Log in
            <ArrowRight v-if="!processing" />
        </Button>

        <p
            class="flex items-center justify-center gap-1.5 text-xs text-muted-foreground"
        >
            <ShieldCheck class="size-3.5 shrink-0" />
            Your password stays encrypted in your session.
        </p>
    </Form>
</template>
