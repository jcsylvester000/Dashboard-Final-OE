<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Copy, Lock, Trash2 } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import RoleController from '@/actions/App/Http/Controllers/Admin/RoleController';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { confirmDialog } from '@/composables/useConfirm';
import { index } from '@/routes/admin/roles';

/**
 * E-89: roles and what each can do. Permissions are grouped with plain descriptions;
 * an umbrella ("Everything in billing") includes the parts under it. Roles can be
 * created from scratch or copied; built-in roles can't be deleted, nor can a role
 * people still hold.
 */
type RoleRow = {
    id: number;
    name: string;
    label: string;
    description: string | null;
    users: number;
    locked: boolean;
    built_in: boolean;
    permissions: string[];
};
type PermissionRow = {
    name: string;
    label: string;
    description: string | null;
    parent: string | null;
    requires: string | null;
};

const props = defineProps<{
    roles: RoleRow[];
    permissions: PermissionRow[];
    groups: Record<string, string[]>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Roles & access', href: index() }],
    },
});

const page = usePage();
const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string | undefined>,
);
const byName = computed(
    () =>
        Object.fromEntries(props.permissions.map((p) => [p.name, p])) as Record<
            string,
            PermissionRow
        >,
);

// Editable copy of each role's permission list.
const matrix = reactive<Record<number, string[]>>(
    Object.fromEntries(props.roles.map((r) => [r.id, [...r.permissions]])),
);
const saving = ref<number | null>(null);

function toggle(roleId: number, permission: string): void {
    const list = matrix[roleId];
    const at = list.indexOf(permission);
    if (at === -1) {
        list.push(permission);
    } else {
        list.splice(at, 1);
    }
}

/** Ticked through its umbrella (e.g. "Everything in billing"). */
function implied(roleId: number, permission: string): boolean {
    const parent = byName.value[permission]?.parent;
    return !!parent && matrix[roleId].includes(parent);
}

function isDirty(role: RoleRow): boolean {
    const current = [...matrix[role.id]].sort().join('|');
    return current !== [...role.permissions].sort().join('|');
}

function save(role: RoleRow): void {
    saving.value = role.id;
    router.put(
        RoleController.update.url(role.id),
        { permissions: matrix[role.id] },
        {
            preserveScroll: true,
            onFinish: () => (saving.value = null),
        },
    );
}

const createForm = useForm({
    label: '',
    description: '',
    copy_from: '' as string | number,
});
function copyRole(role: RoleRow): void {
    createForm.label = `${role.label} (copy)`;
    createForm.copy_from = role.id;
    document.getElementById('new-role-label')?.focus();
}
async function removeRole(role: RoleRow): Promise<void> {
    if (await confirmDialog(`Delete the role "${role.label}"?`)) {
        router.delete(RoleController.destroy.url(role.id), {
            preserveScroll: true,
        });
    }
}
</script>

<template>
    <Head title="Roles & access" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Roles & access</h1>
            <p class="text-sm text-muted-foreground">
                What each role can do across the agency. Super Admin always has
                full access. Client-workspace roles (Lead, Member, Guest) are
                set per workspace.
            </p>
        </div>

        <form
            class="flex flex-wrap items-end gap-2 rounded-lg border p-3 text-sm"
            @submit.prevent="
                createForm.post(RoleController.store.url(), {
                    preserveScroll: true,
                    onSuccess: () => createForm.reset(),
                })
            "
        >
            <label class="grid gap-1 text-xs text-muted-foreground"
                >New role
                <Input
                    id="new-role-label"
                    v-model="createForm.label"
                    class="h-9 w-56"
                    placeholder="e.g. Bookkeeper"
            /></label>
            <label class="grid gap-1 text-xs text-muted-foreground"
                >Start from
                <NativeSelect v-model="createForm.copy_from" class="h-9 w-48">
                    <option value="">No permissions</option>
                    <option
                        v-for="r in roles.filter((x) => !x.locked)"
                        :key="r.id"
                        :value="r.id"
                    >
                        Copy of {{ r.label }}
                    </option>
                </NativeSelect>
            </label>
            <label
                class="grid min-w-0 flex-1 gap-1 text-xs text-muted-foreground"
                >Description (optional)
                <Input v-model="createForm.description" class="h-9"
            /></label>
            <Button
                type="submit"
                size="sm"
                :disabled="createForm.processing || !createForm.label.trim()"
                >Create role</Button
            >
            <InputError class="w-full" :message="createForm.errors.label" />
        </form>
        <InputError :message="errors.role ?? errors.permissions" />

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-xs text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2 text-left font-medium">
                            Permission
                        </th>
                        <th
                            v-for="role in roles"
                            :key="role.id"
                            class="px-3 py-2 text-center font-medium"
                        >
                            <div class="flex items-center justify-center gap-1">
                                <Lock v-if="role.locked" class="size-3" />
                                {{ role.label }}
                            </div>
                            <div class="font-normal">
                                {{ role.users }} member{{
                                    role.users === 1 ? '' : 's'
                                }}
                            </div>
                            <div
                                v-if="!role.locked"
                                class="mt-1 flex justify-center gap-1"
                            >
                                <button
                                    type="button"
                                    class="rounded p-0.5 hover:bg-muted"
                                    :aria-label="`Copy ${role.label}`"
                                    title="Copy this role"
                                    @click="copyRole(role)"
                                >
                                    <Copy class="size-3.5" />
                                </button>
                                <button
                                    v-if="!role.built_in"
                                    type="button"
                                    class="rounded p-0.5 text-rose-600 hover:bg-muted"
                                    :aria-label="`Delete ${role.label}`"
                                    title="Delete this role"
                                    @click="removeRole(role)"
                                >
                                    <Trash2 class="size-3.5" />
                                </button>
                            </div>
                        </th>
                    </tr>
                </thead>
                <tbody
                    v-for="(names, group) in groups"
                    :key="group"
                    class="divide-y"
                >
                    <tr class="bg-muted/30">
                        <th
                            :colspan="roles.length + 1"
                            class="px-4 py-1.5 text-left text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                        >
                            {{ group }}
                        </th>
                    </tr>
                    <tr v-for="name in names" :key="name">
                        <td
                            class="px-4 py-2"
                            :class="byName[name]?.parent ? 'pl-8' : ''"
                        >
                            <div class="font-medium">
                                {{ byName[name]?.label ?? name }}
                            </div>
                            <div
                                v-if="byName[name]?.description"
                                class="max-w-md text-xs text-muted-foreground"
                            >
                                {{ byName[name]?.description }}
                            </div>
                        </td>
                        <td
                            v-for="role in roles"
                            :key="role.id"
                            class="px-3 py-2 text-center"
                        >
                            <input
                                type="checkbox"
                                class="size-4"
                                :aria-label="`${role.label}: ${byName[name]?.label ?? name}`"
                                :checked="
                                    role.locked ||
                                    matrix[role.id].includes(name) ||
                                    implied(role.id, name)
                                "
                                :disabled="
                                    role.locked || implied(role.id, name)
                                "
                                :title="
                                    implied(role.id, name)
                                        ? 'Included by the umbrella permission above'
                                        : ''
                                "
                                @change="toggle(role.id, name)"
                            />
                        </td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="border-t">
                        <td class="px-4 py-2"></td>
                        <td
                            v-for="role in roles"
                            :key="role.id"
                            class="px-3 py-2 text-center"
                        >
                            <Button
                                v-if="!role.locked"
                                size="sm"
                                :variant="isDirty(role) ? 'default' : 'outline'"
                                :disabled="!isDirty(role) || saving !== null"
                                @click="save(role)"
                            >
                                Save
                            </Button>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</template>
