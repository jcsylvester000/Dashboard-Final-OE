<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import TaskController from '@/actions/App/Http/Controllers/Work/TaskController';
import WorkspaceHeader from '@/components/WorkspaceHeader.vue';
import QuickTaskForm from '@/components/work/QuickTaskForm.vue';
import TaskFilterBar from '@/components/work/TaskFilterBar.vue';
import TaskMeta from '@/components/work/TaskMeta.vue';
import { chipColor } from '@/lib/format';
import { index as workspacesIndex } from '@/routes/workspaces';
import { board, show } from '@/routes/workspaces/tasks';
import type { TaskFilters, TaskPageShared, TaskRow } from '@/types/work';

const props = defineProps<
    TaskPageShared & {
        tasks: TaskRow[];
        filters: TaskFilters;
    }
>();

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
    const position = Math.max(0, ...items.value.filter((t) => t.status.id === statusId).map((t) => t.position)) + 1;

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
        />

        <div class="flex gap-3 overflow-x-auto pb-2">
            <section
                v-for="col in columns"
                :key="col.status.id"
                class="flex w-72 shrink-0 flex-col rounded-lg border bg-muted/30"
                :class="{ 'ring-2 ring-primary/40': overColumn === col.status.id }"
                :aria-label="col.status.name"
                @dragover.prevent="overColumn = col.status.id"
                @dragleave="overColumn = null"
                @drop.prevent="onDrop(col.status.id)"
            >
                <header class="flex items-center justify-between px-3 py-2">
                    <span
                        class="rounded-full px-2 py-0.5 text-xs font-medium"
                        :class="chipColor[col.status.color] ?? chipColor.slate"
                        >{{ col.status.name }}</span
                    >
                    <span class="text-xs text-muted-foreground">{{ col.tasks.length }}</span>
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
                        <Link
                            :href="show({ workspace: workspace.slug, task: t.id })"
                            class="mb-1 block text-sm font-medium hover:underline"
                            >{{ t.title }}</Link
                        >
                        <TaskMeta :task="t" />
                    </article>

                    <div v-if="workspace.can.contribute && col.status.category !== 'done'">
                        <QuickTaskForm
                            v-if="addingTo === col.status.id"
                            :workspace-slug="workspace.slug"
                            :departments="departments"
                            :members="members"
                            :status-id="col.status.id"
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
            </section>
        </div>
        <p class="text-xs text-muted-foreground">
            Drag cards between columns. A task that is waiting on others cannot be moved to Done.
        </p>
    </div>
</template>
