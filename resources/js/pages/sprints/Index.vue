<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Play, Plus, Square, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import LabelBadge from '@/components/LabelBadge.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import WorkspaceHeader from '@/components/WorkspaceHeader.vue';
import Burndown from '@/components/work/Burndown.vue';
import TaskMeta from '@/components/work/TaskMeta.vue';
import { confirmDialog } from '@/composables/useConfirm';
import { addDays, formatDate, localToday } from '@/lib/format';
import { index as workspacesIndex } from '@/routes/workspaces';
import { close, destroy, start, store } from '@/routes/workspaces/sprints';
import { show, update as updateTask } from '@/routes/workspaces/tasks';
import type { TaskRow } from '@/types/work';
import type { WorkspaceHeader as Header } from '@/types/workspace';

type SprintRow = {
    id: number;
    name: string;
    goal: string | null;
    status: 'planned' | 'active' | 'closed';
    starts_on: string;
    ends_on: string;
    project: { id: number; name: string } | null;
    committed_points: number | null;
    completed_points: number | null;
    carried_over: number | null;
};

const props = defineProps<{
    workspace: Header;
    sprints: (SprintRow & {
        points: number;
        done_points: number;
        done_count: number;
        tasks: TaskRow[];
    })[];
    burndown: {
        unit: string;
        scope: number;
        days: { date: string; ideal: number; remaining: number | null }[];
    } | null;
    backlog: TaskRow[];
    closed: SprintRow[];
    projects: { id: number; name: string }[];
    pointScale: number[];
    canManage: boolean;
    canPlan: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Workspaces', href: workspacesIndex() }],
    },
});

const ws = computed(() => props.workspace.slug);
const active = computed(
    () => props.sprints.find((s) => s.status === 'active') ?? null,
);
const planned = computed(() =>
    props.sprints.filter((s) => s.status === 'planned'),
);
// Backlog scope: defaults to the active/next sprint's project so marketing or SEO work on the same client doesn't crowd it.
const sprintProject = (active.value ?? planned.value[0])?.project?.id ?? null;
const backlogProject = ref<string>(sprintProject ? String(sprintProject) : '');
const backlogDept = ref<string>('');
const departmentsInBacklog = computed(() => [
    ...new Map(
        props.backlog
            .filter((t) => t.department)
            .map((t) => [t.department!.id, t.department!.name]),
    ).entries(),
]);
const visibleBacklog = computed(() =>
    props.backlog.filter(
        (t) =>
            (backlogProject.value === '' ||
                (backlogProject.value === 'none'
                    ? !t.project
                    : String(t.project?.id) === backlogProject.value)) &&
            (backlogDept.value === '' ||
                String(t.department?.id) === backlogDept.value),
    ),
);
const backlogPoints = computed(() =>
    visibleBacklog.value.reduce((n, t) => n + (t.story_points ?? 0), 0),
);
const velocity = computed(() => {
    const done = props.closed
        .filter((s) => s.completed_points !== null)
        .slice(0, 3);

    return done.length
        ? Math.round(
              done.reduce((n, s) => n + (s.completed_points ?? 0), 0) /
                  done.length,
          )
        : null;
});

// New sprint: defaults to 2 weeks starting the day after the last sprint ends (or today).
const nextStart = () => {
    const lastEnd = [...props.sprints]
        .map((s) => s.ends_on)
        .sort()
        .pop();

    return lastEnd ? addDays(lastEnd, 1) : localToday();
};
const showNew = ref(false);
const form = useForm({
    name: `Sprint ${props.closed.length + props.sprints.length + 1}`,
    goal: '',
    starts_on: nextStart(),
    ends_on: addDays(nextStart(), 13),
    project_id: '',
});

function create(): void {
    form.transform((d) => ({
        ...d,
        project_id: d.project_id ? Number(d.project_id) : null,
    })).post(store.url(ws.value), {
        preserveScroll: true,
        onSuccess: () => {
            showNew.value = false;
            form.reset();
            form.name = `Sprint ${props.closed.length + props.sprints.length + 1}`;
            form.starts_on = nextStart();
            form.ends_on = addDays(form.starts_on, 13);
        },
    });
}

function setTask(
    task: TaskRow,
    field: 'sprint_id' | 'story_points',
    value: string,
): void {
    router.put(
        updateTask.url({ workspace: ws.value, task: task.id }),
        { [field]: value === '' ? null : Number(value) },
        { preserveScroll: true },
    );
}

function startSprint(id: number): void {
    router.post(
        start.url({ workspace: ws.value, sprint: id }),
        {},
        { preserveScroll: true },
    );
}

const closing = ref(false);
const carryTo = ref('');
function closeSprint(): void {
    if (!active.value) {
        return;
    }
    router.post(
        close.url({ workspace: ws.value, sprint: active.value.id }),
        { next_sprint_id: carryTo.value ? Number(carryTo.value) : null },
        {
            preserveScroll: true,
            onSuccess: () => (closing.value = false),
        },
    );
}

async function removeSprint(s: SprintRow): Promise<void> {
    if (
        await confirmDialog(
            `Delete ${s.name}? Its tasks go back to the backlog.`,
        )
    ) {
        router.delete(destroy.url({ workspace: ws.value, sprint: s.id }), {
            preserveScroll: true,
        });
    }
}

const daysLeft = (end: string) =>
    Math.max(
        0,
        Math.round(
            (new Date(end + 'T00:00:00').getTime() -
                new Date(localToday() + 'T00:00:00').getTime()) /
                86400000,
        ),
    );
</script>

<template>
    <Head :title="`${workspace.name} · Sprints`" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <WorkspaceHeader :workspace="workspace" />

        <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="text-sm text-muted-foreground">
                Plan work in time-boxed sprints.
                <template v-if="velocity !== null"
                    >Recent velocity:
                    <strong class="text-foreground"
                        >{{ velocity }} points</strong
                    >
                    per sprint.</template
                >
            </p>
            <Button
                v-if="canManage"
                size="sm"
                variant="outline"
                @click="showNew = !showNew"
                ><Plus /> New sprint</Button
            >
        </div>

        <Card v-if="showNew && canManage">
            <CardHeader
                ><CardTitle class="text-base">New sprint</CardTitle></CardHeader
            >
            <CardContent>
                <form
                    class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5"
                    @submit.prevent="create"
                >
                    <label class="grid gap-1 text-sm">
                        <span class="text-xs text-muted-foreground">Name</span>
                        <Input v-model="form.name" required maxlength="80" />
                        <InputError :message="form.errors.name" />
                    </label>
                    <label class="grid gap-1 text-sm lg:col-span-2">
                        <span class="text-xs text-muted-foreground"
                            >Goal (optional)</span
                        >
                        <Input
                            v-model="form.goal"
                            maxlength="500"
                            placeholder="e.g. Customers can pay with GCash"
                        />
                    </label>
                    <label class="grid gap-1 text-sm">
                        <span class="text-xs text-muted-foreground"
                            >Starts</span
                        >
                        <Input v-model="form.starts_on" type="date" required />
                    </label>
                    <label class="grid gap-1 text-sm">
                        <span class="text-xs text-muted-foreground">Ends</span>
                        <Input v-model="form.ends_on" type="date" required />
                        <InputError :message="form.errors.ends_on" />
                    </label>
                    <label class="grid gap-1 text-sm">
                        <span class="text-xs text-muted-foreground"
                            >Project (optional)</span
                        >
                        <NativeSelect v-model="form.project_id">
                            <option value="">Any project</option>
                            <option
                                v-for="p in projects"
                                :key="p.id"
                                :value="String(p.id)"
                            >
                                {{ p.name }}
                            </option>
                        </NativeSelect>
                    </label>
                    <div class="flex items-end gap-2 lg:col-span-4">
                        <Button type="submit" :disabled="form.processing"
                            >Create sprint</Button
                        >
                        <Button
                            type="button"
                            variant="ghost"
                            @click="showNew = false"
                            >Cancel</Button
                        >
                    </div>
                </form>
            </CardContent>
        </Card>

        <!-- Active sprint -->
        <Card v-if="active">
            <CardHeader>
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <CardTitle class="text-base"
                            >{{ active.name }}
                            <span
                                class="ml-1 rounded bg-emerald-100 px-1.5 py-0.5 text-xs font-normal text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200"
                                >active</span
                            ></CardTitle
                        >
                        <CardDescription>
                            {{ formatDate(active.starts_on) }} –
                            {{ formatDate(active.ends_on) }} ·
                            {{ daysLeft(active.ends_on) }} days left ·
                            {{ active.done_points }} /
                            {{ active.points }} points done ({{
                                active.done_count
                            }}
                            of {{ active.tasks.length }} tasks)
                            <template
                                v-if="
                                    active.committed_points !== null &&
                                    active.points !== active.committed_points
                                "
                            >
                                · scope changed from
                                {{ active.committed_points }}</template
                            >
                        </CardDescription>
                        <p v-if="active.goal" class="mt-1 text-sm">
                            Goal: {{ active.goal }}
                        </p>
                    </div>
                    <Button
                        v-if="canManage"
                        size="sm"
                        variant="outline"
                        @click="closing = !closing"
                        ><Square /> Close sprint</Button
                    >
                </div>
                <div
                    v-if="closing"
                    class="mt-2 flex flex-wrap items-center gap-2 rounded-md border bg-muted/40 p-2 text-sm"
                >
                    <span
                        >{{
                            active.tasks.length - active.done_count
                        }}
                        unfinished task(s) move to</span
                    >
                    <NativeSelect
                        v-model="carryTo"
                        class="h-8 w-48"
                        aria-label="Carry unfinished tasks to"
                    >
                        <option value="">The backlog</option>
                        <option
                            v-for="p in planned"
                            :key="p.id"
                            :value="String(p.id)"
                        >
                            {{ p.name }}
                        </option>
                    </NativeSelect>
                    <Button size="sm" @click="closeSprint"
                        >Close {{ active.name }}</Button
                    >
                    <Button size="sm" variant="ghost" @click="closing = false"
                        >Cancel</Button
                    >
                </div>
            </CardHeader>
            <CardContent class="grid gap-4 lg:grid-cols-2">
                <Burndown v-if="burndown" :data="burndown" />
                <ul class="divide-y rounded-md border text-sm">
                    <li
                        v-for="t in active.tasks"
                        :key="t.id"
                        class="flex items-start justify-between gap-2 px-3 py-2"
                    >
                        <div class="min-w-0 space-y-0.5">
                            <Link
                                :href="show({ workspace: ws, task: t.id })"
                                class="font-medium hover:underline"
                                :class="{
                                    'text-muted-foreground line-through':
                                        t.status.category === 'done',
                                }"
                                >{{ t.title }}</Link
                            >
                            <TaskMeta :task="{ ...t, sprint: null }" />
                        </div>
                        <LabelBadge
                            :name="t.status.name"
                            :color="t.status.color"
                        />
                    </li>
                </ul>
            </CardContent>
        </Card>

        <!-- Planned sprints -->
        <Card v-for="s in planned" :key="s.id">
            <CardHeader>
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <CardTitle class="text-base"
                            >{{ s.name }}
                            <span
                                class="ml-1 rounded bg-muted px-1.5 py-0.5 text-xs font-normal text-muted-foreground"
                                >planned</span
                            ></CardTitle
                        >
                        <CardDescription>
                            {{ formatDate(s.starts_on) }} –
                            {{ formatDate(s.ends_on) }} ·
                            {{ s.tasks.length }} tasks · {{ s.points }} points
                            <template
                                v-if="
                                    velocity !== null &&
                                    s.points > velocity * 1.2
                                "
                            >
                                ·
                                <span class="text-amber-700 dark:text-amber-300"
                                    >above recent velocity ({{
                                        velocity
                                    }})</span
                                ></template
                            >
                            <template v-if="s.project">
                                · {{ s.project.name }}</template
                            >
                        </CardDescription>
                        <p v-if="s.goal" class="mt-1 text-sm">
                            Goal: {{ s.goal }}
                        </p>
                    </div>
                    <div v-if="canManage" class="flex gap-2">
                        <Button
                            size="sm"
                            :disabled="!!active || s.tasks.length === 0"
                            :title="
                                active
                                    ? 'Close the active sprint first'
                                    : s.tasks.length === 0
                                      ? 'Add tasks first'
                                      : ''
                            "
                            @click="startSprint(s.id)"
                            ><Play /> Start</Button
                        >
                        <Button
                            size="sm"
                            variant="ghost"
                            aria-label="Delete sprint"
                            @click="removeSprint(s)"
                            ><Trash2
                        /></Button>
                    </div>
                </div>
            </CardHeader>
            <CardContent>
                <p
                    v-if="s.tasks.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    Empty. Move tasks in from the backlog below.
                </p>
                <ul v-else class="divide-y rounded-md border text-sm">
                    <li
                        v-for="t in s.tasks"
                        :key="t.id"
                        class="flex flex-wrap items-center justify-between gap-2 px-3 py-2"
                    >
                        <div class="min-w-0 space-y-0.5">
                            <Link
                                :href="show({ workspace: ws, task: t.id })"
                                class="font-medium hover:underline"
                                >{{ t.title }}</Link
                            >
                            <TaskMeta :task="{ ...t, sprint: null }" />
                        </div>
                        <div v-if="canPlan" class="flex items-center gap-1">
                            <NativeSelect
                                :model-value="t.story_points ?? ''"
                                class="h-8 w-20"
                                :aria-label="`Points for ${t.key}`"
                                @update:model-value="
                                    (v) => setTask(t, 'story_points', String(v))
                                "
                            >
                                <option value="">pts</option>
                                <option
                                    v-for="p in [0, ...pointScale]"
                                    :key="p"
                                    :value="p"
                                >
                                    {{ p }}
                                </option>
                            </NativeSelect>
                            <Button
                                size="sm"
                                variant="ghost"
                                @click="setTask(t, 'sprint_id', '')"
                                >Remove</Button
                            >
                        </div>
                    </li>
                </ul>
            </CardContent>
        </Card>

        <!-- Backlog -->
        <Card>
            <CardHeader>
                <CardTitle class="text-base"
                    >Backlog
                    <span class="text-sm font-normal text-muted-foreground"
                        >· {{ visibleBacklog.length }} of
                        {{ backlog.length }} open tasks ·
                        {{ backlogPoints }} points</span
                    ></CardTitle
                >
                <CardDescription
                    >Open tasks not in a sprint, most urgent first. Estimate
                    with points, then move them into a sprint.</CardDescription
                >
                <div class="flex flex-wrap gap-2 pt-2">
                    <NativeSelect
                        v-model="backlogProject"
                        class="h-8 w-56"
                        aria-label="Backlog project"
                    >
                        <option value="">All projects</option>
                        <option value="none">No project</option>
                        <option
                            v-for="p in projects"
                            :key="p.id"
                            :value="String(p.id)"
                        >
                            {{ p.name }}
                        </option>
                    </NativeSelect>
                    <NativeSelect
                        v-model="backlogDept"
                        class="h-8 w-44"
                        aria-label="Backlog department"
                    >
                        <option value="">All departments</option>
                        <option
                            v-for="[id, name] in departmentsInBacklog"
                            :key="id"
                            :value="String(id)"
                        >
                            {{ name }}
                        </option>
                    </NativeSelect>
                </div>
            </CardHeader>
            <CardContent>
                <p
                    v-if="visibleBacklog.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    {{
                        backlog.length === 0
                            ? 'The backlog is empty.'
                            : 'Nothing in the backlog matches these filters.'
                    }}
                </p>
                <ul v-else class="divide-y rounded-md border text-sm">
                    <li
                        v-for="t in visibleBacklog"
                        :key="t.id"
                        class="flex flex-wrap items-center justify-between gap-2 px-3 py-2"
                    >
                        <div class="min-w-0 space-y-0.5">
                            <Link
                                :href="show({ workspace: ws, task: t.id })"
                                class="font-medium hover:underline"
                                >{{ t.title }}</Link
                            >
                            <TaskMeta :task="t" />
                        </div>
                        <div v-if="canPlan" class="flex items-center gap-1">
                            <NativeSelect
                                :model-value="t.story_points ?? ''"
                                class="h-8 w-20"
                                :aria-label="`Points for ${t.key}`"
                                @update:model-value="
                                    (v) => setTask(t, 'story_points', String(v))
                                "
                            >
                                <option value="">pts</option>
                                <option
                                    v-for="p in [0, ...pointScale]"
                                    :key="p"
                                    :value="p"
                                >
                                    {{ p }}
                                </option>
                            </NativeSelect>
                            <NativeSelect
                                v-if="sprints.length"
                                model-value=""
                                class="h-8 w-40"
                                :aria-label="`Move ${t.key} to sprint`"
                                @update:model-value="
                                    (v) =>
                                        v && setTask(t, 'sprint_id', String(v))
                                "
                            >
                                <option value="">Move to sprint…</option>
                                <option
                                    v-for="sp in sprints"
                                    :key="sp.id"
                                    :value="sp.id"
                                >
                                    {{ sp.name }}
                                </option>
                            </NativeSelect>
                        </div>
                    </li>
                </ul>
            </CardContent>
        </Card>

        <!-- Sprint report -->
        <Card v-if="closed.length">
            <CardHeader>
                <CardTitle class="text-base">Sprint report</CardTitle>
                <CardDescription
                    >Committed at start vs completed at close. Velocity is the
                    average completed over the last 3 sprints.</CardDescription
                >
            </CardHeader>
            <CardContent class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs text-muted-foreground">
                        <tr>
                            <th class="py-1.5 font-medium">Sprint</th>
                            <th class="py-1.5 font-medium">Dates</th>
                            <th class="py-1.5 text-right font-medium">
                                Committed
                            </th>
                            <th class="py-1.5 text-right font-medium">
                                Completed
                            </th>
                            <th class="py-1.5 text-right font-medium">
                                Carried over
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="s in closed" :key="s.id" class="border-t">
                            <td class="py-1.5">{{ s.name }}</td>
                            <td class="py-1.5 text-muted-foreground">
                                {{ formatDate(s.starts_on) }} –
                                {{ formatDate(s.ends_on) }}
                            </td>
                            <td class="py-1.5 text-right tabular-nums">
                                {{ s.committed_points ?? '—' }}
                            </td>
                            <td class="py-1.5 text-right tabular-nums">
                                {{ s.completed_points ?? '—' }}
                            </td>
                            <td class="py-1.5 text-right tabular-nums">
                                {{ s.carried_over ?? 0 }} tasks
                            </td>
                        </tr>
                    </tbody>
                </table>
            </CardContent>
        </Card>
    </div>
</template>
