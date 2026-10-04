<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Lock } from '@lucide/vue';
import { reactive, ref } from 'vue';
import RoleController from '@/actions/App/Http/Controllers/Admin/RoleController';
import { Button } from '@/components/ui/button';
import { index } from '@/routes/admin/roles';

type RoleRow = {
    id: number;
    name: string;
    label: string;
    users: number;
    locked: boolean;
    permissions: string[];
};

const props = defineProps<{
    roles: RoleRow[];
    permissions: { name: string; label: string }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Roles & access', href: index() }],
    },
});

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
</script>

<template>
    <Head title="Roles & access" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">
                Roles & access
            </h1>
            <p class="text-sm text-muted-foreground">
                Global permissions per role. Super Admin always has full
                access. Client-workspace roles are added in P2.
            </p>
        </div>

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
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="p in permissions" :key="p.name">
                        <td class="px-4 py-2">
                            <div class="font-medium">{{ p.label }}</div>
                            <div class="font-mono text-xs text-muted-foreground">
                                {{ p.name }}
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
                                :aria-label="`${role.label}: ${p.label}`"
                                :checked="
                                    role.locked ||
                                    matrix[role.id].includes(p.name)
                                "
                                :disabled="role.locked"
                                @change="toggle(role.id, p.name)"
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
