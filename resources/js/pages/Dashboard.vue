<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ShieldAlert } from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { departmentDot, roleLabel, timeAgo } from '@/lib/format';
import { dashboard } from '@/routes';
import { index as activityIndex } from '@/routes/admin/activity';
import { edit as securityEdit } from '@/routes/security';
import { index as workspacesIndex, show as workspaceShow } from '@/routes/workspaces';
import { show as projectShow } from '@/routes/workspaces/projects';
import type { DepartmentOption } from '@/types/admin';

defineProps<{
    profile: {
        name: string;
        title: string | null;
        primaryDepartment: DepartmentOption | null;
        departments: DepartmentOption[];
        roles: string[];
        lastLoginAt: string | null;
        twoFactorEnabled: boolean;
    };
    team: {
        activeMembers: number;
        inactiveMembers: number;
        departments: (DepartmentOption & { members: number })[];
    } | null;
    recentActivity:
        | { id: number; action: string; actor: string | null; at: string }[]
        | null;
    workspaces: (DepartmentOption & { slug: string; openProjects: number })[];
    mentions: {
        id: number;
        by: string | null;
        at: string;
        project: string;
        projectId: number;
        workspace: string;
        workspaceSlug: string;
    }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
    },
});
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">
                Hi, {{ profile.name.split(' ')[0] }}
            </h1>
            <p class="text-sm text-muted-foreground">
                {{ profile.title || 'Team member' }}
                <span v-if="profile.primaryDepartment">
                    · {{ profile.primaryDepartment.name }}</span
                >
                · {{ profile.roles.map(roleLabel).join(', ') || 'No role' }}
            </p>
        </div>

        <Card
            v-if="!profile.twoFactorEnabled"
            class="border-amber-300 bg-amber-50 dark:border-amber-900 dark:bg-amber-950/30"
        >
            <CardContent class="flex items-center gap-3 py-3 text-sm">
                <ShieldAlert class="size-5 shrink-0 text-amber-600" />
                <span class="flex-1">
                    Protect your account: turn on two-factor authentication or
                    add a passkey.
                </span>
                <Link
                    :href="securityEdit()"
                    class="font-medium underline underline-offset-4"
                >
                    Security settings
                </Link>
            </CardContent>
        </Card>

        <div class="grid gap-4 md:grid-cols-3">
            <Card>
                <CardHeader>
                    <CardDescription>My open tasks</CardDescription>
                    <CardTitle class="text-2xl">—</CardTitle>
                </CardHeader>
                <CardContent class="text-xs text-muted-foreground">
                    Tasks arrive with workspaces and the shared workflow (P2–P3).
                </CardContent>
            </Card>
            <Card>
                <CardHeader>
                    <CardDescription>Tagged recently</CardDescription>
                    <CardTitle class="text-2xl">{{ mentions.length }}</CardTitle>
                </CardHeader>
                <CardContent class="text-xs text-muted-foreground">
                    Handoff and overdue alerts join this in P4.
                </CardContent>
            </Card>
            <Card>
                <CardHeader>
                    <CardDescription>Last login</CardDescription>
                    <CardTitle class="text-2xl">{{
                        timeAgo(profile.lastLoginAt)
                    }}</CardTitle>
                </CardHeader>
                <CardContent class="flex flex-wrap gap-1">
                    <Badge
                        v-for="d in profile.departments"
                        :key="d.id"
                        variant="outline"
                    >
                        <span
                            class="size-2 rounded-full"
                            :class="departmentDot[d.color] ?? 'bg-slate-500'"
                        />
                        {{ d.name }}
                    </Badge>
                </CardContent>
            </Card>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <Card>
                <CardHeader>
                    <CardTitle class="text-base">My workspaces</CardTitle>
                    <CardDescription>
                        <Link :href="workspacesIndex()" class="underline underline-offset-4"
                            >All workspaces</Link
                        >
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <p v-if="workspaces.length === 0" class="text-sm text-muted-foreground">
                        You are not on any client workspace yet.
                    </p>
                    <ul v-else class="divide-y text-sm">
                        <li v-for="w in workspaces" :key="w.id" class="flex items-center justify-between py-2">
                            <Link :href="workspaceShow(w.slug)" class="flex items-center gap-2 hover:underline">
                                <span class="size-2 rounded-full" :class="departmentDot[w.color] ?? 'bg-slate-500'" />
                                {{ w.name }}
                            </Link>
                            <span class="text-xs text-muted-foreground">{{ w.openProjects }} open</span>
                        </li>
                    </ul>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle class="text-base">Tagged in</CardTitle>
                    <CardDescription>Where teammates @mentioned you</CardDescription>
                </CardHeader>
                <CardContent>
                    <p v-if="mentions.length === 0" class="text-sm text-muted-foreground">No mentions yet.</p>
                    <ul v-else class="divide-y text-sm">
                        <li v-for="m in mentions" :key="m.id" class="flex items-center justify-between gap-3 py-2">
                            <span class="min-w-0 truncate">
                                <Link
                                    :href="projectShow({ workspace: m.workspaceSlug, project: m.projectId })"
                                    class="font-medium hover:underline"
                                    >{{ m.project }}</Link
                                >
                                <span class="text-xs text-muted-foreground">
                                    · {{ m.workspace }} · by {{ m.by ?? 'someone' }}</span
                                >
                            </span>
                            <span class="shrink-0 text-xs text-muted-foreground">{{ timeAgo(m.at) }}</span>
                        </li>
                    </ul>
                </CardContent>
            </Card>
        </div>

        <div v-if="team || recentActivity" class="grid gap-4 lg:grid-cols-2">
            <Card v-if="team">
                <CardHeader>
                    <CardTitle class="text-base">Team</CardTitle>
                    <CardDescription>
                        {{ team.activeMembers }} active ·
                        {{ team.inactiveMembers }} deactivated
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <ul class="divide-y text-sm">
                        <li
                            v-for="d in team.departments"
                            :key="d.id"
                            class="flex items-center justify-between py-2"
                        >
                            <span class="flex items-center gap-2">
                                <span
                                    class="size-2 rounded-full"
                                    :class="
                                        departmentDot[d.color] ?? 'bg-slate-500'
                                    "
                                />
                                {{ d.name }}
                            </span>
                            <span class="text-muted-foreground"
                                >{{ d.members }} members</span
                            >
                        </li>
                    </ul>
                </CardContent>
            </Card>

            <Card v-if="recentActivity">
                <CardHeader>
                    <CardTitle class="text-base">Recent activity</CardTitle>
                    <CardDescription>
                        <Link
                            :href="activityIndex()"
                            class="underline underline-offset-4"
                            >Open activity log</Link
                        >
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <ul class="divide-y text-sm">
                        <li
                            v-for="item in recentActivity"
                            :key="item.id"
                            class="flex items-center justify-between gap-3 py-2"
                        >
                            <span class="truncate">
                                <span class="font-medium">{{
                                    item.actor ?? 'System'
                                }}</span>
                                · {{ item.action }}
                            </span>
                            <span
                                class="shrink-0 text-xs text-muted-foreground"
                                >{{ timeAgo(item.at) }}</span
                            >
                        </li>
                    </ul>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
