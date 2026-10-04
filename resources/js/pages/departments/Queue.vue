<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive, watch } from 'vue';
import LabelBadge from '@/components/LabelBadge.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import TaskMeta from '@/components/work/TaskMeta.vue';
import { departmentDot } from '@/lib/format';
import { queue } from '@/routes/departments';
import { show } from '@/routes/workspaces/tasks';
import type { TaskRow, TaskStatus } from '@/types/work';

type Dept = { id: number; name: string; slug: string; color: string };

const props = defineProps<{
    department: Dept;
    tasks: TaskRow[];
    statuses: TaskStatus[];
    filters: { workspace?: number | string; assignee?: string; due?: string };
    workspaces: { id: number; name: string }[];
    departments: Dept[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Department queue', href: '#' }],
    },
});

const form = reactive({
    workspace: props.filters.workspace ? String(props.filters.workspace) : '',
    assignee: props.filters.assignee ?? '',
    due: props.filters.due ?? '',
});

watch(form, () => {
    const query = Object.fromEntries(Object.entries(form).filter(([, v]) => v !== ''));
    router.get(queue.url(props.department.slug, { query }), {}, { preserveState: true, replace: true });
});

const byStatus = computed(() =>
    props.statuses
        .filter((s) => s.category !== 'done')
        .map((s) => ({ status: s, tasks: props.tasks.filter((t) => t.status.id === s.id) }))
        .filter((g) => g.tasks.length),
);
</script>

<template>
    <Head :title="`${department.name} queue`" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="flex items-center gap-2 text-xl font-semibold tracking-tight">
                    <span class="size-3 rounded-full" :class="departmentDot[department.color] ?? 'bg-slate-500'" />
                    {{ department.name }} queue
                </h1>
                <p class="text-sm text-muted-foreground">
                    Open {{ department.name }} work across every client you can see.
                </p>
            </div>
            <nav class="flex flex-wrap gap-1 text-sm">
                <Link
                    v-for="d in departments"
                    :key="d.id"
                    :href="queue(d.slug)"
                    class="rounded-md border px-2 py-1"
                    :class="d.id === department.id ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-accent'"
                    >{{ d.name }}</Link
                >
            </nav>
        </div>

        <div class="flex flex-wrap gap-2">
            <NativeSelect v-model="form.workspace" class="w-48" aria-label="Client">
                <option value="">All clients</option>
                <option v-for="w in workspaces" :key="w.id" :value="String(w.id)">{{ w.name }}</option>
            </NativeSelect>
            <NativeSelect v-model="form.assignee" class="w-40" aria-label="Assignee">
                <option value="">Anyone</option>
                <option value="me">Me</option>
                <option value="none">Unassigned</option>
            </NativeSelect>
            <NativeSelect v-model="form.due" class="w-36" aria-label="Due">
                <option value="">Any due date</option>
                <option value="overdue">Overdue</option>
                <option value="week">This week</option>
            </NativeSelect>
        </div>

        <p v-if="byStatus.length === 0" class="rounded-lg border p-8 text-center text-sm text-muted-foreground">
            The queue is clear.
        </p>

        <section v-for="g in byStatus" :key="g.status.id" class="space-y-2">
            <h2 class="flex items-center gap-2 text-sm font-semibold">
                <LabelBadge :name="g.status.name" :color="g.status.color" />
                <span class="font-normal text-muted-foreground">{{ g.tasks.length }}</span>
            </h2>
            <ul class="divide-y rounded-lg border">
                <li v-for="t in g.tasks" :key="t.id" class="px-4 py-3">
                    <Link :href="show({ workspace: t.workspace.slug, task: t.id })" class="font-medium hover:underline">{{ t.title }}</Link>
                    <TaskMeta :task="t" show-workspace />
                </li>
            </ul>
        </section>
    </div>
</template>
