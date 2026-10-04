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
                    <CardDescription>Needs my attention</CardDescription>
                    <CardTitle class="text-2xl">—</CardTitle>
                </CardHeader>
                <CardContent class="text-xs text-muted-foreground">
                    Mentions, handoffs and overdue alerts (P4).
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
