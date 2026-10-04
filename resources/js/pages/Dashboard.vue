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
import { departmentDot, formatDate, isOverdue, roleLabel, timeAgo } from '@/lib/format';
import { dashboard } from '@/routes';
import { index as inboxIndex, open as openAlert } from '@/routes/inbox';
import { mine } from '@/routes/tasks';
import { index as activityIndex } from '@/routes/admin/activity';
import { edit as securityEdit } from '@/routes/security';
import { index as workspacesIndex, show as workspaceShow } from '@/routes/workspaces';
import { show as taskShow } from '@/routes/workspaces/tasks';
import type { DepartmentOption } from '@/types/admin';
import type { InboxItem } from '@/types/notifications';

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
    workspaces: (DepartmentOption & { slug: string; openProjects: number; myOpenTasks: number })[];
    myTasks: { open: number; overdue: number; dueWeek: number };
    nextTasks: {
        id: number;
        title: string;
        priority: string;
        due_on: string | null;
        status: string;
        workspace: string;
        workspaceSlug: string;
    }[];
    digest: {
        unread: number;
        dueToday: number;
        overdue: number;
        items: InboxItem[];
    };
    mentions: {
        id: number;
        kind: 'project' | 'task' | 'comment';
        by: string | null;
        at: string;
        label: string;
        url: string;
        workspace: string;
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
                    <CardTitle class="text-2xl">{{ myTasks.open }}</CardTitle>
                </CardHeader>
                <CardContent class="text-xs">
                    <span :class="myTasks.overdue ? 'font-medium text-rose-600' : 'text-muted-foreground'"
                        >{{ myTasks.overdue }} overdue</span
                    >
                    · <span class="text-muted-foreground">{{ myTasks.dueWeek }} due this week</span> ·
                    <Link :href="mine()" class="underline underline-offset-4">Open My tasks</Link>
                </CardContent>
            </Card>
            <Card>
                <CardHeader>
                    <CardDescription>Unread alerts</CardDescription>
                    <CardTitle class="text-2xl">{{ digest.unread }}</CardTitle>
                </CardHeader>
                <CardContent class="text-xs">
                    <span :class="digest.dueToday ? 'font-medium text-orange-600' : 'text-muted-foreground'"
                        >{{ digest.dueToday }} due today</span
                    >
                    ·
                    <Link :href="inboxIndex()" class="underline underline-offset-4">Open inbox</Link>
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

        <Card>
            <CardHeader>
                <CardTitle class="text-base">Daily digest</CardTitle>
                <CardDescription>
                    Last 24 hours · {{ digest.overdue }} overdue · {{ digest.dueToday }} due today ·
                    <Link :href="inboxIndex()" class="underline underline-offset-4">Inbox</Link>
                </CardDescription>
            </CardHeader>
            <CardContent>
                <p v-if="digest.items.length === 0" class="text-sm text-muted-foreground">
                    All quiet. New assignments, mentions, handoffs and due dates show up here.
                </p>
                <ul v-else class="divide-y text-sm">
                    <li v-for="n in digest.items" :key="n.id" class="flex items-center justify-between gap-3 py-2">
                        <span class="min-w-0 truncate">
                            <Link :href="openAlert(n.id)" :class="n.read ? '' : 'font-medium'" class="hover:underline">{{
                                n.title
                            }}</Link>
                            <span v-if="n.body" class="text-muted-foreground"> · {{ n.body }}</span>
                            <span v-if="n.workspace" class="text-xs text-muted-foreground"> · {{ n.workspace }}</span>
                        </span>
                        <span class="shrink-0 text-xs text-muted-foreground">{{ timeAgo(n.created_at) }}</span>
                    </li>
                </ul>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle class="text-base">Up next</CardTitle>
                <CardDescription>My open tasks by due date</CardDescription>
            </CardHeader>
            <CardContent>
                <p v-if="nextTasks.length === 0" class="text-sm text-muted-foreground">Nothing assigned to you.</p>
                <ul v-else class="divide-y text-sm">
                    <li v-for="t in nextTasks" :key="t.id" class="flex items-center justify-between gap-3 py-2">
                        <span class="min-w-0 truncate">
                            <Link :href="taskShow({ workspace: t.workspaceSlug, task: t.id })" class="font-medium hover:underline">{{
                                t.title
                            }}</Link>
                            <span class="text-xs text-muted-foreground"> · {{ t.workspace }} · {{ t.status }}</span>
                        </span>
                        <span class="shrink-0 text-xs" :class="isOverdue(t.due_on) ? 'font-medium text-rose-600' : 'text-muted-foreground'">{{
                            t.due_on ? formatDate(t.due_on) : 'No due date'
                        }}</span>
                    </li>
                </ul>
            </CardContent>
        </Card>

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
                            <span class="text-xs text-muted-foreground"
                                >{{ w.myOpenTasks }} my tasks · {{ w.openProjects }} open projects</span
                            >
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
                                <Link :href="m.url" class="font-medium hover:underline">{{ m.label }}</Link>
                                <span class="text-xs text-muted-foreground">
                                    · {{ m.kind === 'comment' ? 'in a comment' : m.kind }} · {{ m.workspace }} · by
                                    {{ m.by ?? 'someone' }}</span
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
