<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import WorkspaceHeader from '@/components/WorkspaceHeader.vue';
import { addDays, departmentDot, formatDate, localToday } from '@/lib/format';
import { index as workspacesIndex } from '@/routes/workspaces';
import { calendar, show } from '@/routes/workspaces/tasks';
import type { DepartmentOption } from '@/types/admin';
import type { TaskRow } from '@/types/work';
import type {
    MemberOption,
    WorkspaceHeader as Header,
} from '@/types/workspace';

/**
 * Month / week calendar (S1-17). Tasks with a start date show on every day from
 * start to due; the due day is marked. Titles wrap to two lines (full title on hover).
 */
const props = defineProps<{
    workspace: Header;
    view: 'month' | 'week';
    month: string;
    gridStart: string;
    gridEnd: string;
    filters: {
        department?: number | string;
        project?: number | string;
        assignee?: string;
    };
    tasks: TaskRow[];
    members: MemberOption[];
    departments: DepartmentOption[];
    projects: { id: number; name: string }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Workspaces', href: workspacesIndex() }],
    },
});

const today = localToday();

const days = computed(() => {
    const out: string[] = [];
    for (let d = props.gridStart; d <= props.gridEnd; d = addDays(d, 1)) {
        out.push(d);
    }

    return out;
});

type Cell = { task: TaskRow; isStart: boolean; isDue: boolean };

// Month view starts with due dates only (long-running work would fill every day); bars are opt-in.
const showBars = ref(props.view === 'week');
watch(
    () => props.view,
    (v) => (showBars.value = v === 'week'),
);

// Each task appears on every day of its start..due span inside the grid.
const byDay = computed(() => {
    const map: Record<string, Cell[]> = {};
    for (const t of props.tasks) {
        if (!t.due_on) {
            continue;
        }
        const start =
            showBars.value && t.start_on && t.start_on < t.due_on
                ? t.start_on
                : t.due_on;
        const from = start < props.gridStart ? props.gridStart : start;
        const to = t.due_on > props.gridEnd ? props.gridEnd : t.due_on;
        for (let d = from; d <= to; d = addDays(d, 1)) {
            (map[d] ??= []).push({
                task: t,
                isStart: d === start,
                isDue: d === t.due_on,
            });
        }
    }
    // Longer bars first so they line up at the top of each day.
    for (const list of Object.values(map)) {
        list.sort(
            (a, b) =>
                (a.task.start_on ?? a.task.due_on ?? '').localeCompare(
                    b.task.start_on ?? b.task.due_on ?? '',
                ) || a.task.id - b.task.id,
        );
    }

    return map;
});

const title = computed(() => {
    if (props.view === 'week') {
        return `${formatDate(props.gridStart)} – ${formatDate(props.gridEnd)}`;
    }
    const [y, m] = props.month.split('-').map(Number);

    return new Date(y, m - 1, 1).toLocaleDateString(undefined, {
        month: 'long',
        year: 'numeric',
    });
});

const form = reactive({
    department: props.filters.department
        ? String(props.filters.department)
        : '',
    project: props.filters.project ? String(props.filters.project) : '',
    assignee: props.filters.assignee ?? '',
});

function visit(extra: Record<string, string>): void {
    const query = Object.fromEntries(
        Object.entries({ ...form, ...extra }).filter(([, v]) => v !== ''),
    );
    router.get(
        calendar.url(props.workspace.slug, { query }),
        {},
        { preserveScroll: true, preserveState: true, replace: true },
    );
}

const place = (): Record<string, string> =>
    props.view === 'week'
        ? { view: 'week', date: props.gridStart }
        : { month: props.month };

watch(form, () => visit(place()));

function shift(step: number): void {
    if (props.view === 'week') {
        visit({ view: 'week', date: addDays(props.gridStart, 7 * step) });

        return;
    }
    const [y, m] = props.month.split('-').map(Number);
    const d = new Date(Date.UTC(y, m - 1 + step, 1));
    visit({ month: d.toISOString().slice(0, 7) });
}

function setView(v: 'month' | 'week'): void {
    const inMonth =
        today.slice(0, 7) === props.month ? today : `${props.month}-01`;
    visit(
        v === 'week'
            ? {
                  view: 'week',
                  date: props.view === 'week' ? props.gridStart : inMonth,
              }
            : { month: props.month },
    );
}

const weekdays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
const label = (c: Cell) =>
    `${c.task.key} · ${c.task.title} · ${c.task.status.name}${c.task.assignee ? ' · ' + c.task.assignee.name : ''}${c.task.start_on && c.task.start_on !== c.task.due_on ? ` · ${formatDate(c.task.start_on)} → ${formatDate(c.task.due_on)}` : ''}`;
</script>

<template>
    <Head :title="`${workspace.name} · Calendar`" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <WorkspaceHeader :workspace="workspace" />

        <div class="flex flex-wrap items-center gap-2">
            <Button
                variant="outline"
                size="sm"
                :aria-label="
                    view === 'week' ? 'Previous week' : 'Previous month'
                "
                @click="shift(-1)"
                ><ChevronLeft
            /></Button>
            <h2 class="min-w-44 text-center font-semibold">{{ title }}</h2>
            <Button
                variant="outline"
                size="sm"
                :aria-label="view === 'week' ? 'Next week' : 'Next month'"
                @click="shift(1)"
                ><ChevronRight
            /></Button>
            <div
                class="ml-2 inline-flex rounded-md border p-0.5 text-xs"
                role="group"
                aria-label="Calendar view"
            >
                <button
                    type="button"
                    class="rounded px-2 py-1"
                    :class="
                        view === 'month'
                            ? 'bg-accent font-medium'
                            : 'text-muted-foreground'
                    "
                    :aria-pressed="view === 'month'"
                    @click="setView('month')"
                >
                    Month
                </button>
                <button
                    type="button"
                    class="rounded px-2 py-1"
                    :class="
                        view === 'week'
                            ? 'bg-accent font-medium'
                            : 'text-muted-foreground'
                    "
                    :aria-pressed="view === 'week'"
                    @click="setView('week')"
                >
                    Week
                </button>
            </div>
            <label
                v-if="view === 'month'"
                class="ml-2 inline-flex items-center gap-1 text-xs"
            >
                <input v-model="showBars" type="checkbox" class="size-3.5" />
                Show start → due bars
            </label>
            <div class="ml-auto flex flex-wrap gap-2">
                <NativeSelect
                    v-model="form.assignee"
                    class="h-8 w-36 text-xs"
                    aria-label="Person"
                >
                    <option value="">Anyone</option>
                    <option value="me">Me</option>
                    <option
                        v-for="m in members"
                        :key="m.id"
                        :value="String(m.id)"
                    >
                        {{ m.name }}
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
                <NativeSelect
                    v-if="projects.length"
                    v-model="form.project"
                    class="h-8 w-44 text-xs"
                    aria-label="Project"
                >
                    <option value="">All projects</option>
                    <option
                        v-for="p in projects"
                        :key="p.id"
                        :value="String(p.id)"
                    >
                        {{ p.name }}
                    </option>
                </NativeSelect>
            </div>
        </div>

        <div class="overflow-x-auto rounded-lg border">
            <div class="grid min-w-[40rem] grid-cols-7 text-xs">
                <div
                    v-for="(w, i) in weekdays"
                    :key="w"
                    class="border-b bg-muted/50 px-2 py-1 font-medium text-muted-foreground"
                >
                    {{ w
                    }}<template v-if="view === 'week'">
                        {{ Number(days[i]?.slice(8)) }}</template
                    >
                </div>
                <div
                    v-for="d in days"
                    :key="d"
                    class="border-r border-b p-1 last:border-r-0"
                    :class="[
                        view === 'week' ? 'min-h-96' : 'min-h-28',
                        {
                            'bg-muted/30':
                                view === 'month' && d.slice(0, 7) !== month,
                        },
                    ]"
                >
                    <div
                        v-if="view === 'month'"
                        class="mb-1 text-right"
                        :class="
                            d === today
                                ? 'font-bold text-primary'
                                : 'text-muted-foreground'
                        "
                    >
                        {{ Number(d.slice(8)) }}
                    </div>
                    <div
                        v-else-if="d === today"
                        class="mb-1 text-right font-bold text-primary"
                    >
                        Today
                    </div>
                    <template v-for="c in byDay[d] ?? []" :key="c.task.id">
                        <Link
                            prefetch
                            v-if="
                                !c.isStart &&
                                !c.isDue &&
                                d !== gridStart &&
                                !d.endsWith('-01')
                            "
                            :href="
                                show({
                                    workspace: workspace.slug,
                                    task: c.task.id,
                                })
                            "
                            class="mb-1 block h-1.5 bg-sky-300/70 dark:bg-sky-700/60"
                            :title="label(c)"
                            :aria-label="label(c)"
                        />
                        <Link
                            prefetch
                            v-else
                            :href="
                                show({
                                    workspace: workspace.slug,
                                    task: c.task.id,
                                })
                            "
                            class="mb-1 flex items-start gap-1 px-1 py-0.5 hover:brightness-95"
                            :class="[
                                c.isDue ? 'rounded-r' : '',
                                c.isStart ? 'rounded-l' : '',
                                c.task.start_on &&
                                c.task.start_on !== c.task.due_on
                                    ? 'bg-sky-100/70 dark:bg-sky-900/30'
                                    : 'hover:bg-accent',
                                {
                                    'text-muted-foreground line-through':
                                        c.task.status.category === 'done',
                                    'font-medium text-rose-600':
                                        c.isDue &&
                                        c.task.due_on! < today &&
                                        c.task.status.category !== 'done',
                                },
                            ]"
                            :title="label(c)"
                            :aria-label="label(c)"
                        >
                            <span
                                class="mt-1 size-1.5 shrink-0 rounded-full"
                                :class="
                                    departmentDot[
                                        c.task.department?.color ?? ''
                                    ] ?? 'bg-slate-400'
                                "
                            />
                            <span
                                :class="
                                    view === 'week'
                                        ? 'whitespace-normal'
                                        : 'line-clamp-2'
                                "
                            >
                                {{ c.task.title }}
                                <span
                                    v-if="
                                        c.isDue && c.task.type === 'milestone'
                                    "
                                    class="ml-1 text-violet-700"
                                    >◆</span
                                >
                            </span>
                        </Link>
                    </template>
                </div>
            </div>
        </div>
        <p class="text-xs text-muted-foreground">
            Month view lists tasks on their due date; tick "Show start → due
            bars" (or use Week) to see spans. Set a start date on the task page.
        </p>
    </div>
</template>
