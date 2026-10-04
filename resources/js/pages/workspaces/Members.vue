<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import { UserMinus } from '@lucide/vue';
import WorkspaceMemberController from '@/actions/App/Http/Controllers/Workspaces/WorkspaceMemberController';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import WorkspaceHeader from '@/components/WorkspaceHeader.vue';
import { roleLabel } from '@/lib/format';
import { index as workspacesIndex } from '@/routes/workspaces';
import type { DepartmentOption } from '@/types/admin';
import type { MemberOption, WorkspaceHeader as Header } from '@/types/workspace';

type MemberRow = {
    id: number;
    name: string;
    email: string;
    title: string | null;
    is_active: boolean;
    role: string;
    department_id: number | null;
};

const props = defineProps<{
    workspace: Header;
    members: MemberRow[];
    roles: string[];
    departments: DepartmentOption[];
    candidates: MemberOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Workspaces', href: workspacesIndex() }],
    },
});

const roleHelp: Record<string, string> = {
    owner: 'Settings, members and everything below',
    lead: 'Manage projects and labels',
    member: 'Create and edit projects',
    guest: 'Read-only',
};

function update(
    member: MemberRow,
    field: 'role' | 'department_id',
    value: string | number | null | undefined,
): void {
    router.put(
        WorkspaceMemberController.update.url({ workspace: props.workspace.slug, user: member.id }),
        {
            role: field === 'role' ? value : member.role,
            department_id: field === 'department_id' ? value || null : member.department_id,
        },
        { preserveScroll: true },
    );
}

function remove(member: MemberRow): void {
    if (!window.confirm(`Remove ${member.name} from ${props.workspace.name}? They will also be removed from its projects.`)) {
        return;
    }

    router.delete(
        WorkspaceMemberController.destroy.url({ workspace: props.workspace.slug, user: member.id }),
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head :title="`${workspace.name} · Members`" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <WorkspaceHeader :workspace="workspace" />

        <InputError :message="($page.props.errors as Record<string, string>)?.role" />

        <Card v-if="workspace.can.manageMembers && candidates.length">
            <CardHeader>
                <CardTitle class="text-base">Add a team member</CardTitle>
            </CardHeader>
            <CardContent>
                <Form
                    v-bind="WorkspaceMemberController.store.form(workspace.slug)"
                    v-slot="{ errors, processing }"
                    class="grid gap-3 sm:grid-cols-4 sm:items-end"
                    preserve-scroll
                    reset-on-success
                >
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="add-user">Person</Label>
                        <NativeSelect id="add-user" name="user_id" model-value="" required>
                            <option value="" disabled>Choose…</option>
                            <option v-for="c in candidates" :key="c.id" :value="c.id">
                                {{ c.name }}{{ c.title ? ` — ${c.title}` : '' }}
                            </option>
                        </NativeSelect>
                        <InputError :message="errors.user_id" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="add-role">Workspace role</Label>
                        <NativeSelect id="add-role" name="role" model-value="member">
                            <option v-for="r in roles" :key="r" :value="r">{{ roleLabel(r) }}</option>
                        </NativeSelect>
                    </div>
                    <div class="grid gap-2">
                        <Label for="add-dept">Department here</Label>
                        <NativeSelect id="add-dept" name="department_id" model-value="">
                            <option value="">—</option>
                            <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
                        </NativeSelect>
                    </div>
                    <div class="sm:col-span-4">
                        <Button type="submit" :disabled="processing">Add to workspace</Button>
                    </div>
                </Form>
            </CardContent>
        </Card>

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-left text-sm">
                <thead class="bg-muted/50 text-xs text-muted-foreground uppercase">
                    <tr>
                        <th class="px-4 py-2 font-medium">Member</th>
                        <th class="px-4 py-2 font-medium">Role</th>
                        <th class="px-4 py-2 font-medium">Department</th>
                        <th v-if="workspace.can.manageMembers" class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="m in members" :key="m.id" :class="{ 'opacity-60': !m.is_active }">
                        <td class="px-4 py-2">
                            <div class="font-medium">{{ m.name }}</div>
                            <div class="text-xs text-muted-foreground">
                                {{ m.title || m.email }}
                            </div>
                        </td>
                        <td class="px-4 py-2">
                            <NativeSelect
                                v-if="workspace.can.manageMembers"
                                :model-value="m.role"
                                class="w-36"
                                :aria-label="`Role for ${m.name}`"
                                @update:model-value="(v) => update(m, 'role', v)"
                            >
                                <option v-for="r in roles" :key="r" :value="r">{{ roleLabel(r) }}</option>
                            </NativeSelect>
                            <span v-else :title="roleHelp[m.role]">{{ roleLabel(m.role) }}</span>
                        </td>
                        <td class="px-4 py-2">
                            <NativeSelect
                                v-if="workspace.can.manageMembers"
                                :model-value="m.department_id ?? ''"
                                class="w-40"
                                :aria-label="`Department for ${m.name}`"
                                @update:model-value="(v) => update(m, 'department_id', v)"
                            >
                                <option value="">—</option>
                                <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
                            </NativeSelect>
                            <span v-else>{{ departments.find((d) => d.id === m.department_id)?.name ?? '—' }}</span>
                        </td>
                        <td v-if="workspace.can.manageMembers" class="px-4 py-2 text-right">
                            <Button
                                variant="ghost"
                                size="sm"
                                :aria-label="`Remove ${m.name}`"
                                @click="remove(m)"
                            >
                                <UserMinus />
                            </Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p class="text-xs text-muted-foreground">
            Roles: <strong>Owner</strong> — {{ roleHelp.owner }} ·
            <strong>Lead</strong> — {{ roleHelp.lead }} ·
            <strong>Member</strong> — {{ roleHelp.member }} ·
            <strong>Guest</strong> — {{ roleHelp.guest }}
        </p>
    </div>
</template>
