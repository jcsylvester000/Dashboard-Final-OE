<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import LabelBadge from '@/components/LabelBadge.vue';
import TaskMeta from '@/components/work/TaskMeta.vue';
import { mine } from '@/routes/tasks';
import { show } from '@/routes/workspaces/tasks';
import type { TaskRow, TaskStatus } from '@/types/work';

const props = defineProps<{ tasks: TaskRow[]; statuses: TaskStatus[] }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'My tasks', href: mine() }],
    },
});

const today = new Date().toISOString().slice(0, 10);
const weekAhead = new Date(Date.now() + 7 * 86400000).toISOString().slice(0, 10);

const groups = computed(() => {
    const buckets: { title: string; tasks: TaskRow[] }[] = [
        { title: 'Overdue', tasks: [] },
        { title: 'Due today', tasks: [] },
        { title: 'This week', tasks: [] },
        { title: 'Later', tasks: [] },
        { title: 'No due date', tasks: [] },
    ];

    for (const t of props.tasks) {
        if (!t.due_on) buckets[4].tasks.push(t);
        else if (t.due_on < today) buckets[0].tasks.push(t);
        else if (t.due_on === today) buckets[1].tasks.push(t);
        else if (t.due_on <= weekAhead) buckets[2].tasks.push(t);
        else buckets[3].tasks.push(t);
    }

    return buckets.filter((b) => b.tasks.length);
});
</script>

<template>
    <Head title="My tasks" />

    <div class="flex max-w-5xl flex-1 flex-col gap-4 p-4">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">My tasks</h1>
            <p class="text-sm text-muted-foreground">
                Everything assigned to you across all client workspaces, by due date.
            </p>
        </div>

        <p v-if="tasks.length === 0" class="rounded-lg border p-8 text-center text-sm text-muted-foreground">
            Nothing assigned to you right now.
        </p>

        <section v-for="g in groups" :key="g.title" class="space-y-2">
            <h2 class="text-sm font-semibold" :class="{ 'text-rose-600': g.title === 'Overdue' }">
                {{ g.title }} <span class="font-normal text-muted-foreground">({{ g.tasks.length }})</span>
            </h2>
            <ul class="divide-y rounded-lg border">
                <li v-for="t in g.tasks" :key="t.id" class="flex items-start justify-between gap-3 px-4 py-3">
                    <div class="min-w-0 space-y-1">
                        <Link :href="show({ workspace: t.workspace.slug, task: t.id })" class="font-medium hover:underline">{{ t.title }}</Link>
                        <TaskMeta :task="t" show-workspace />
                    </div>
                    <LabelBadge :name="t.status.name" :color="t.status.color" />
                </li>
            </ul>
        </section>
    </div>
</template>
