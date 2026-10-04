<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, Lock } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { addDays, formatMinutes } from '@/lib/format';
import { timesheet } from '@/routes/time';
import { show } from '@/routes/workspaces/tasks';
import type { TimeRow } from '@/types/time';

const props = defineProps<{
    week: { start: string; end: string };
    entries: TimeRow[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'My timesheet', href: timesheet() }],
    },
});

const days = computed(() => Array.from({ length: 7 }, (_, i) => addDays(props.week.start, i)));

type Row = {
    key: string;
    task: TimeRow['task'];
    workspace: TimeRow['workspace'];
    perDay: Record<string, number>;
    total: number;
    locked: boolean;
};

const rows = computed<Row[]>(() => {
    const map = new Map<string, Row>();
    for (const e of props.entries) {
        const key = `${e.workspace.id}-${e.task?.id ?? 'none'}`;
        const row = map.get(key) ?? { key, task: e.task, workspace: e.workspace, perDay: {}, total: 0, locked: true };
        row.perDay[e.entry_date] = (row.perDay[e.entry_date] ?? 0) + e.minutes;
        row.total += e.minutes;
        row.locked = row.locked && e.locked;
        map.set(key, row);
    }

    return [...map.values()].sort((a, b) => b.total - a.total);
});

const dayTotals = computed(() =>
    Object.fromEntries(days.value.map((d) => [d, props.entries.filter((e) => e.entry_date === d).reduce((s, e) => s + e.minutes, 0)])),
);
const weekTotal = computed(() => props.entries.reduce((s, e) => s + e.minutes, 0));
const billable = computed(() => props.entries.filter((e) => e.is_billable).reduce((s, e) => s + e.minutes, 0));
const approved = computed(() => props.entries.filter((e) => e.approved).reduce((s, e) => s + e.minutes, 0));

function go(offset: number): void {
    router.get(timesheet.url({ query: { week: addDays(props.week.start, offset) } }), {}, { preserveScroll: true });
}

function dayLabel(d: string): string {
    const [y, m, day] = d.split('-').map(Number);

    return new Date(y, m - 1, day).toLocaleDateString(undefined, { weekday: 'short', day: 'numeric' });
}
</script>

<template>
    <Head title="My timesheet" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">My timesheet</h1>
                <p class="text-sm text-muted-foreground">
                    {{ formatMinutes(weekTotal) }} this week · {{ formatMinutes(billable) }} billable ·
                    {{ formatMinutes(approved) }} approved
                </p>
            </div>
            <div class="flex items-center gap-2">
                <Button variant="outline" size="sm" aria-label="Previous week" @click="go(-7)"><ChevronLeft /></Button>
                <span class="text-sm">{{ week.start }} – {{ week.end }}</span>
                <Button variant="outline" size="sm" aria-label="Next week" @click="go(7)"><ChevronRight /></Button>
            </div>
        </div>

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-xs text-muted-foreground">
                    <tr>
                        <th class="px-3 py-2 text-left font-medium">Task</th>
                        <th v-for="d in days" :key="d" class="px-2 py-2 text-right font-medium whitespace-nowrap">{{ dayLabel(d) }}</th>
                        <th class="px-3 py-2 text-right font-medium">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-if="rows.length === 0">
                        <td :colspan="days.length + 2" class="px-3 py-8 text-center text-muted-foreground">
                            No time logged this week. Start a timer or use “Log time” on any task.
                        </td>
                    </tr>
                    <tr v-for="r in rows" :key="r.key">
                        <td class="px-3 py-2">
                            <Link
                                v-if="r.task"
                                :href="show({ workspace: r.workspace.slug, task: r.task.id })"
                                class="font-medium hover:underline"
                                >{{ r.task.title }}</Link
                            >
                            <span v-else class="text-muted-foreground">No task</span>
                            <span class="block text-xs text-muted-foreground">
                                {{ r.workspace.name }}
                                <Lock v-if="r.locked" class="inline size-3" aria-label="Approved" />
                            </span>
                        </td>
                        <td v-for="d in days" :key="d" class="px-2 py-2 text-right tabular-nums">
                            {{ r.perDay[d] ? formatMinutes(r.perDay[d]) : '' }}
                        </td>
                        <td class="px-3 py-2 text-right font-medium tabular-nums">{{ formatMinutes(r.total) }}</td>
                    </tr>
                </tbody>
                <tfoot v-if="rows.length" class="border-t bg-muted/30 text-xs">
                    <tr>
                        <td class="px-3 py-2 font-medium">Day total</td>
                        <td v-for="d in days" :key="d" class="px-2 py-2 text-right tabular-nums">{{ formatMinutes(dayTotals[d]) }}</td>
                        <td class="px-3 py-2 text-right font-semibold tabular-nums">{{ formatMinutes(weekTotal) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <p class="text-xs text-muted-foreground">
            Approved entries are locked. To change one, ask the workspace lead to un-approve it first.
        </p>
    </div>
</template>
