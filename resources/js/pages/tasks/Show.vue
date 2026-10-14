<script setup lang="ts">
import { Deferred, Form, Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowRightLeft,
    BadgeCheck,
    Bell,
    BellOff,
    Copy,
    Hourglass,
    Link2,
    Pencil,
    Trash2,
    X,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import CommentController from '@/actions/App/Http/Controllers/Work/CommentController';
import TaskController from '@/actions/App/Http/Controllers/Work/TaskController';
import TaskFlowController from '@/actions/App/Http/Controllers/Work/TaskFlowController';
import InputError from '@/components/InputError.vue';
import LabelBadge from '@/components/LabelBadge.vue';
import MentionTextarea from '@/components/MentionTextarea.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import AttachmentsPanel from '@/components/files/AttachmentsPanel.vue';
import ReferencePanel from '@/components/ReferencePanel.vue';
import RichText from '@/components/RichText.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import WorkspaceHeader from '@/components/WorkspaceHeader.vue';
import BugReportCard from '@/components/work/BugReportCard.vue';
import ChecklistCard from '@/components/work/ChecklistCard.vue';
import CustomFieldsCard from '@/components/work/CustomFieldsCard.vue';
import QuickTaskForm from '@/components/work/QuickTaskForm.vue';
import ReviewersCard from '@/components/work/ReviewersCard.vue';
import TaskMeta from '@/components/work/TaskMeta.vue';
import TaskPicker from '@/components/work/TaskPicker.vue';
import TaskTimeCard from '@/components/work/TaskTimeCard.vue';
import WorkDetailsCard from '@/components/work/WorkDetailsCard.vue';
import { confirmDialog } from '@/composables/useConfirm';
import { chipColor, formatMinutes, timeAgo } from '@/lib/format';
import { index as workspacesIndex } from '@/routes/workspaces';
import { index as tasksIndex, show } from '@/routes/workspaces/tasks';
import type { AttachmentRow, FileLimits } from '@/types/files';
import type { TaskTime } from '@/types/time';
import type { LinkRow } from '@/types/workspace';
import type {
    CustomField,
    TaskDetail,
    TaskPageShared,
    TaskRow,
    TimelineEntry,
} from '@/types/work';

const props = defineProps<
    TaskPageShared & {
        task: TaskDetail;
        timeline?: TimelineEntry[];
        links: LinkRow[];
        canDelete: boolean;
        attachments?: AttachmentRow[];
        handoffFiles?: AttachmentRow[];
        linkedWork: {
            relation: string;
            id: number | null;
            title: string;
            status: { name: string; color: string; category: string } | null;
            url: string;
        }[];
        canReview: boolean;
        reviewRequired: boolean;
        fileLimits: FileLimits;
        time?: TaskTime | null;
        workDetailFields: Record<
            string,
            Record<string, [string, string, string[]?]>
        >;
        customFields?: CustomField[];
    }
>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Workspaces', href: workspacesIndex() }],
    },
});

const ws = computed(() => props.workspace.slug);
const can = computed(() => props.workspace.can.contribute);
const editing = ref(false);
const handingOff = ref(false);
const editingComment = ref<number | null>(null);
const fieldError = ref<string | null>(null);
const requestingChanges = ref(false);
const reviewNote = ref('');
const inReview = computed(() => props.task.status.slug === 'in-review');

function review(decision: 'approve' | 'changes'): void {
    fieldError.value = null;
    router.post(
        TaskFlowController.review.url({
            workspace: ws.value,
            task: props.task.id,
        }),
        { decision, note: reviewNote.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                requestingChanges.value = false;
                reviewNote.value = '';
            },
            onError: (errors) =>
                (fieldError.value =
                    Object.values(errors)[0] ?? 'Could not save.'),
        },
    );
}

const estimateHours = computed(() =>
    props.task.estimate_minutes
        ? String(Math.round((props.task.estimate_minutes / 60) * 100) / 100)
        : '',
);

// Inertia reuses this component when moving between tasks (e.g. after a handoff), so reset local UI state.
watch(
    () => props.task.id,
    () => {
        handingOff.value = false;
        editing.value = false;
        editingComment.value = null;
    },
);

const taskUrl = () =>
    TaskController.update.url({ workspace: ws.value, task: props.task.id });

/** Change one field immediately (status, assignee, department, etc.). */
const copied = ref(false);

function copyKey(): void {
    const text = `${props.task.key} ${props.task.title} ${window.location.origin}${window.location.pathname}`;
    void navigator.clipboard?.writeText(text).then(() => {
        copied.value = true;
        window.setTimeout(() => (copied.value = false), 1500);
    });
}

// B-12: send when this page last saw the task; the server refuses if someone else saved since.
const conflict = ref<string | null>(null);
// Re-mount the side fields after a refused save so they show the saved values, not the refused one.
const sideKey = ref(0);
function setField(field: string, value: unknown): void {
    fieldError.value = null;
    conflict.value = null;
    router.put(
        taskUrl(),
        {
            [field]: value === '' ? null : value,
            _seen_at: props.task.updated_at ?? null,
        } as Record<string, string | number | null>,
        {
            preserveScroll: true,
            // E-120: a field save only changes the task, its history and linked work.
            only: [
                'task',
                'timeline',
                'linkedWork',
                'reviewRequired',
                'canReview',
                'canDelete',
            ],
            onError: (errors) => {
                if (errors.conflict) {
                    conflict.value = errors.conflict;
                    sideKey.value++;

                    return;
                }
                fieldError.value =
                    Object.values(errors)[0] ?? 'Could not save.';
                sideKey.value++;
            },
        },
    );
}
function reloadTask(): void {
    conflict.value = null;
    router.reload();
}

function duplicateTask(): void {
    router.post(
        TaskController.duplicate.url({
            workspace: ws.value,
            task: props.task.id,
        }),
    );
}

function addDependency(id: string | number | null | undefined): void {
    if (!id) {
        return;
    }
    router.post(
        TaskFlowController.addDependency.url({
            workspace: ws.value,
            task: props.task.id,
        }),
        { depends_on_task_id: Number(id) },
        {
            preserveScroll: true,
            onError: (e) => (fieldError.value = e.depends_on_task_id ?? null),
        },
    );
}

function removeDependency(dep: TaskRow): void {
    router.delete(
        TaskFlowController.removeDependency.url({
            workspace: ws.value,
            task: props.task.id,
            dependency: dep.id,
        }),
        { preserveScroll: true },
    );
}

function toggleWatch(): void {
    router.post(
        TaskController.watch.url({ workspace: ws.value, task: props.task.id }),
        {},
        { preserveScroll: true },
    );
}

async function destroyTask(): Promise<void> {
    if (await confirmDialog(`Delete "${props.task.title}"?`)) {
        router.delete(
            TaskController.destroy.url({
                workspace: ws.value,
                task: props.task.id,
            }),
        );
    }
}

async function deleteComment(id: number): Promise<void> {
    if (await confirmDialog('Delete this comment?')) {
        router.delete(
            CommentController.destroy.url({ workspace: ws.value, comment: id }),
            { preserveScroll: true },
        );
    }
}

const actionLabels: Record<string, string> = {
    'task.created': 'created the task',
    'task.updated': 'updated',
    'task.commented': 'commented',
    'task.handoff-sent': 'handed off to another department',
    'task.handoff-received': 'created this task as a handoff',
    'task.unblocked': 'unblocked (previous step done)',
    'task.dependency-added': 'added a dependency',
    'task.dependency-removed': 'removed a dependency',
    'task.time-logged': 'logged time',
    'task.approved': 'approved the work',
    'task.changes-requested': 'requested changes',
    'file.approved': 'approved a file',
    'file.unapproved': 'removed a file approval',
    'task.duplicated': 'created this task as a copy',
    'link.created': 'linked other work',
    'link.deleted': 'removed a link',
};

function describe(entry: TimelineEntry): string {
    const changes = (entry.properties?.changes ?? null) as Record<
        string,
        { from: unknown; to: unknown }
    > | null;
    if (entry.action === 'task.time-moved') {
        const moved = Number(entry.properties?.moved_minutes ?? 0);
        const kept = Number(entry.properties?.kept_minutes ?? 0);

        return `moved ${formatMinutes(moved)} of logged time to the new project${kept ? `; ${formatMinutes(kept)} billed time stayed` : ''}`;
    }
    if (entry.action === 'task.updated' && changes) {
        return Object.entries(changes)
            .map(
                ([field, c]) =>
                    `${field.replace('_id', '')}: ${c.from ?? '—'} → ${c.to ?? '—'}`,
            )
            .join(', ');
    }

    return actionLabels[entry.action] ?? entry.action;
}
</script>

<template>
    <Head :title="task.title" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <WorkspaceHeader :workspace="workspace" />

        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0 space-y-1">
                <Link
                    :href="tasksIndex(ws)"
                    class="text-xs text-muted-foreground hover:underline"
                    >← Tasks</Link
                >
                <h2 class="text-lg font-semibold">{{ task.title }}</h2>
                <button
                    type="button"
                    class="font-mono text-xs text-muted-foreground hover:underline"
                    title="Copy key and link"
                    @click="copyKey"
                >
                    {{ copied ? 'Copied ' + task.key : task.key }}
                </button>
                <TaskMeta :task="task" hide-key />
                <p v-if="task.handoff_from" class="text-xs text-violet-700">
                    Handed off from
                    <Link
                        :href="
                            show({ workspace: ws, task: task.handoff_from.id })
                        "
                        class="underline"
                        >{{ task.handoff_from.title }}</Link
                    >
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button variant="outline" size="sm" @click="toggleWatch">
                    <component :is="task.watching ? BellOff : Bell" />
                    {{ task.watching ? 'Unwatch' : 'Watch' }}
                </Button>
                <Button
                    v-if="can"
                    variant="outline"
                    size="sm"
                    @click="handingOff = !handingOff"
                >
                    <ArrowRightLeft /> Send to department
                </Button>
                <Button
                    v-if="can && !editing"
                    variant="outline"
                    size="sm"
                    @click="editing = true"
                    ><Pencil /> Edit</Button
                >
                <Button
                    v-if="can"
                    variant="outline"
                    size="sm"
                    title="Copy this task (fields, labels and subtasks)"
                    @click="duplicateTask"
                    ><Copy /> Duplicate</Button
                >
                <Button
                    v-if="canDelete"
                    variant="ghost"
                    size="sm"
                    aria-label="Delete task"
                    @click="destroyTask"
                    ><Trash2
                /></Button>
            </div>
        </div>

        <InputError :message="fieldError ?? undefined" />
        <div
            v-if="conflict"
            class="flex flex-wrap items-center gap-2 rounded-md border border-amber-300 bg-amber-50 p-2 text-sm text-amber-900 dark:bg-amber-950/40 dark:text-amber-200"
            role="alert"
        >
            {{ conflict }}
            <Button size="sm" variant="outline" @click="reloadTask"
                >Reload</Button
            >
        </div>

        <!-- Review sign-off -->
        <Card
            v-if="inReview"
            class="border-amber-300 bg-amber-50/60 dark:bg-amber-950/20"
        >
            <CardContent class="flex flex-wrap items-center gap-3 pt-6 text-sm">
                <BadgeCheck class="size-5 text-amber-700" />
                <div class="min-w-0 flex-1">
                    <p class="font-medium">Waiting for review</p>
                    <p class="text-xs text-muted-foreground">
                        <template v-if="canReview"
                            >Approve to mark it Done (the next department is
                            notified), or send it back with notes.</template
                        >
                        <template v-else
                            >A workspace lead, the project lead or the
                            {{ task.department?.name ?? 'department' }} lead
                            signs this off.</template
                        >
                    </p>
                </div>
                <template v-if="canReview && !requestingChanges">
                    <Button size="sm" @click="review('approve')"
                        ><BadgeCheck /> Approve</Button
                    >
                    <Button
                        size="sm"
                        variant="outline"
                        @click="requestingChanges = true"
                        >Request changes</Button
                    >
                </template>
                <div
                    v-if="canReview && requestingChanges"
                    class="grid w-full gap-2"
                >
                    <textarea
                        v-model="reviewNote"
                        v-focus
                        rows="3"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                        placeholder="What needs to change? This is posted to the discussion and the assignee is tagged."
                        aria-label="Changes requested"
                    />
                    <div class="flex gap-2">
                        <Button
                            size="sm"
                            :disabled="reviewNote.trim() === ''"
                            @click="review('changes')"
                            >Send back</Button
                        >
                        <Button
                            size="sm"
                            variant="ghost"
                            @click="requestingChanges = false"
                            >Cancel</Button
                        >
                    </div>
                </div>
            </CardContent>
        </Card>

        <!-- Handoff -->
        <Card v-if="handingOff">
            <CardHeader>
                <CardTitle class="text-base"
                    >Send to another department</CardTitle
                >
                <CardDescription>
                    Creates a linked task for that department. It waits in
                    Backlog until this task is Done, then moves to To Do
                    automatically.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Form
                    v-bind="
                        TaskFlowController.handoff.form({
                            workspace: ws,
                            task: task.id,
                        })
                    "
                    v-slot="{ errors, processing }"
                    class="grid gap-3 sm:grid-cols-2"
                    @success="handingOff = false"
                >
                    <div class="grid gap-1">
                        <Label for="ho-dept">Department</Label>
                        <NativeSelect
                            id="ho-dept"
                            name="department_id"
                            model-value=""
                            required
                        >
                            <option value="" disabled>Choose…</option>
                            <option
                                v-for="d in departments"
                                :key="d.id"
                                :value="d.id"
                            >
                                {{ d.name }}
                            </option>
                        </NativeSelect>
                        <InputError :message="errors.department_id" />
                    </div>
                    <div class="grid gap-1">
                        <Label for="ho-assignee">Assign to</Label>
                        <NativeSelect
                            id="ho-assignee"
                            name="assignee_id"
                            model-value=""
                        >
                            <option value="">Department lead decides</option>
                            <option
                                v-for="m in members"
                                :key="m.id"
                                :value="m.id"
                            >
                                {{ m.name }}
                            </option>
                        </NativeSelect>
                    </div>
                    <div class="grid gap-1">
                        <Label for="ho-title">Title (optional)</Label>
                        <Input
                            id="ho-title"
                            name="title"
                            placeholder="Defaults to Department: task title"
                        />
                    </div>
                    <div class="grid gap-1">
                        <Label for="ho-due">Due</Label>
                        <Input id="ho-due" name="due_on" type="date" />
                    </div>
                    <div class="grid gap-1 sm:col-span-2">
                        <Label for="ho-note">Handoff note</Label>
                        <MentionTextarea
                            id="ho-note"
                            name="note"
                            :members="members"
                            :departments="departments"
                            :rows="3"
                            placeholder="What they need to know. Type @ to tag."
                        />
                    </div>
                    <div class="flex gap-2 sm:col-span-2">
                        <Button type="submit" :disabled="processing"
                            >Send</Button
                        >
                        <Button
                            type="button"
                            variant="ghost"
                            @click="handingOff = false"
                            ><X /> Cancel</Button
                        >
                    </div>
                </Form>
            </CardContent>
        </Card>

        <div class="grid gap-4 lg:grid-cols-3">
            <div class="space-y-4 lg:col-span-2">
                <!-- Linked work: everything this task is connected to -->
                <Card v-if="linkedWork.length">
                    <CardHeader class="pb-2">
                        <CardTitle
                            class="flex items-center justify-between gap-2 text-base"
                        >
                            <span class="flex items-center gap-2"
                                ><Link2 class="size-4" /> Linked work
                                <span
                                    class="text-sm font-normal text-muted-foreground"
                                    >· {{ linkedWork.length }}</span
                                ></span
                            >
                            <a
                                v-if="can"
                                href="#references"
                                class="text-xs font-normal text-muted-foreground hover:underline"
                                >+ Link a task</a
                            >
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <ul class="divide-y text-sm">
                            <li
                                v-for="(l, i) in linkedWork"
                                :key="i"
                                class="flex items-center gap-3 py-1.5"
                            >
                                <span
                                    class="w-28 shrink-0 text-xs text-muted-foreground"
                                    >{{ l.relation }}</span
                                >
                                <Link
                                    :href="l.url"
                                    class="min-w-0 flex-1 truncate hover:underline"
                                    :class="{
                                        'text-muted-foreground line-through':
                                            l.status?.category === 'done',
                                    }"
                                    >{{ l.title }}</Link
                                >
                                <LabelBadge
                                    v-if="l.status"
                                    :name="l.status.name"
                                    :color="l.status.color"
                                />
                            </li>
                        </ul>
                    </CardContent>
                </Card>

                <!-- Details / edit -->
                <Card>
                    <BugReportCard
                        v-if="task.type === 'bug'"
                        :key="`bug-${task.id}-${task.updated_at}`"
                        :workspace-slug="ws"
                        :task-id="task.id"
                        :report="task.bug_report ?? {}"
                        :can-edit="can"
                    />

                    <CardContent class="pt-6">
                        <Form
                            v-if="editing"
                            v-bind="
                                TaskController.update.form({
                                    workspace: ws,
                                    task: task.id,
                                })
                            "
                            v-slot="{ errors, processing }"
                            class="space-y-3"
                            :options="{ preserveScroll: true }"
                            @success="editing = false"
                        >
                            <div class="grid gap-1">
                                <Label for="t-title">Title</Label>
                                <Input
                                    id="t-title"
                                    name="title"
                                    :default-value="task.title"
                                    required
                                />
                                <InputError :message="errors.title" />
                            </div>
                            <div class="grid gap-1">
                                <Label for="t-desc">Description</Label>
                                <MentionTextarea
                                    id="t-desc"
                                    name="description"
                                    :members="members"
                                    :departments="departments"
                                    :default-value="task.description"
                                    :rows="6"
                                />
                                <InputError :message="errors.description" />
                            </div>
                            <div class="grid gap-3 sm:grid-cols-3">
                                <div class="grid gap-1">
                                    <Label for="t-priority">Priority</Label>
                                    <NativeSelect
                                        id="t-priority"
                                        name="priority"
                                        :model-value="task.priority"
                                    >
                                        <option
                                            v-for="(label, key) in priorities"
                                            :key="key"
                                            :value="key"
                                        >
                                            {{ label }}
                                        </option>
                                    </NativeSelect>
                                </div>
                                <div class="grid gap-1">
                                    <Label for="t-project">Project</Label>
                                    <NativeSelect
                                        id="t-project"
                                        name="project_id"
                                        :model-value="task.project?.id ?? ''"
                                    >
                                        <option value="">None</option>
                                        <option
                                            v-for="p in projects"
                                            :key="p.id"
                                            :value="p.id"
                                        >
                                            {{ p.name }}
                                        </option>
                                    </NativeSelect>
                                </div>
                                <div class="grid gap-1">
                                    <Label for="t-estimate"
                                        >Estimate (hours)</Label
                                    >
                                    <Input
                                        id="t-estimate"
                                        name="estimate_hours"
                                        type="number"
                                        min="0"
                                        step="0.25"
                                        :default-value="estimateHours"
                                    />
                                </div>
                            </div>
                            <fieldset
                                v-if="labels.length"
                                class="flex flex-wrap gap-2"
                            >
                                <label
                                    v-for="l in labels"
                                    :key="l.id"
                                    class="inline-flex cursor-pointer items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="
                                        chipColor[l.color] ?? chipColor.slate
                                    "
                                >
                                    <input
                                        type="checkbox"
                                        name="label_ids[]"
                                        :value="l.id"
                                        :checked="
                                            task.labels.some(
                                                (x) => x.id === l.id,
                                            )
                                        "
                                        class="size-3"
                                    />
                                    {{ l.name }}
                                </label>
                                <input
                                    type="hidden"
                                    name="label_ids[]"
                                    value=""
                                />
                            </fieldset>
                            <div class="flex gap-2">
                                <Button type="submit" :disabled="processing"
                                    >Save</Button
                                >
                                <Button
                                    type="button"
                                    variant="ghost"
                                    @click="editing = false"
                                    >Cancel</Button
                                >
                            </div>
                        </Form>
                        <template v-else>
                            <RichText
                                v-if="task.description_html"
                                :html="task.description_html"
                            />
                            <p v-else class="text-sm text-muted-foreground">
                                No description.
                            </p>
                        </template>
                    </CardContent>
                </Card>

                <!-- Subtasks -->
                <Card>
                    <CardHeader>
                        <CardTitle class="text-base">Subtasks</CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <ul
                            v-if="task.subtasks.length"
                            class="divide-y text-sm"
                        >
                            <li
                                v-for="s in task.subtasks"
                                :key="s.id"
                                class="flex items-center justify-between gap-2 py-2"
                            >
                                <Link
                                    :href="show({ workspace: ws, task: s.id })"
                                    class="hover:underline"
                                    :class="{
                                        'text-muted-foreground line-through':
                                            s.status.category === 'done',
                                    }"
                                    >{{ s.title }}</Link
                                >
                                <LabelBadge
                                    :name="s.status.name"
                                    :color="s.status.color"
                                />
                            </li>
                        </ul>
                        <QuickTaskForm
                            v-if="can"
                            :workspace-slug="ws"
                            :departments="departments"
                            :members="members"
                            :parent-id="task.id"
                            :project-id="task.project?.id ?? null"
                        />
                    </CardContent>
                </Card>

                <ChecklistCard
                    :workspace-slug="ws"
                    :task-id="task.id"
                    :items="task.checklist_items ?? []"
                    :can-edit="can"
                />

                <!-- Comments -->
                <Card>
                    <CardHeader>
                        <CardTitle class="text-base">Discussion</CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <p
                            v-if="task.comments.length === 0"
                            class="text-sm text-muted-foreground"
                        >
                            No comments yet.
                        </p>
                        <div
                            v-for="c in task.comments"
                            :key="c.id"
                            class="space-y-1 border-b pb-3 last:border-0"
                        >
                            <div
                                class="flex items-center justify-between text-xs text-muted-foreground"
                            >
                                <span>
                                    <span class="font-medium text-foreground">{{
                                        c.author?.name ?? 'Former member'
                                    }}</span>
                                    · {{ timeAgo(c.created_at)
                                    }}<span v-if="c.edited_at"> · edited</span>
                                </span>
                                <span class="flex gap-1">
                                    <button
                                        v-if="c.can_edit"
                                        type="button"
                                        class="hover:underline"
                                        @click="editingComment = c.id"
                                    >
                                        Edit
                                    </button>
                                    <button
                                        v-if="c.can_delete"
                                        type="button"
                                        class="hover:underline"
                                        @click="deleteComment(c.id)"
                                    >
                                        Delete
                                    </button>
                                </span>
                            </div>
                            <Form
                                v-if="editingComment === c.id"
                                v-bind="
                                    CommentController.update.form({
                                        workspace: ws,
                                        comment: c.id,
                                    })
                                "
                                class="space-y-2"
                                :options="{ preserveScroll: true }"
                                @success="editingComment = null"
                            >
                                <MentionTextarea
                                    name="body"
                                    :members="members"
                                    :departments="departments"
                                    :default-value="c.body"
                                    :rows="3"
                                />
                                <div class="flex gap-2">
                                    <Button type="submit" size="sm"
                                        >Save</Button
                                    >
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        @click="editingComment = null"
                                        >Cancel</Button
                                    >
                                </div>
                            </Form>
                            <RichText v-else :html="c.body_html ?? ''" />
                        </div>

                        <Form
                            v-if="can"
                            v-bind="
                                CommentController.store.form({
                                    workspace: ws,
                                    task: task.id,
                                })
                            "
                            v-slot="{ errors, processing }"
                            class="space-y-2"
                            :options="{ preserveScroll: true }"
                            reset-on-success
                        >
                            <MentionTextarea
                                :key="task.comments.length"
                                name="body"
                                :members="members"
                                :departments="departments"
                                :rows="3"
                                :paste-target="{ type: 'task', id: task.id }"
                                placeholder="Write an update. Type @ to tag someone. Paste a screenshot (Ctrl+V). Markdown and ``` code blocks work."
                            />
                            <InputError :message="errors.body" />
                            <Button
                                type="submit"
                                size="sm"
                                :disabled="processing"
                                >Comment</Button
                            >
                        </Form>
                    </CardContent>
                </Card>
            </div>

            <div :key="sideKey" class="space-y-4">
                <ReviewersCard
                    :workspace-slug="ws"
                    :task-id="task.id"
                    :assignee-id="task.assignee?.id ?? null"
                    :reviewers="task.reviewers ?? []"
                    :rule="task.review_rule ?? 'all'"
                    :members="members"
                    :can-edit="can"
                />
                <!-- Quick fields (S1-19): what people change most, then Time, then the rest. -->
                <Card>
                    <CardContent class="space-y-3 pt-6 text-sm">
                        <div class="grid gap-1">
                            <Label for="q-status">Status</Label>
                            <NativeSelect
                                id="q-status"
                                :model-value="task.status.id"
                                :disabled="!can"
                                @update:model-value="
                                    (v) => setField('status_id', v)
                                "
                            >
                                <option
                                    v-for="s in statuses"
                                    :key="s.id"
                                    :value="s.id"
                                    :disabled="
                                        reviewRequired &&
                                        !canReview &&
                                        s.category === 'done' &&
                                        task.status.category !== 'done'
                                    "
                                >
                                    {{ s.name
                                    }}{{
                                        reviewRequired &&
                                        !canReview &&
                                        s.category === 'done'
                                            ? ' (lead signs off)'
                                            : ''
                                    }}
                                </option>
                            </NativeSelect>
                            <p
                                v-if="
                                    reviewRequired &&
                                    !canReview &&
                                    task.status.category !== 'done'
                                "
                                class="text-xs text-muted-foreground"
                            >
                                This project needs a lead's sign-off: move it to
                                In Review when it's ready.
                            </p>
                        </div>
                        <div class="grid gap-1">
                            <Label for="q-assignee">Assignee</Label>
                            <NativeSelect
                                id="q-assignee"
                                :model-value="task.assignee?.id ?? ''"
                                :disabled="!can"
                                @update:model-value="
                                    (v) => setField('assignee_id', v)
                                "
                            >
                                <option value="">Unassigned</option>
                                <option
                                    v-for="m in members"
                                    :key="m.id"
                                    :value="m.id"
                                >
                                    {{ m.name }}
                                </option>
                            </NativeSelect>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div class="grid gap-1">
                                <Label for="q-repeat">Repeats</Label>
                                <NativeSelect
                                    id="q-repeat"
                                    :model-value="task.recurrence ?? ''"
                                    :disabled="!can"
                                    @update:model-value="
                                        (v) => setField('recurrence', v)
                                    "
                                >
                                    <option value="">Doesn't repeat</option>
                                    <option value="weekly">Every week</option>
                                    <option value="biweekly">
                                        Every 2 weeks
                                    </option>
                                    <option value="monthly">Every month</option>
                                </NativeSelect>
                            </div>
                            <div v-if="task.recurrence" class="grid gap-1">
                                <Label for="q-until">Until</Label>
                                <Input
                                    id="q-until"
                                    type="date"
                                    :default-value="task.recurrence_until ?? ''"
                                    :disabled="!can"
                                    @change="
                                        (e: Event) =>
                                            setField(
                                                'recurrence_until',
                                                (e.target as HTMLInputElement)
                                                    .value,
                                            )
                                    "
                                />
                            </div>
                            <p
                                v-if="task.recurrence"
                                class="col-span-2 text-xs text-muted-foreground"
                            >
                                When this is done, the next one is created with
                                the same owner and details.
                            </p>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div class="grid gap-1">
                                <Label for="q-start">Start</Label>
                                <Input
                                    id="q-start"
                                    type="date"
                                    :default-value="task.start_on ?? ''"
                                    :max="task.due_on ?? undefined"
                                    :disabled="!can"
                                    @change="
                                        (e: Event) =>
                                            setField(
                                                'start_on',
                                                (e.target as HTMLInputElement)
                                                    .value,
                                            )
                                    "
                                />
                            </div>
                            <div class="grid gap-1">
                                <Label for="q-due">Due</Label>
                                <Input
                                    id="q-due"
                                    type="date"
                                    :default-value="task.due_on ?? ''"
                                    :disabled="!can"
                                    @change="
                                        (e: Event) =>
                                            setField(
                                                'due_on',
                                                (e.target as HTMLInputElement)
                                                    .value,
                                            )
                                    "
                                />
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Deferred data="time">
                    <template #fallback>
                        <div
                            class="h-28 animate-pulse rounded-xl border bg-muted/40"
                            aria-label="Loading time"
                        />
                    </template>
                    <TaskTimeCard
                        v-if="time"
                        :workspace-slug="ws"
                        :task-id="task.id"
                        :time="time"
                        :can-log="can"
                    />
                </Deferred>

                <Card>
                    <CardContent class="space-y-3 pt-6 text-sm">
                        <div class="grid gap-1">
                            <Label for="q-dept">Department</Label>
                            <NativeSelect
                                id="q-dept"
                                :model-value="task.department?.id ?? ''"
                                :disabled="!can"
                                @update:model-value="
                                    (v) => setField('department_id', v)
                                "
                            >
                                <option value="">None</option>
                                <option
                                    v-for="d in departments"
                                    :key="d.id"
                                    :value="d.id"
                                >
                                    {{ d.name }}
                                </option>
                            </NativeSelect>
                        </div>
                        <div class="grid gap-1">
                            <Label for="q-project">Project</Label>
                            <NativeSelect
                                id="q-project"
                                :model-value="task.project?.id ?? ''"
                                :disabled="!can"
                                @update:model-value="
                                    (v) => setField('project_id', v)
                                "
                            >
                                <option value="">None</option>
                                <option
                                    v-for="p in projects"
                                    :key="p.id"
                                    :value="p.id"
                                >
                                    {{ p.name }}
                                </option>
                            </NativeSelect>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div class="grid gap-1">
                                <Label for="q-sprint">Sprint</Label>
                                <NativeSelect
                                    id="q-sprint"
                                    :model-value="task.sprint_id ?? ''"
                                    :disabled="!can"
                                    @update:model-value="
                                        (v) => setField('sprint_id', v)
                                    "
                                >
                                    <option value="">Backlog</option>
                                    <option
                                        v-if="
                                            task.sprint &&
                                            !sprintOptions.some(
                                                (s) => s.id === task.sprint?.id,
                                            )
                                        "
                                        :value="task.sprint.id"
                                    >
                                        {{ task.sprint.name }} (closed)
                                    </option>
                                    <option
                                        v-for="s in sprintOptions"
                                        :key="s.id"
                                        :value="s.id"
                                    >
                                        {{ s.name
                                        }}{{
                                            s.status === 'active'
                                                ? ' (active)'
                                                : ''
                                        }}
                                    </option>
                                </NativeSelect>
                            </div>
                            <div class="grid gap-1">
                                <Label for="q-points">Story points</Label>
                                <NativeSelect
                                    id="q-points"
                                    :model-value="task.story_points ?? ''"
                                    :disabled="!can"
                                    @update:model-value="
                                        (v) => setField('story_points', v)
                                    "
                                >
                                    <option value="">None</option>
                                    <option
                                        v-for="p in [0, 1, 2, 3, 5, 8, 13, 21]"
                                        :key="p"
                                        :value="p"
                                    >
                                        {{ p }}
                                    </option>
                                </NativeSelect>
                            </div>
                        </div>
                        <div class="grid gap-1">
                            <Label for="q-type">Type</Label>
                            <NativeSelect
                                id="q-type"
                                :model-value="task.type"
                                :disabled="!can"
                                @update:model-value="(v) => setField('type', v)"
                            >
                                <option value="task">Task</option>
                                <option value="story">Story</option>
                                <option value="bug">Bug</option>
                                <option value="chore">Chore</option>
                                <option value="milestone">Milestone</option>
                            </NativeSelect>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div class="grid gap-1">
                                <Label for="q-priority">Priority</Label>
                                <NativeSelect
                                    id="q-priority"
                                    :model-value="task.priority"
                                    :disabled="!can"
                                    @update:model-value="
                                        (v) => setField('priority', v)
                                    "
                                >
                                    <option
                                        v-for="(label, key) in priorities"
                                        :key="key"
                                        :value="key"
                                    >
                                        {{ label }}
                                    </option>
                                </NativeSelect>
                            </div>
                            <div class="grid gap-1">
                                <Label for="q-estimate">Estimate (h)</Label>
                                <Input
                                    id="q-estimate"
                                    type="number"
                                    min="0"
                                    step="0.25"
                                    :default-value="estimateHours"
                                    :disabled="!can"
                                    @change="
                                        (e: Event) =>
                                            setField(
                                                'estimate_hours',
                                                (e.target as HTMLInputElement)
                                                    .value,
                                            )
                                    "
                                />
                            </div>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            Reported by {{ task.reporter?.name ?? '—' }} ·
                            {{ timeAgo(task.created_at) }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            Watching:
                            {{
                                task.watchers.map((w) => w.name).join(', ') ||
                                'nobody'
                            }}
                        </p>
                    </CardContent>
                </Card>

                <WorkDetailsCard
                    :key="`${task.id}-${task.department?.id}`"
                    :workspace-slug="ws"
                    :task-id="task.id"
                    :department-name="task.department?.name ?? null"
                    :department-slug="task.department?.slug ?? null"
                    :fields="workDetailFields"
                    :values="task.work_details"
                    :project-name="task.project?.name ?? null"
                    :can-edit="can"
                />
                <CustomFieldsCard
                    v-if="customFields?.length"
                    :key="`cf-${task.id}-${task.updated_at}`"
                    :workspace-slug="ws"
                    :task-id="task.id"
                    :fields="customFields"
                    :values="task.custom_values ?? {}"
                    :can-edit="can"
                />

                <Deferred :data="['attachments', 'handoffFiles']">
                    <template #fallback>
                        <div
                            class="h-32 animate-pulse rounded-xl border bg-muted/40"
                            aria-label="Loading files"
                        />
                    </template>
                    <AttachmentsPanel
                        attachable-type="task"
                        :attachable-id="task.id"
                        :attachments="attachments ?? []"
                        :limits="fileLimits"
                        :can-upload="can"
                    />

                    <AttachmentsPanel
                        v-if="task.handoff_from && handoffFiles?.length"
                        attachable-type="task"
                        :attachable-id="task.handoff_from.id"
                        :attachments="handoffFiles"
                        :limits="fileLimits"
                        :can-upload="false"
                        title="Files from previous step"
                        :description="`From \u201c${task.handoff_from.title}\u201d. Approved files are listed first.`"
                    />
                </Deferred>

                <!-- Dependencies -->
                <Card>
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2 text-base"
                            ><Hourglass class="size-4" /> Waiting on</CardTitle
                        >
                    </CardHeader>
                    <CardContent class="space-y-3 text-sm">
                        <p
                            v-if="task.dependencies.length === 0"
                            class="text-muted-foreground"
                        >
                            Nothing — free to finish.
                        </p>
                        <ul v-else class="space-y-2">
                            <li
                                v-for="d in task.dependencies"
                                :key="d.id"
                                class="flex items-center justify-between gap-2"
                            >
                                <span class="min-w-0">
                                    <Link
                                        :href="
                                            show({ workspace: ws, task: d.id })
                                        "
                                        class="hover:underline"
                                        >{{ d.title }}</Link
                                    >
                                    <span
                                        class="block text-xs text-muted-foreground"
                                        >{{ d.department?.name ?? '—' }} ·
                                        {{ d.status.name }}</span
                                    >
                                </span>
                                <button
                                    v-if="can"
                                    type="button"
                                    class="rounded p-1 hover:bg-accent"
                                    :aria-label="`Stop waiting on ${d.title}`"
                                    @click="removeDependency(d)"
                                >
                                    <X class="size-3.5" />
                                </button>
                            </li>
                        </ul>
                        <TaskPicker
                            v-if="can"
                            :workspace-id="workspace.id"
                            :exclude-id="task.id"
                            placeholder="+ Wait on another task (search title or key)"
                            @pick="addDependency"
                        />

                        <div
                            v-if="task.dependents.length"
                            class="border-t pt-3"
                        >
                            <div
                                class="mb-1 text-xs font-medium text-muted-foreground"
                            >
                                Blocks
                            </div>
                            <ul class="space-y-1">
                                <li v-for="d in task.dependents" :key="d.id">
                                    <Link
                                        :href="
                                            show({ workspace: ws, task: d.id })
                                        "
                                        class="hover:underline"
                                        >{{ d.title }}</Link
                                    >
                                    <span class="text-xs text-muted-foreground">
                                        · {{ d.department?.name ?? '—' }}</span
                                    >
                                </li>
                            </ul>
                        </div>
                    </CardContent>
                </Card>

                <Card id="references">
                    <CardHeader>
                        <CardTitle class="text-base"
                            >Link to other work</CardTitle
                        >
                        <CardDescription
                            >Relates to, duplicates or follow-up of any task or
                            project you can see. Use "Waiting on" for
                            blockers.</CardDescription
                        >
                    </CardHeader>
                    <CardContent>
                        <ReferencePanel
                            source-type="task"
                            :source-id="task.id"
                            :links="links"
                            :can-edit="can"
                        />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle class="text-base">Timeline</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Deferred data="timeline">
                            <template #fallback>
                                <p class="text-xs text-muted-foreground">
                                    Loading history…
                                </p>
                            </template>
                            <ul class="space-y-2 text-xs">
                                <li v-for="e in timeline ?? []" :key="e.id">
                                    <span class="font-medium">{{
                                        e.actor ?? 'System'
                                    }}</span>
                                    {{ describe(e) }}
                                    <span class="text-muted-foreground">
                                        · {{ timeAgo(e.at) }}</span
                                    >
                                </li>
                            </ul>
                        </Deferred>
                    </CardContent>
                </Card>
            </div>
        </div>
    </div>
</template>
