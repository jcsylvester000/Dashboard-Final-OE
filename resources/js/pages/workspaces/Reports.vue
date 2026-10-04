<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Camera } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import WorkspaceHeader from '@/components/WorkspaceHeader.vue';
import { formatDate, formatDateTime } from '@/lib/format';
import { index as workspacesIndex } from '@/routes/workspaces';
import { index as reportsIndex, store } from '@/routes/workspaces/reports';
import { show as taskShow } from '@/routes/workspaces/tasks';
import type { WorkspaceHeader as Header } from '@/types/workspace';

type HoursRow = { name: string; hours: number; billable_hours: number };

type SnapshotData = {
    week_start: string;
    week_end: string;
    completed: number;
    created: number;
    open: number;
    overdue: number;
    hours: number;
    billable_hours: number;
    time_by_department: HoursRow[];
    time_by_person: HoursRow[];
    completed_tasks: { id: number; title: string; department: string | null; assignee: string | null; completed_on: string | null }[];
};

const props = defineProps<{
    workspace: Header;
    snapshots: { id: number; week_start: string; completed: number; hours: number }[];
    selected: { id: number; data: SnapshotData; saved_at: string | null } | null;
    canCapture: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Workspaces', href: workspacesIndex() }],
    },
});

function capture(): void {
    router.post(store.url(props.workspace.slug), {}, { preserveScroll: true });
}
</script>

<template>
    <Head :title="`${workspace.name} - Reports`" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <WorkspaceHeader :workspace="workspace" />

        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-muted-foreground">
                Weekly snapshots are saved every Monday morning for last week. Live numbers are in Reports.
            </p>
            <Button v-if="canCapture" size="sm" variant="outline" @click="capture">
                <Camera class="size-4" /> Save last week now
            </Button>
        </div>

        <p v-if="snapshots.length === 0" class="py-10 text-center text-sm text-muted-foreground">No snapshots yet.</p>

        <div v-else class="grid gap-4 lg:grid-cols-4">
            <Card class="lg:col-span-1">
                <CardHeader>
                    <CardTitle class="text-base">Weeks</CardTitle>
                </CardHeader>
                <CardContent>
                    <ul class="divide-y text-sm">
                        <li v-for="s in snapshots" :key="s.id">
                            <Link
                                :href="reportsIndex(workspace.slug, { query: { snapshot: String(s.id) } })"
                                class="flex items-center justify-between py-2 hover:underline"
                                :class="{ 'font-semibold': selected?.id === s.id }"
                                preserve-scroll
                            >
                                <span>{{ formatDate(s.week_start) }}</span>
                                <span class="text-xs text-muted-foreground">{{ s.completed }} done · {{ s.hours }} h</span>
                            </Link>
                        </li>
                    </ul>
                </CardContent>
            </Card>

            <div v-if="selected" class="flex flex-col gap-4 lg:col-span-3">
                <div class="grid gap-3 sm:grid-cols-3">
                    <Card>
                        <CardHeader>
                            <CardDescription>Week of {{ formatDate(selected.data.week_start) }}</CardDescription>
                            <CardTitle class="text-2xl">{{ selected.data.completed }} done</CardTitle>
                        </CardHeader>
                        <CardContent class="text-xs text-muted-foreground">{{ selected.data.created }} created</CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardDescription>Hours logged</CardDescription>
                            <CardTitle class="text-2xl">{{ selected.data.hours }}</CardTitle>
                        </CardHeader>
                        <CardContent class="text-xs text-muted-foreground">{{ selected.data.billable_hours }} billable</CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardDescription>Open when saved</CardDescription>
                            <CardTitle class="text-2xl">{{ selected.data.open }}</CardTitle>
                        </CardHeader>
                        <CardContent class="text-xs text-muted-foreground">
                            {{ selected.data.overdue }} overdue · saved {{ formatDateTime(selected.saved_at) }}
                        </CardContent>
                    </Card>
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <Card v-for="block in [
                        { title: 'Hours by department', rows: selected.data.time_by_department },
                        { title: 'Hours by person', rows: selected.data.time_by_person },
                    ]" :key="block.title">
                        <CardHeader>
                            <CardTitle class="text-base">{{ block.title }}</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p v-if="block.rows.length === 0" class="text-sm text-muted-foreground">No time logged.</p>
                            <ul v-else class="divide-y text-sm">
                                <li v-for="r in block.rows" :key="r.name" class="flex justify-between py-1.5">
                                    <span>{{ r.name }}</span>
                                    <span class="tabular-nums">{{ r.hours }} h <span class="text-xs text-muted-foreground">({{ r.billable_hours }} billable)</span></span>
                                </li>
                            </ul>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle class="text-base">Finished that week</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p v-if="selected.data.completed_tasks.length === 0" class="text-sm text-muted-foreground">Nothing finished.</p>
                        <ul v-else class="divide-y text-sm">
                            <li v-for="t in selected.data.completed_tasks" :key="t.id" class="flex justify-between gap-3 py-1.5">
                                <Link :href="taskShow({ workspace: workspace.slug, task: t.id })" class="truncate hover:underline">{{ t.title }}</Link>
                                <span class="shrink-0 text-xs text-muted-foreground">
                                    {{ t.department ?? '—' }} · {{ t.assignee ?? 'unassigned' }} · {{ formatDate(t.completed_on) }}
                                </span>
                            </li>
                        </ul>
                    </CardContent>
                </Card>
            </div>
        </div>
    </div>
</template>
