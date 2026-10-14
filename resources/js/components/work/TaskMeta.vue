<script setup lang="ts">
import {
    BookOpen,
    Bug,
    Flag,
    FolderKanban,
    Hourglass,
    Link2,
    Repeat,
    Repeat2,
    Timer,
    Wrench,
} from '@lucide/vue';
import LabelBadge from '@/components/LabelBadge.vue';
import { departmentDot, formatDate, isOverdue } from '@/lib/format';
import type { TaskRow } from '@/types/work';

/**
 * Compact metadata line for a task: department, project, assignee, due date,
 * priority, "waiting on" and handoff markers, labels.
 */
defineProps<{ task: TaskRow; showWorkspace?: boolean; hideKey?: boolean }>();

const priorityClass: Record<string, string> = {
    urgent: 'text-rose-600 font-semibold',
    high: 'text-amber-600 font-medium',
    normal: 'text-muted-foreground',
    low: 'text-muted-foreground',
};
</script>

<template>
    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
        <span
            v-if="!hideKey"
            class="inline-flex items-center gap-1 font-mono text-muted-foreground"
            :title="`${task.type} ${task.key}`"
        >
            <Bug
                v-if="task.type === 'bug'"
                class="size-3 text-rose-600"
                aria-label="Bug"
            />
            <BookOpen
                v-else-if="task.type === 'story'"
                class="size-3 text-emerald-600"
                aria-label="Story"
            />
            <Wrench
                v-else-if="task.type === 'chore'"
                class="size-3 text-slate-500"
                aria-label="Chore"
            />
            <Flag
                v-else-if="task.type === 'milestone'"
                class="size-3 text-violet-600"
                aria-label="Milestone"
            />
            {{ task.key }}
        </span>
        <span
            v-if="showWorkspace"
            class="inline-flex items-center gap-1 text-muted-foreground"
        >
            <span
                class="size-2 rounded-full"
                :class="departmentDot[task.workspace.color] ?? 'bg-slate-500'"
            />
            {{ task.workspace.name }}
        </span>
        <span v-if="task.department" class="inline-flex items-center gap-1">
            <span
                class="size-2 rounded-full"
                :class="departmentDot[task.department.color] ?? 'bg-slate-500'"
            />
            {{ task.department.name }}
        </span>
        <span
            v-if="task.project"
            class="inline-flex max-w-56 items-center gap-1 truncate text-muted-foreground"
            :title="`Project: ${task.project.name}`"
        >
            <FolderKanban class="size-3 shrink-0" />
            <span class="truncate">{{ task.project.name }}</span>
        </span>
        <span class="text-muted-foreground">{{
            task.assignee?.name ?? 'Unassigned'
        }}</span>
        <span
            v-if="task.sprint"
            class="inline-flex items-center gap-1 text-indigo-700 dark:text-indigo-300"
            :title="`Sprint: ${task.sprint.name}`"
        >
            <Timer class="size-3" /> {{ task.sprint.name }}
        </span>
        <span
            v-if="task.bug_report_missing"
            class="rounded bg-rose-100 px-1 text-[10px] font-medium text-rose-800 dark:bg-rose-950 dark:text-rose-200"
            title="This bug has no steps / expected / actual yet"
            >Needs bug report</span
        >
        <span
            v-if="task.recurrence"
            class="inline-flex items-center gap-1 text-muted-foreground"
            :title="`Repeats ${task.recurrence === 'biweekly' ? 'every 2 weeks' : task.recurrence === 'weekly' ? 'every week' : 'every month'}`"
        >
            <Repeat class="size-3" />
            {{ task.recurrence === 'biweekly' ? '2-weekly' : task.recurrence }}
        </span>
        <span
            v-if="task.story_points != null"
            class="rounded bg-muted px-1 tabular-nums"
            :title="`${task.story_points} story points`"
            >{{ task.story_points }} pt</span
        >
        <span
            v-if="task.due_on"
            :class="
                isOverdue(task.due_on) && task.status.category !== 'done'
                    ? 'font-medium text-rose-600'
                    : 'text-muted-foreground'
            "
            >Due {{ formatDate(task.due_on)
            }}<template
                v-if="isOverdue(task.due_on) && task.status.category !== 'done'"
            >
                · overdue</template
            ></span
        >
        <span
            v-if="task.priority !== 'normal'"
            :class="priorityClass[task.priority]"
            >{{ task.priority }}</span
        >
        <span
            v-if="task.waiting_on > 0"
            class="inline-flex items-center gap-1 text-amber-700"
            :title="`Waiting on ${task.waiting_on} task(s)`"
        >
            <Hourglass class="size-3" /> waiting on {{ task.waiting_on }}
        </span>
        <span
            v-if="task.linked"
            class="inline-flex items-center gap-1 text-sky-700 dark:text-sky-300"
            :title="`Linked to ${task.linked} item(s)`"
        >
            <Link2 class="size-3" /> {{ task.linked }} linked
        </span>
        <span
            v-if="task.is_handoff"
            class="inline-flex items-center gap-1 text-violet-700"
            title="Handed off from another department"
        >
            <Repeat2 class="size-3" /> handoff
        </span>
        <LabelBadge
            v-for="l in task.labels"
            :key="l.id"
            :name="l.name"
            :color="l.color"
        />
    </div>
</template>
