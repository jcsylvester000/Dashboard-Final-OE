<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import AccessLinkController from '@/actions/App/Http/Controllers/Auth/AccessLinkController';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime } from '@/lib/format';
import { login } from '@/routes';

const props = defineProps<{
    valid: boolean;
    token?: string;
    purpose?: 'setup' | 'reset';
    name?: string;
    email?: string;
    expiresAt?: string;
    passwordRules?: string;
}>();

defineOptions({
    layout: {
        title: 'Set your password',
        description: 'Choose a password to finish signing in to OverEasy.',
    },
});

const heading = computed(() =>
    props.purpose === 'setup'
        ? `Welcome, ${props.name ?? ''}`
        : 'Reset your password',
);
</script>

<template>
    <Head title="Set your password" />

    <div v-if="!valid" class="space-y-4 text-center">
        <p class="font-medium">This link is invalid or has expired.</p>
        <p class="text-sm text-muted-foreground">
            Links work once and expire after a set time. Ask an admin to issue
            a new one.
        </p>
        <Link :href="login()" class="text-sm underline underline-offset-4">
            Back to log in
        </Link>
    </div>

    <Form
        v-else
        v-bind="AccessLinkController.store.form({ token: token! })"
        :reset-on-error="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <div class="space-y-1 text-center">
            <p class="font-medium">{{ heading }}</p>
            <p class="text-xs text-muted-foreground">
                Link expires {{ formatDateTime(expiresAt) }}
            </p>
        </div>

        <div class="grid gap-2">
            <Label for="email">Email</Label>
            <Input
                id="email"
                type="email"
                :default-value="email"
                autocomplete="username"
                readonly
            />
        </div>

        <div class="grid gap-2">
            <Label for="password">New password</Label>
            <PasswordInput
                id="password"
                name="password"
                required
                autofocus
                autocomplete="new-password"
                :passwordrules="passwordRules"
            />
            <InputError :message="errors.password" />
        </div>

        <div class="grid gap-2">
            <Label for="password_confirmation">Confirm password</Label>
            <PasswordInput
                id="password_confirmation"
                name="password_confirmation"
                required
                autocomplete="new-password"
                :passwordrules="passwordRules"
            />
        </div>

        <Button type="submit" class="w-full" :disabled="processing">
            <Spinner v-if="processing" />
            Save password
        </Button>
    </Form>
</template>
