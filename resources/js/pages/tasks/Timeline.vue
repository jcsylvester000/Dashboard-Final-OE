<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed, reactive, watch } from 'vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import WorkspaceHeader from '@/components/WorkspaceHeader.vue';
import { addDays, departmentDot, formatDate } from '@/lib/format';
import { index as workspacesIndex } from '@/routes/workspaces';
import { show as projectShow } from '@/routes/workspaces/projects';
import { show, timeline } from '@/routes/workspaces/tasks';
import type { DepartmentOption } from '@/types/admin';
import type { TaskRow } from '@/types/work';
import type { WorkspaceHeader as Header } from '@/types/workspace';

/**
 * Timeline / Gantt (B-02, S2-09): project and campaign bars, their tasks from start to due,
 * milestones as diamonds, sprint bands, a today line and "waits on" connectors.
 */
type ProjectBar = {
    id: number;
    name: string;
    type: string;
    status: string;
    start_on: string | null;
    due_on: string | null;
    tasks_total: number;
    tasks_done: number;
};

const props = defineProps<{
    workspace: Header;
    range: { from: string; to: string; weeks: number; today: string };
    filters: { project?: number | string; department?: number | string };
    projects: ProjectBar[];
    tasks: TaskRow[];
    truncated: number | null;
    dependencies: { task: number; waits_on: number }[];
    sprints: {
        id: number;
        name: string;
        status: string;
        starts_on: string;
        ends_on: string;
    }[];
    departments: DepartmentOption[];
    projectOptions: { id: number; name: string }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Workspaces', href: workspacesIndex() }],
    },
});

const ROW = 32;
const dayMs = 86400000;
const toDay = (d: string) =>
    Math.round(new Date(d + 'T00:00:00').getTime() / dayMs);
const span = computed(
    () => toDay(props.range.to) - toDay(props.range.from) + 1,
);
// Percent of the chart width for the start of a day (end = next day).
const pct = (d: string) =>
    Math.min(
        100,
        Math.max(0, ((toDay(d) - toDay(props.range.from)) / span.value) * 100),
    );
const endPct = (d: string) => pct(addDays(d, 1));

const weeksTicks = computed(() => {
    const out: string[] = [];
    for (let d = props.range.from; d <= props.range.to; d = addDays(d, 7)) {
        out.push(d);
    }

    return out;
});

type Row =
    | { kind: 'project'; project: ProjectBar }
    | { kind: 'group'; label: string }
    | { kind: 'task'; task: TaskRow };

const rows = computed<Row[]>(() => {
    const out: Row[] = [];
    const byProject = new Map<number | null, TaskRow[]>();
    for (const t of props.tasks) {
        const key = t.project?.id ?? null;
        byProject.set(key, [...(byProject.get(key) ?? []), t]);
    }
    for (const p of props.projects) {
        const list = byProject.get(p.id) ?? [];
        if (!list.length && !(p.start_on && p.due_on)) {
            continue;
        }
        out.push({ kind: 'project', project: p });
        list.forEach((task) => out.push({ kind: 'task', task }));
        byProject.delete(p.id);
    }
    const loose = [...byProject.values()].flat();
    if (loose.length) {
        out.push({ kind: 'group', label: 'No project' });
        loose.forEach((task) => out.push({ kind: 'task', task }));
    }

    return out;
});

const rowOf = computed(() => {
    const m = new Map<number, number>();
    rows.value.forEach((r, i) => r.kind === 'task' && m.set(r.task.id, i));

    return m;
});
const keyOf = computed(() => new Map(props.tasks.map((t) => [t.id, t])));

// Connector from the end of the task waited on to the start of the waiting task.
const links = computed(() =>
    props.dependencies
        .map((d) => {
            const a = keyOf.value.get(d.waits_on);
            const b = keyOf.value.get(d.task);
            const ra = rowOf.value.get(d.waits_on);
            const rb = rowOf.value.get(d.task);
            if (
                !a?.due_on ||
                !b?.due_on ||
                ra === undefined ||
                rb === undefined
            ) {
                return null;
            }

            return {
                id: `${d.waits_on}-${d.task}`,
                x1: endPct(a.due_on),
                y1: ra * ROW + ROW / 2,
                x2: pct(b.start_on ?? b.due_on),
                y2: rb * ROW + ROW / 2,
                late: (b.start_on ?? b.due_on) < a.due_on,
            };
        })
        .filter((l): l is NonNullable<typeof l> => l !== null),
);

const waitsOnLabel = (id: number) =>
    props.dependencies
        .filter((d) => d.task === id)
        .map((d) => keyOf.value.get(d.waits_on)?.key)
        .filter(Boolean)
        .join(', ');

const form = reactive({
    project: props.filters.project ? String(props.filters.project) : '',
    department: props.filters.department
        ? String(props.filters.department)
        : '',
    weeks: String(props.range.weeks),
});

function visit(from: string): void {
    const query = Object.fromEntries(
        Object.entries({ ...form, from }).filter(([, v]) => v !== ''),
    );
    router.get(
        timeline.url(props.workspace.slug, { query }),
        {},
        { preserveScroll: true, preserveState: true, replace: true },
    );
}
watch(form, () => visit(props.range.from));

const barClass = (t: TaskRow) =>
    t.status.category === 'done'
        ? 'bg-emerald-500/70'
        : t.waiting_on > 0
          ? 'bg-amber-500/80'
          : t.due_on && t.due_on < props.range.today
            ? 'bg-rose-500/80'
            : 'bg-sky-500/80';
</script>

<template>
    <Head :title="`${workspace.name} · Timeline`" />

    <div class="flex min-w-0 flex-1 flex-col gap-4 p-4">
        <WorkspaceHeader :workspace="workspace" />

        <div class="flex flex-wrap items-center gap-2">
            <Button
                variant="outline"
                size="sm"
                aria-label="Earlier"
                @click="
                    visit(
                        addDays(
                            range.from,
                            -7 * Math.max(1, Math.floor(range.weeks / 2)),
                        ),
                    )
                "
                ><ChevronLeft
            /></Button>
            <h2 class="min-w-52 text-center text-sm font-semibold">
                {{ formatDate(range.from) }} – {{ formatDate(range.to) }}
            </h2>
            <Button
                variant="outline"
                size="sm"
                aria-label="Later"
                @click="
                    visit(
                        addDays(
                            range.from,
                            7 * Math.max(1, Math.floor(range.weeks / 2)),
                        ),
                    )
                "
                ><ChevronRight
            /></Button>
            <Button
                variant="ghost"
                size="sm"
                @click="visit(addDays(range.today, -14))"
                >Today</Button
            >
            <div class="ml-auto flex flex-wrap gap-2">
                <NativeSelect
                    v-model="form.weeks"
                    class="h-8 w-28 text-xs"
                    aria-label="Zoom"
                >
                    <option value="4">4 weeks</option>
                    <option value="8">8 weeks</option>
                    <option value="13">Quarter</option>
                    <option value="26">6 months</option>
                </NativeSelect>
                <NativeSelect
                    v-model="form.project"
                    class="h-8 w-48 text-xs"
                    aria-label="Project"
                >
                    <option value="">All projects</option>
                    <option
                        v-for="p in projectOptions"
                        :key="p.id"
                        :value="String(p.id)"
                    >
                        {{ p.name }}
                    </option>
                </NativeSelect>
                <NativeSelect
                    v-model="form.department"
                    class="h-8 w-36 text-xs"
                    aria-label="Department"
                >
                    <option value="">All departments</option>
                    <option
                        v-for="d in departments"
                        :key="d.id"
                        :value="String(d.id)"
                    >
                        {{ d.name }}
                    </option>
                </NativeSelect>
            </div>
        </div>

        <p v-if="truncated" class="text-xs text-amber-700 dark:text-amber-300">
            Showing the first 300 of {{ truncated }} tasks. Filter by project or
            department to see the rest.
        </p>

        <div class="overflow-x-auto rounded-lg border">
            <div class="min-w-[900px]">
                <!-- Header: weeks + sprint bands -->
                <div
                    class="flex border-b bg-muted/40 text-[11px] text-muted-foreground"
                >
                    <div class="w-64 shrink-0 border-r px-3 py-1.5 font-medium">
                        Work
                    </div>
                    <div class="relative h-12 flex-1">
                        <span
                            v-for="w in weeksTicks"
                            :key="w"
                            class="absolute top-1 border-l pl-1"
                            :style="{ left: `${pct(w)}%` }"
                            >{{ formatDate(w) }}</span
                        >
                        <span
                            v-for="s in sprints"
                            :key="s.id"
                            class="absolute bottom-1 h-4 truncate rounded px-1 text-[10px] leading-4"
                            :class="
                                s.status === 'active'
                                    ? 'bg-emerald-200 text-emerald-900 dark:bg-emerald-900 dark:text-emerald-100'
                                    : 'bg-muted text-muted-foreground'
                            "
                            :style="{
                                left: `${pct(s.starts_on)}%`,
                                width: `${endPct(s.ends_on) - pct(s.starts_on)}%`,
                            }"
                            :title="`${s.name} · ${formatDate(s.starts_on)} – ${formatDate(s.ends_on)}`"
                            >{{ s.name }}</span
                        >
                    </div>
                </div>

                <p
                    v-if="rows.length === 0"
                    class="p-8 text-center text-sm text-muted-foreground"
                >
                    Nothing with dates in this range. Give tasks a start and due
                    date, or move the range.
                </p>

                <div v-else class="flex">
                    <!-- Labels -->
                    <ul class="w-64 shrink-0 border-r text-sm">
                        <li
                            v-for="(r, i) in rows"
                            :key="i"
                            class="flex items-center truncate px-3"
                            :style="{ height: `${ROW}px` }"
                            :class="
                                r.kind !== 'task'
                                    ? 'bg-muted/30 font-medium'
                                    : ''
                            "
                        >
                            <template v-if="r.kind === 'project'">
                                <Link
                                    :href="
                                        projectShow({
                                            workspace: workspace.slug,
                                            project: r.project.id,
                                        })
                                    "
                                    class="truncate hover:underline"
                                    >{{ r.project.name }}</Link
                                >
                                <span
                                    class="ml-auto pl-2 text-[11px] font-normal text-muted-foreground tabular-nums"
                                    >{{ r.project.tasks_done }}/{{
                                        r.project.tasks_total
                                    }}</span
                                >
                            </template>
                            <span
                                v-else-if="r.kind === 'group'"
                                class="text-muted-foreground"
                                >{{ r.label }}</span
                            >
                            <template v-else>
                                <span
                                    class="mr-1.5 size-1.5 shrink-0 rounded-full"
                                    :class="
                                        departmentDot[
                                            r.task.department?.color ?? ''
                                        ] ?? 'bg-slate-400'
                                    "
                                />
                                <Link
                                    :href="
                                        show({
                                            workspace: workspace.slug,
                                            task: r.task.id,
                                        })
                                    "
                                    class="truncate text-xs hover:underline"
                                    :title="`${r.task.key} · ${r.task.title}`"
                                >
                                    <span
                                        class="font-mono text-muted-foreground"
                                        >{{ r.task.key }}</span
                                    >
                                    {{ r.task.title }}
                                </Link>
                            </template>
                        </li>
                    </ul>

                    <!-- Bars -->
                    <div
                        class="relative flex-1"
                        :style="{ height: `${rows.length * ROW}px` }"
                    >
                        <span
                            v-for="w in weeksTicks"
                            :key="w"
                            class="absolute inset-y-0 border-l border-dashed border-border/60"
                            :style="{ left: `${pct(w)}%` }"
                        />
                        <span
                            v-if="
                                range.today >= range.from &&
                                range.today <= range.to
                            "
                            class="absolute inset-y-0 z-10 w-px bg-rose-500"
                            :style="{ left: `${pct(range.today)}%` }"
                            title="Today"
                        />
                        <svg
                            class="pointer-events-none absolute inset-0 z-10 h-full w-full"
                            :viewBox="`0 0 100 ${rows.length * ROW}`"
                            preserveAspectRatio="none"
                            aria-hidden="true"
                        >
                            <path
                                v-for="l in links"
                                :key="l.id"
                                :d="`M ${l.x1} ${l.y1} C ${l.x1 + 1.5} ${l.y1}, ${l.x2 - 1.5} ${l.y2}, ${l.x2} ${l.y2}`"
                                fill="none"
                                :class="
                                    l.late
                                        ? 'stroke-rose-500'
                                        : 'stroke-muted-foreground'
                                "
                                stroke-width="1.25"
                                vector-effect="non-scaling-stroke"
                            />
                        </svg>

                        <template v-for="(r, i) in rows" :key="i">
                            <div
                                v-if="r.kind !== 'task'"
                                class="absolute inset-x-0 bg-muted/30"
                                :style="{
                                    top: `${i * ROW}px`,
                                    height: `${ROW}px`,
                                }"
                            >
                                <span
                                    v-if="
                                        r.kind === 'project' &&
                                        r.project.start_on &&
                                        r.project.due_on
                                    "
                                    class="absolute top-2 h-4 overflow-hidden rounded bg-violet-200 dark:bg-violet-900/60"
                                    :style="{
                                        left: `${pct(r.project.start_on)}%`,
                                        width: `${Math.max(0.6, endPct(r.project.due_on) - pct(r.project.start_on))}%`,
                                    }"
                                    :title="`${r.project.name} · ${formatDate(r.project.start_on)} – ${formatDate(r.project.due_on)}`"
                                >
                                    <span
                                        class="block h-full bg-violet-500/70"
                                        :style="{
                                            width: `${r.project.tasks_total ? (r.project.tasks_done / r.project.tasks_total) * 100 : 0}%`,
                                        }"
                                    />
                                </span>
                            </div>
                            <template v-else-if="r.task.due_on">
                                <span
                                    v-if="r.task.type === 'milestone'"
                                    class="absolute z-20 size-3.5 rotate-45 border-2 border-violet-700 bg-violet-400"
                                    :style="{
                                        top: `${i * ROW + 9}px`,
                                        left: `calc(${endPct(r.task.due_on)}% - 7px)`,
                                    }"
                                    :title="`Milestone · ${r.task.title} · ${formatDate(r.task.due_on)} · ${r.task.status.name}`"
                                    role="img"
                                    :aria-label="`Milestone ${r.task.title} on ${formatDate(r.task.due_on)}`"
                                />
                                <Link
                                    v-else
                                    :href="
                                        show({
                                            workspace: workspace.slug,
                                            task: r.task.id,
                                        })
                                    "
                                    class="absolute z-20 flex h-5 items-center overflow-hidden rounded px-1.5 text-[10px] whitespace-nowrap text-white"
                                    :class="barClass(r.task)"
                                    :style="{
                                        top: `${i * ROW + 6}px`,
                                        left: `${pct(r.task.start_on ?? r.task.due_on)}%`,
                                        width: `${Math.max(1.2, endPct(r.task.due_on) - pct(r.task.start_on ?? r.task.due_on))}%`,
                                    }"
                                    :title="`${r.task.key} · ${r.task.title} · ${r.task.status.name} · ${formatDate(r.task.start_on ?? r.task.due_on)} → ${formatDate(r.task.due_on)}${waitsOnLabel(r.task.id) ? ' · waits on ' + waitsOnLabel(r.task.id) : ''}`"
                                    >{{ r.task.assignee?.name ?? '' }}</Link
                                >
                            </template>
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <div class="flex flex-wrap gap-4 text-xs text-muted-foreground">
            <span class="inline-flex items-center gap-1"
                ><span class="h-2.5 w-5 rounded bg-sky-500/80" /> Planned</span
            >
            <span class="inline-flex items-center gap-1"
                ><span class="h-2.5 w-5 rounded bg-amber-500/80" /> Waiting on
                other work</span
            >
            <span class="inline-flex items-center gap-1"
                ><span class="h-2.5 w-5 rounded bg-rose-500/80" /> Overdue</span
            >
            <span class="inline-flex items-center gap-1"
                ><span class="h-2.5 w-5 rounded bg-emerald-500/70" /> Done</span
            >
            <span class="inline-flex items-center gap-1"
                ><span class="size-2.5 rotate-45 bg-violet-400" />
                Milestone</span
            >
            <span class="inline-flex items-center gap-1"
                ><span class="h-2.5 w-5 rounded bg-violet-200" /> Project
                (filled = % done)</span
            >
            <span
                >Lines join a task to the work it waits on (red = scheduled to
                start before it finishes).</span
            >
        </div>
    </div>
</template>
