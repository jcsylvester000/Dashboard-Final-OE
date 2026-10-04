<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import ForcePasswordChangeController from '@/actions/App/Http/Controllers/Auth/ForcePasswordChangeController';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';

defineProps<{
    passwordRules: string;
}>();

defineOptions({
    layout: {
        title: 'Choose a new password',
        description:
            'You signed in with a temporary password. Set your own to continue.',
    },
});
</script>

<template>
    <Head title="Choose a new password" />

    <Form
        v-bind="ForcePasswordChangeController.update.form()"
        :reset-on-error="['password', 'password_confirmation', 'current_password']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <div class="grid gap-2">
            <Label for="current_password">Temporary password</Label>
            <PasswordInput
                id="current_password"
                name="current_password"
                required
                autofocus
                autocomplete="current-password"
            />
            <InputError :message="errors.current_password" />
        </div>

        <div class="grid gap-2">
            <Label for="password">New password</Label>
            <PasswordInput
                id="password"
                name="password"
                required
                autocomplete="new-password"
                :passwordrules="passwordRules"
            />
            <InputError :message="errors.password" />
        </div>

        <div class="grid gap-2">
            <Label for="password_confirmation">Confirm new password</Label>
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
            Save and continue
        </Button>
    </Form>

    <Link
        :href="logout()"
        as="button"
        type="button"
        class="mt-4 w-full text-center text-sm text-muted-foreground underline underline-offset-4"
    >
        Log out
    </Link>
</template>
