<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import WorkspaceHeader from '@/components/WorkspaceHeader.vue';
import { addDays, departmentDot } from '@/lib/format';
import { index as workspacesIndex } from '@/routes/workspaces';
import { calendar, show } from '@/routes/workspaces/tasks';
import type { TaskRow } from '@/types/work';
import type { WorkspaceHeader as Header } from '@/types/workspace';

const props = defineProps<{
    workspace: Header;
    month: string;
    gridStart: string;
    gridEnd: string;
    tasks: TaskRow[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Workspaces', href: workspacesIndex() }],
    },
});

const today = new Date().toISOString().slice(0, 10);

const days = computed(() => {
    const out: string[] = [];
    for (let d = props.gridStart; d <= props.gridEnd; d = addDays(d, 1)) {
        out.push(d);
    }

    return out;
});

const byDay = computed(() => {
    const map: Record<string, TaskRow[]> = {};
    for (const t of props.tasks) {
        if (t.due_on) {
            (map[t.due_on] ??= []).push(t);
        }
    }

    return map;
});

const title = computed(() => {
    const [y, m] = props.month.split('-').map(Number);

    return new Date(y, m - 1, 1).toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
});

function shift(months: number): void {
    const [y, m] = props.month.split('-').map(Number);
    const d = new Date(Date.UTC(y, m - 1 + months, 1));
    router.get(calendar.url(props.workspace.slug, { query: { month: d.toISOString().slice(0, 7) } }), {}, { preserveScroll: true });
}

const weekdays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
</script>

<template>
    <Head :title="`${workspace.name} · Calendar`" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <WorkspaceHeader :workspace="workspace" />

        <div class="flex items-center gap-2">
            <Button variant="outline" size="sm" aria-label="Previous month" @click="shift(-1)"><ChevronLeft /></Button>
            <h2 class="min-w-40 text-center font-semibold">{{ title }}</h2>
            <Button variant="outline" size="sm" aria-label="Next month" @click="shift(1)"><ChevronRight /></Button>
        </div>

        <div class="grid grid-cols-7 overflow-hidden rounded-lg border text-xs">
            <div v-for="w in weekdays" :key="w" class="border-b bg-muted/50 px-2 py-1 font-medium text-muted-foreground">{{ w }}</div>
            <div
                v-for="d in days"
                :key="d"
                class="min-h-24 border-r border-b p-1 last:border-r-0"
                :class="{ 'bg-muted/30': d.slice(0, 7) !== month }"
            >
                <div class="mb-1 text-right" :class="d === today ? 'font-bold text-primary' : 'text-muted-foreground'">
                    {{ Number(d.slice(8)) }}
                </div>
                <Link
                    v-for="t in byDay[d] ?? []"
                    :key="t.id"
                    :href="show({ workspace: workspace.slug, task: t.id })"
                    class="mb-1 flex items-center gap-1 truncate rounded px-1 py-0.5 hover:bg-accent"
                    :class="{ 'text-muted-foreground line-through': t.status.category === 'done', 'font-medium text-rose-600': t.due_on! < today && t.status.category !== 'done' }"
                    :title="`${t.title} · ${t.status.name}${t.assignee ? ' · ' + t.assignee.name : ''}`"
                >
                    <span class="size-1.5 shrink-0 rounded-full" :class="departmentDot[t.department?.color ?? ''] ?? 'bg-slate-400'" />
                    <span class="truncate">{{ t.title }}</span>
                </Link>
            </div>
        </div>
    </div>
</template>
