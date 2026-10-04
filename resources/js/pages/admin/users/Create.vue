<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import UserController from '@/actions/App/Http/Controllers/Admin/UserController';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { create, index } from '@/routes/admin/users';
import type { DepartmentOption, Option } from '@/types/admin';
import UserFields from './partials/UserFields.vue';

defineProps<{
    roles: Option[];
    departments: DepartmentOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Team members', href: index() },
            { title: 'Add member', href: create() },
        ],
    },
});
</script>

<template>
    <Head title="Add team member" />

    <div class="flex max-w-3xl flex-1 flex-col gap-6 p-4">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">
                Add team member
            </h1>
            <p class="text-sm text-muted-foreground">
                After saving you get a one-time setup link (valid 72 hours).
                Share it with the member directly — the app does not send
                email.
            </p>
        </div>

        <Form
            v-bind="UserController.store.form()"
            v-slot="{ errors, processing }"
            class="flex flex-col gap-6"
        >
            <UserFields
                :roles="roles"
                :departments="departments"
                :errors="errors"
            />

            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="processing">
                    <Spinner v-if="processing" />
                    Create and get setup link
                </Button>
                <Button variant="ghost" as-child>
                    <Link :href="index()">Cancel</Link>
                </Button>
            </div>
        </Form>
    </div>
</template>
