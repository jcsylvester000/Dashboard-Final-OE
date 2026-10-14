<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { Plus, ShieldCheck } from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';
import NativeSelect from '@/components/NativeSelect.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { departmentDot, roleLabel, timeAgo } from '@/lib/format';
import { bulkImport, create, csv, edit, index } from '@/routes/admin/users';
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
    importResult: {
        preview: boolean;
        rows: {
            line: number;
            name: string;
            email: string;
            role: string;
            department: string;
            errors: string[];
            status: string;
        }[];
        created: { name: string; email: string; link: string }[];
    } | null;
}>();

// E-93: add many people from a CSV (preview first; setup links shown once).
const showImport = ref(false);
const importFile = ref<File | null>(null);
const importing = ref(false);
function runImport(preview: boolean): void {
    if (!importFile.value) {
        return;
    }
    importing.value = true;
    router.post(
        bulkImport.url(),
        { file: importFile.value, preview },
        {
            forceFormData: true,
            preserveScroll: true,
            onFinish: () => (importing.value = false),
        },
    );
}
function copy(text: string): void {
    void navigator.clipboard?.writeText(text);
}

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
    router.get(
        index.url({ query }),
        {},
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
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
            <div class="flex flex-wrap gap-2">
                <Button variant="outline" as-child>
                    <a :href="csv.url()">Export CSV</a>
                </Button>
                <Button
                    v-if="canManage"
                    variant="outline"
                    @click="showImport = !showImport"
                    >Import CSV</Button
                >
                <Button v-if="canManage" as-child>
                    <Link :href="create()"><Plus /> Add member</Link>
                </Button>
            </div>
        </div>

        <div
            v-if="canManage && (showImport || importResult)"
            class="space-y-3 rounded-lg border p-4 text-sm"
        >
            <p class="font-medium">Add people from a CSV</p>
            <p class="text-xs text-muted-foreground">
                Header row, then one person per line:
                <code>name, email, role, department, title, access_ends_on</code
                >. Role and department use their names (e.g. Finance,
                Marketing). Preview first; each new person gets a one-time setup
                link to send them yourself.
            </p>
            <div class="flex flex-wrap items-center gap-2">
                <input
                    type="file"
                    accept=".csv,text/csv"
                    class="text-xs"
                    aria-label="CSV file"
                    @change="
                        importFile =
                            ($event.target as HTMLInputElement).files?.[0] ??
                            null
                    "
                />
                <Button
                    size="sm"
                    variant="outline"
                    :disabled="!importFile || importing"
                    @click="runImport(true)"
                    >Preview</Button
                >
                <Button
                    size="sm"
                    :disabled="!importFile || importing"
                    @click="runImport(false)"
                    >Add the valid rows</Button
                >
            </div>
            <div v-if="importResult" class="rounded-md border">
                <p class="border-b px-3 py-2 text-xs text-muted-foreground">
                    {{
                        importResult.preview
                            ? `${importResult.rows.filter((r) => !r.errors.length).length} ready to add`
                            : `${importResult.created.length} added`
                    }}
                    ·
                    {{
                        importResult.rows.filter((r) => r.errors.length).length
                    }}
                    with problems
                </p>
                <ul class="max-h-72 divide-y overflow-y-auto text-xs">
                    <li
                        v-for="r in importResult.rows"
                        :key="r.line"
                        class="px-3 py-1.5"
                        :class="
                            r.errors.length
                                ? 'text-rose-700 dark:text-rose-300'
                                : ''
                        "
                    >
                        Line {{ r.line }}: {{ r.name || '—' }} ·
                        {{ r.email || '—' }} · {{ r.role }}
                        <template v-if="r.department">
                            · {{ r.department }}</template
                        >
                        <template v-if="r.errors.length">
                            — {{ r.errors.join(' ') }}</template
                        >
                    </li>
                </ul>
                <div
                    v-if="importResult.created.length"
                    class="space-y-1 border-t p-3"
                >
                    <p class="text-xs font-medium">
                        Setup links (shown once; send each to the person):
                    </p>
                    <div
                        v-for="c in importResult.created"
                        :key="c.email"
                        class="flex items-center gap-2 text-xs"
                    >
                        <span class="w-48 shrink-0 truncate">{{ c.name }}</span>
                        <Input
                            :model-value="c.link"
                            readonly
                            class="h-7 min-w-0 flex-1 text-xs"
                            :aria-label="`Setup link for ${c.name}`"
                        />
                        <Button size="sm" variant="ghost" @click="copy(c.link)"
                            >Copy</Button
                        >
                    </div>
                </div>
            </div>
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
                <thead
                    class="bg-muted/50 text-xs text-muted-foreground uppercase"
                >
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
                                v-if="
                                    canManage &&
                                    (isSuperAdmin || u.role !== 'super-admin')
                                "
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
                            <Badge
                                :variant="u.is_active ? 'secondary' : 'outline'"
                            >
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
