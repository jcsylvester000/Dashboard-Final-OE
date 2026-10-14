<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import TaskController from '@/actions/App/Http/Controllers/Work/TaskController';
import WorkspaceHeader from '@/components/WorkspaceHeader.vue';
import QuickTaskForm from '@/components/work/QuickTaskForm.vue';
import SavedViews from '@/components/work/SavedViews.vue';
import TaskFilterBar from '@/components/work/TaskFilterBar.vue';
import TaskMeta from '@/components/work/TaskMeta.vue';
import { avatarColor, getInitials } from '@/composables/useInitials';
import { chipColor, formatMinutes } from '@/lib/format';
import { index as workspacesIndex } from '@/routes/workspaces';
import { board, show } from '@/routes/workspaces/tasks';
import type { TaskFilters, TaskPageShared, TaskRow } from '@/types/work';

const props = defineProps<
    TaskPageShared & {
        tasks: TaskRow[];
        filters: TaskFilters;
        columnTotals?: Record<number, number>;
        perColumn?: number;
    }
>();

// B-21: each column ships its first perColumn cards; "Show more" raises the cap.
const totalFor = (statusId: number, shown: number): number =>
    Math.max(shown, props.columnTotals?.[statusId] ?? shown);
function showMore(): void {
    router.reload({
        data: { per_column: (props.perColumn ?? 50) + 100 },
        only: ['tasks', 'columnTotals', 'perColumn'],
    });
}

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Workspaces', href: workspacesIndex() }],
    },
});

// Local copy so moves show instantly (optimistic) and roll back on error.
const items = ref<TaskRow[]>([...props.tasks]);
watch(
    () => props.tasks,
    (value) => (items.value = [...value]),
);

const columns = computed(() =>
    props.statuses.map((status) => ({
        status,
        tasks: items.value
            .filter((t) => t.status.id === status.id)
            .sort((a, b) => a.position - b.position),
    })),
);

// S1-12: six columns don't fit; collapse the ones you don't need (Done starts collapsed). Remembered per client.
const collapseKey = `oe.board.collapsed.${props.workspace.slug}`;
const readCollapsed = (): number[] => {
    try {
        const raw = window.localStorage.getItem(collapseKey);
        if (raw) {
            return JSON.parse(raw) as number[];
        }
    } catch {
        // storage unavailable: fall through to the default
    }

    return props.statuses.filter((s) => s.category === 'done').map((s) => s.id);
};
const collapsed = ref<number[]>(readCollapsed());
function toggleColumn(id: number): void {
    collapsed.value = collapsed.value.includes(id)
        ? collapsed.value.filter((x) => x !== id)
        : [...collapsed.value, id];
    try {
        window.localStorage.setItem(
            collapseKey,
            JSON.stringify(collapsed.value),
        );
    } catch {
        // ignore
    }
}

const dragging = ref<number | null>(null);
const overColumn = ref<number | null>(null);
const addingTo = ref<number | null>(null);

function onDragStart(task: TaskRow, event: DragEvent): void {
    dragging.value = task.id;
    event.dataTransfer?.setData('text/plain', String(task.id));
}

function onDrop(statusId: number): void {
    overColumn.value = null;
    const id = dragging.value;
    dragging.value = null;
    if (id === null || !props.workspace.can.contribute) {
        return;
    }

    const task = items.value.find((t) => t.id === id);
    const status = props.statuses.find((s) => s.id === statusId);
    if (!task || !status || task.status.id === statusId) {
        return;
    }

    const previous = { status: task.status, position: task.position };
    const position =
        Math.max(
            0,
            ...items.value
                .filter((t) => t.status.id === statusId)
                .map((t) => t.position),
        ) + 1;

    // Optimistic update.
    task.status = status;
    task.position = position;

    router.patch(
        TaskController.move.url({ workspace: props.workspace.slug, task: id }),
        { status_id: statusId, position },
        {
            preserveScroll: true,
            preserveState: true,
            onError: (errors) => {
                task.status = previous.status;
                task.position = previous.position;
                toast.error(errors.status_id ?? 'Could not move the task.');
            },
        },
    );
}
</script>

<template>
    <Head :title="`${workspace.name} · Board`" />

    <div class="flex min-w-0 flex-1 flex-col gap-4 p-4">
        <WorkspaceHeader :workspace="workspace" />

        <TaskFilterBar
            :url="board.url(workspace.slug)"
            :filters="filters"
            :departments="departments"
            :members="members"
            :projects="projects"
            :sprints="sprintOptions"
        />
        <SavedViews
            :workspace-slug="workspace.slug"
            page="board"
            :views="views"
            :filters="filters"
            :can-share="workspace.can.contribute"
        />

        <div class="flex gap-3 overflow-x-auto pb-2">
            <section
                v-for="col in columns"
                :key="col.status.id"
                class="flex shrink-0 flex-col rounded-lg border bg-muted/30"
                :class="[
                    collapsed.includes(col.status.id) ? 'w-11' : 'w-60 xl:w-64',
                    { 'ring-2 ring-primary/40': overColumn === col.status.id },
                ]"
                :aria-label="col.status.name"
                @dragover.prevent="overColumn = col.status.id"
                @dragleave="overColumn = null"
                @drop.prevent="onDrop(col.status.id)"
            >
                <button
                    v-if="collapsed.includes(col.status.id)"
                    type="button"
                    class="flex flex-1 flex-col items-center gap-2 py-2 text-xs"
                    :aria-label="`Show ${col.status.name} column (${totalFor(col.status.id, col.tasks.length)})`"
                    :title="`Show ${col.status.name}`"
                    @click="toggleColumn(col.status.id)"
                >
                    <span class="text-muted-foreground">{{
                        totalFor(col.status.id, col.tasks.length)
                    }}</span>
                    <span
                        class="rounded-full px-1 py-2 font-medium [writing-mode:vertical-rl]"
                        :class="chipColor[col.status.color] ?? chipColor.slate"
                        >{{ col.status.name }}</span
                    >
                </button>
                <template v-else>
                    <header class="flex items-center justify-between px-3 py-2">
                        <span
                            class="rounded-full px-2 py-0.5 text-xs font-medium"
                            :class="
                                chipColor[col.status.color] ?? chipColor.slate
                            "
                            >{{ col.status.name }}</span
                        >
                        <span
                            class="flex items-center gap-1 text-xs text-muted-foreground"
                        >
                            {{ totalFor(col.status.id, col.tasks.length) }}
                            <button
                                type="button"
                                class="rounded px-1 hover:bg-accent"
                                :aria-label="`Collapse ${col.status.name} column`"
                                title="Collapse"
                                @click="toggleColumn(col.status.id)"
                            >
                                «
                            </button>
                        </span>
                    </header>

                    <div class="flex min-h-24 flex-1 flex-col gap-2 px-2 pb-2">
                        <article
                            v-for="t in col.tasks"
                            :key="t.id"
                            :draggable="workspace.can.contribute"
                            class="cursor-grab rounded-md border bg-background p-2 shadow-xs active:cursor-grabbing"
                            :class="{ 'opacity-50': dragging === t.id }"
                            @dragstart="onDragStart(t, $event)"
                            @dragend="dragging = null"
                        >
                            <div
                                class="mb-1 flex items-start justify-between gap-2"
                            >
                                <Link
                                    prefetch
                                    :href="
                                        show({
                                            workspace: workspace.slug,
                                            task: t.id,
                                        })
                                    "
                                    class="text-sm font-medium hover:underline"
                                    >{{ t.title }}</Link
                                >
                                <span
                                    v-if="t.assignee"
                                    class="flex size-6 shrink-0 items-center justify-center rounded-full text-[10px] font-semibold"
                                    :class="avatarColor(t.assignee.name)"
                                    :title="t.assignee.name"
                                    :aria-label="`Assigned to ${t.assignee.name}`"
                                    >{{ getInitials(t.assignee.name) }}</span
                                >
                            </div>
                            <TaskMeta :task="t" />
                            <div
                                v-if="t.estimate_minutes"
                                class="mt-1 text-[11px] text-muted-foreground"
                            >
                                Est. {{ formatMinutes(t.estimate_minutes) }}
                            </div>
                        </article>
                        <button
                            v-if="
                                totalFor(col.status.id, col.tasks.length) >
                                col.tasks.length
                            "
                            type="button"
                            class="rounded-md border border-dashed px-2 py-1.5 text-xs text-muted-foreground hover:bg-accent"
                            @click="showMore"
                        >
                            Show
                            {{
                                Math.min(
                                    100,
                                    totalFor(col.status.id, col.tasks.length) -
                                        col.tasks.length,
                                )
                            }}
                            more
                            <span class="sr-only"
                                >in {{ col.status.name }}</span
                            >
                        </button>

                        <div
                            v-if="
                                workspace.can.contribute &&
                                col.status.category !== 'done'
                            "
                        >
                            <QuickTaskForm
                                v-if="addingTo === col.status.id"
                                :workspace-slug="workspace.slug"
                                :departments="departments"
                                :members="members"
                                :status-id="col.status.id"
                                :project-id="filters.project ?? null"
                                autofocus
                            />
                            <button
                                v-else
                                type="button"
                                class="w-full rounded-md px-2 py-1 text-left text-xs text-muted-foreground hover:bg-accent"
                                @click="addingTo = col.status.id"
                            >
                                + Add task
                            </button>
                        </div>
                    </div>
                </template>
            </section>
        </div>
        <p class="text-xs text-muted-foreground">
            Drag cards between columns (also onto a collapsed column). A task
            that is waiting on others cannot be moved to Done. Use « to collapse
            a column.
        </p>
    </div>
</template>
