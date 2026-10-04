<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import LabelBadge from '@/components/LabelBadge.vue';
import Pagination from '@/components/Pagination.vue';
import WorkspaceHeader from '@/components/WorkspaceHeader.vue';
import ApplyTemplatePanel from '@/components/work/ApplyTemplatePanel.vue';
import QuickTaskForm from '@/components/work/QuickTaskForm.vue';
import TaskFilterBar from '@/components/work/TaskFilterBar.vue';
import TaskMeta from '@/components/work/TaskMeta.vue';
import { index as workspacesIndex } from '@/routes/workspaces';
import { index, show } from '@/routes/workspaces/tasks';
import type { Paginated } from '@/types/admin';
import type { TaskFilters, TaskPageShared, TaskRow } from '@/types/work';

const props = defineProps<
    TaskPageShared & {
        tasks: Paginated<TaskRow>;
        filters: TaskFilters;
    }
>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Workspaces', href: workspacesIndex() }],
    },
});

const listUrl = index.url(props.workspace.slug);
</script>

<template>
    <Head :title="`${workspace.name} · Tasks`" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <WorkspaceHeader :workspace="workspace" />

        <div v-if="workspace.can.contribute" class="flex flex-wrap items-start justify-between gap-3">
            <QuickTaskForm class="flex-1" :workspace-slug="workspace.slug" :departments="departments" :members="members" />
            <ApplyTemplatePanel
                :workspace-slug="workspace.slug"
                :templates="templates"
                :projects="projects"
                :departments="departments"
                :members="members"
            />
        </div>

        <TaskFilterBar
            :url="listUrl"
            :filters="filters"
            :statuses="statuses"
            :departments="departments"
            :members="members"
            :projects="projects"
            show-status
        />

        <div class="overflow-hidden rounded-lg border">
            <p v-if="tasks.data.length === 0" class="p-8 text-center text-sm text-muted-foreground">
                No tasks match. Add one above or start a workflow.
            </p>
            <ul v-else class="divide-y">
                <li v-for="t in tasks.data" :key="t.id" class="flex items-start justify-between gap-3 px-4 py-3">
                    <div class="min-w-0 space-y-1">
                        <Link
                            :href="show({ workspace: workspace.slug, task: t.id })"
                            class="font-medium hover:underline"
                            :class="{ 'text-muted-foreground line-through': t.status.category === 'done' }"
                            >{{ t.title }}</Link
                        >
                        <TaskMeta :task="t" />
                    </div>
                    <div class="flex shrink-0 flex-col items-end gap-1">
                        <LabelBadge :name="t.status.name" :color="t.status.color" />
                        <span v-if="t.project" class="text-xs text-muted-foreground">{{ t.project.name }}</span>
                    </div>
                </li>
            </ul>
        </div>

        <Pagination :links="tasks.links" :from="tasks.from" :to="tasks.to" :total="tasks.total" />
    </div>
</template>
