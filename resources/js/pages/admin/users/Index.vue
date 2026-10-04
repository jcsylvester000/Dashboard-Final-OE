<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { Plus, ShieldCheck } from '@lucide/vue';
import { computed, reactive, watch } from 'vue';
import NativeSelect from '@/components/NativeSelect.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { departmentDot, roleLabel, timeAgo } from '@/lib/format';
import { create, edit, index } from '@/routes/admin/users';
import type {
    DepartmentOption,
    Option,
    Paginated,
    UserRow,
} from '@/types/admin';

const props = defineProps<{
    users: Paginated<UserRow>;
    filters: {
        search?: string;
        status?: string;
        role?: string;
        department?: number | string;
    };
    roles: Option[];
    departments: DepartmentOption[];
    canManage: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Team members', href: index() }],
    },
});

const page = usePage();
const isSuperAdmin = computed(() =>
    (page.props.auth.roles ?? []).includes('super-admin'),
);

const form = reactive({
    search: props.filters.search ?? '',
    status: props.filters.status ?? '',
    role: props.filters.role ?? '',
    department: props.filters.department
        ? String(props.filters.department)
        : '',
});

const apply = useDebounceFn(() => {
    const query = Object.fromEntries(
        Object.entries(form).filter(([, value]) => value !== ''),
    );
    router.get(index.url({ query }), {}, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}, 300);

watch(form, apply);
</script>

<template>
    <Head title="Team members" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">
                    Team members
                </h1>
                <p class="text-sm text-muted-foreground">
                    Create accounts, set roles and departments, and issue
                    one-time access links.
                </p>
            </div>
            <Button v-if="canManage" as-child>
                <Link :href="create()"><Plus /> Add member</Link>
            </Button>
        </div>

        <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
            <Input
                v-model="form.search"
                type="search"
                placeholder="Search name, email or title"
                aria-label="Search team members"
            />
            <NativeSelect v-model="form.status" aria-label="Status">
                <option value="">All statuses</option>
                <option value="active">Active</option>
                <option value="inactive">Deactivated</option>
            </NativeSelect>
            <NativeSelect v-model="form.role" aria-label="Role">
                <option value="">All roles</option>
                <option v-for="r in roles" :key="r.value" :value="r.value">
                    {{ r.label }}
                </option>
            </NativeSelect>
            <NativeSelect v-model="form.department" aria-label="Department">
                <option value="">All departments</option>
                <option
                    v-for="d in departments"
                    :key="d.id"
                    :value="String(d.id)"
                >
                    {{ d.name }}
                </option>
            </NativeSelect>
        </div>

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-left text-sm">
                <thead class="bg-muted/50 text-xs text-muted-foreground uppercase">
                    <tr>
                        <th class="px-4 py-2 font-medium">Member</th>
                        <th class="px-4 py-2 font-medium">Role</th>
                        <th class="px-4 py-2 font-medium">Department</th>
                        <th class="px-4 py-2 font-medium">Last login</th>
                        <th class="px-4 py-2 font-medium">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-if="users.data.length === 0">
                        <td
                            colspan="5"
                            class="px-4 py-8 text-center text-muted-foreground"
                        >
                            No team members match these filters.
                        </td>
                    </tr>
                    <tr
                        v-for="u in users.data"
                        :key="u.id"
                        :class="{ 'opacity-60': !u.is_active }"
                    >
                        <td class="px-4 py-2">
                            <Link
                                v-if="canManage && (isSuperAdmin || u.role !== 'super-admin')"
                                :href="edit(u.id)"
                                class="font-medium hover:underline"
                                >{{ u.name }}</Link
                            >
                            <span v-else class="font-medium">{{ u.name }}</span>
                            <div class="text-xs text-muted-foreground">
                                {{ u.email
                                }}<span v-if="u.title"> · {{ u.title }}</span>
                            </div>
                        </td>
                        <td class="px-4 py-2">
                            <span class="inline-flex items-center gap-1">
                                {{ roleLabel(u.role) }}
                                <ShieldCheck
                                    v-if="u.two_factor"
                                    class="size-3.5 text-emerald-600"
                                    aria-label="Two-factor on"
                                />
                            </span>
                        </td>
                        <td class="px-4 py-2">
                            <span
                                v-if="u.department"
                                class="inline-flex items-center gap-2"
                            >
                                <span
                                    class="size-2 rounded-full"
                                    :class="
                                        departmentDot[u.department.color] ??
                                        'bg-slate-500'
                                    "
                                />
                                {{ u.department.name }}
                            </span>
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                        <td class="px-4 py-2 text-muted-foreground">
                            {{ timeAgo(u.last_login_at) }}
                        </td>
                        <td class="px-4 py-2">
                            <Badge :variant="u.is_active ? 'secondary' : 'outline'">
                                {{ u.is_active ? 'Active' : 'Deactivated' }}
                            </Badge>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination
            :links="users.links"
            :from="users.from"
            :to="users.to"
            :total="users.total"
        />
    </div>
</template>
