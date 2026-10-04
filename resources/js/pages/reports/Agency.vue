<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { RefreshCw } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { departmentDot, formatDateTime } from '@/lib/format';
import { agency } from '@/routes/reports';
import { show as workspaceShow } from '@/routes/workspaces';
import type { WorkspaceHealth } from '@/types/reports';

type Row = {
    id: number;
    name: string;
    slug: string;
    color: string;
    open: number;
    overdue: number;
    blocked: number;
    done_7d: number;
    hours_week: number;
    health: WorkspaceHealth;
};

type Person = {
    id: number;
    name: string;
    title: string | null;
    open: number;
    overdue: number;
    due_week: number;
    hours_week: number;
};

defineProps<{
    workspaces: Row[];
    team: Person[];
    totals: { workspaces: number; on_track: number; at_risk: number; overdue: number };
    generated_at: string;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Agency overview', href: agency() }],
    },
});

const healthLabel: Record<WorkspaceHealth, string> = {
    on_track: 'On track',
    at_risk: 'At risk',
    overdue: 'Overdue',
};

const healthClass: Record<WorkspaceHealth, string> = {
    on_track: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    at_risk: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
    overdue: 'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300',
};

function refresh(): void {
    router.get(agency.url({ query: { refresh: '1' } }), {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Agency overview" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                title="Agency overview"
                description="Health of every active client and the team's workload. Overdue = 5+ overdue tasks or 25%+ of open work overdue; at risk = any overdue or blocked task."
            />
            <div class="flex items-center gap-2 text-xs text-muted-foreground">
                Updated {{ formatDateTime(generated_at) }}
                <Button variant="outline" size="sm" @click="refresh"><RefreshCw class="size-4" /> Refresh</Button>
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-4">
            <Card>
                <CardHeader>
                    <CardDescription>Active clients</CardDescription>
                    <CardTitle class="text-2xl">{{ totals.workspaces }}</CardTitle>
                </CardHeader>
            </Card>
            <Card v-for="h in (['on_track', 'at_risk', 'overdue'] as const)" :key="h">
                <CardHeader>
                    <CardDescription>{{ healthLabel[h] }}</CardDescription>
                    <CardTitle class="text-2xl">{{ totals[h] }}</CardTitle>
                </CardHeader>
            </Card>
        </div>

        <Card>
            <CardHeader>
                <CardTitle class="text-base">Clients</CardTitle>
            </CardHeader>
            <CardContent class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs text-muted-foreground">
                        <tr>
                            <th class="py-2 font-medium">Client</th>
                            <th class="py-2 font-medium">Health</th>
                            <th class="py-2 text-right font-medium">Open</th>
                            <th class="py-2 text-right font-medium">Overdue</th>
                            <th class="py-2 text-right font-medium">Blocked</th>
                            <th class="py-2 text-right font-medium">Done (7 days)</th>
                            <th class="py-2 text-right font-medium">Hours this week</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="w in workspaces" :key="w.id">
                            <td class="py-2">
                                <Link :href="workspaceShow(w.slug)" class="flex items-center gap-2 hover:underline">
                                    <span class="size-2 rounded-full" :class="departmentDot[w.color] ?? 'bg-slate-500'" />
                                    {{ w.name }}
                                </Link>
                            </td>
                            <td class="py-2">
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="healthClass[w.health]">{{
                                    healthLabel[w.health]
                                }}</span>
                            </td>
                            <td class="py-2 text-right tabular-nums">{{ w.open }}</td>
                            <td class="py-2 text-right tabular-nums" :class="{ 'font-medium text-rose-600': w.overdue }">{{ w.overdue }}</td>
                            <td class="py-2 text-right tabular-nums">{{ w.blocked }}</td>
                            <td class="py-2 text-right tabular-nums">{{ w.done_7d }}</td>
                            <td class="py-2 text-right tabular-nums">{{ w.hours_week }}</td>
                        </tr>
                    </tbody>
                </table>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle class="text-base">Team workload</CardTitle>
                <CardDescription>Open tasks assigned to each active member; hours logged this week.</CardDescription>
            </CardHeader>
            <CardContent class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs text-muted-foreground">
                        <tr>
                            <th class="py-2 font-medium">Member</th>
                            <th class="py-2 text-right font-medium">Open</th>
                            <th class="py-2 text-right font-medium">Overdue</th>
                            <th class="py-2 text-right font-medium">Due this week</th>
                            <th class="py-2 text-right font-medium">Hours this week</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="p in team" :key="p.id">
                            <td class="py-2">
                                {{ p.name }}
                                <span v-if="p.title" class="text-xs text-muted-foreground">· {{ p.title }}</span>
                            </td>
                            <td class="py-2 text-right tabular-nums">{{ p.open }}</td>
                            <td class="py-2 text-right tabular-nums" :class="{ 'font-medium text-rose-600': p.overdue }">{{ p.overdue }}</td>
                            <td class="py-2 text-right tabular-nums">{{ p.due_week }}</td>
                            <td class="py-2 text-right tabular-nums">{{ p.hours_week }}</td>
                        </tr>
                    </tbody>
                </table>
            </CardContent>
        </Card>
    </div>
</template>
