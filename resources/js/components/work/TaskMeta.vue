<script setup lang="ts">
import { Hourglass, Repeat2 } from '@lucide/vue';
import LabelBadge from '@/components/LabelBadge.vue';
import { departmentDot, formatDate, isOverdue } from '@/lib/format';
import type { TaskRow } from '@/types/work';

/**
 * Compact metadata line for a task: department, assignee, due date,
 * priority, "waiting on" and handoff markers, labels.
 */
defineProps<{ task: TaskRow; showWorkspace?: boolean }>();

const priorityClass: Record<string, string> = {
    urgent: 'text-rose-600 font-semibold',
    high: 'text-amber-600 font-medium',
    normal: 'text-muted-foreground',
    low: 'text-muted-foreground',
};
</script>

<template>
    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
        <span v-if="showWorkspace" class="inline-flex items-center gap-1 text-muted-foreground">
            <span class="size-2 rounded-full" :class="departmentDot[task.workspace.color] ?? 'bg-slate-500'" />
            {{ task.workspace.name }}
        </span>
        <span v-if="task.department" class="inline-flex items-center gap-1">
            <span class="size-2 rounded-full" :class="departmentDot[task.department.color] ?? 'bg-slate-500'" />
            {{ task.department.name }}
        </span>
        <span class="text-muted-foreground">{{ task.assignee?.name ?? 'Unassigned' }}</span>
        <span
            v-if="task.due_on"
            :class="isOverdue(task.due_on) && task.status.category !== 'done' ? 'font-medium text-rose-600' : 'text-muted-foreground'"
            >Due {{ formatDate(task.due_on) }}</span
        >
        <span v-if="task.priority !== 'normal'" :class="priorityClass[task.priority]">{{ task.priority }}</span>
        <span
            v-if="task.waiting_on > 0"
            class="inline-flex items-center gap-1 text-amber-700"
            :title="`Waiting on ${task.waiting_on} task(s)`"
        >
            <Hourglass class="size-3" /> waiting on {{ task.waiting_on }}
        </span>
        <span v-if="task.is_handoff" class="inline-flex items-center gap-1 text-violet-700" title="Handed off from another department">
            <Repeat2 class="size-3" /> handoff
        </span>
        <LabelBadge v-for="l in task.labels" :key="l.id" :name="l.name" :color="l.color" />
    </div>
</template>
