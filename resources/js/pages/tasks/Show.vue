<script setup lang="ts">
import { Form, Head, Link, router } from '@inertiajs/vue3';
import { ArrowRightLeft, Bell, BellOff, Hourglass, Pencil, Trash2, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import CommentController from '@/actions/App/Http/Controllers/Work/CommentController';
import TaskController from '@/actions/App/Http/Controllers/Work/TaskController';
import TaskFlowController from '@/actions/App/Http/Controllers/Work/TaskFlowController';
import InputError from '@/components/InputError.vue';
import LabelBadge from '@/components/LabelBadge.vue';
import MentionText from '@/components/MentionText.vue';
import MentionTextarea from '@/components/MentionTextarea.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import AttachmentsPanel from '@/components/files/AttachmentsPanel.vue';
import ReferencePanel from '@/components/ReferencePanel.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import WorkspaceHeader from '@/components/WorkspaceHeader.vue';
import QuickTaskForm from '@/components/work/QuickTaskForm.vue';
import TaskMeta from '@/components/work/TaskMeta.vue';
import TaskTimeCard from '@/components/work/TaskTimeCard.vue';
import WorkDetailsCard from '@/components/work/WorkDetailsCard.vue';
import { chipColor, timeAgo } from '@/lib/format';
import { index as workspacesIndex } from '@/routes/workspaces';
import { index as tasksIndex, show } from '@/routes/workspaces/tasks';
import type { AttachmentRow, FileLimits } from '@/types/files';
import type { TaskTime } from '@/types/time';
import type { LinkRow } from '@/types/workspace';
import type { TaskDetail, TaskPageShared, TaskRow, TimelineEntry } from '@/types/work';

const props = defineProps<
    TaskPageShared & {
        task: TaskDetail;
        timeline: TimelineEntry[];
        links: LinkRow[];
        canDelete: boolean;
        attachments: AttachmentRow[];
        fileLimits: FileLimits;
        dependencyOptions: { id: number; title: string }[];
        time: TaskTime | null;
        workDetailFields: Record<string, Record<string, [string, string, string[]?]>>;
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

const taskUrl = () => TaskController.update.url({ workspace: ws.value, task: props.task.id });

/** Change one field immediately (status, assignee, department, etc.). */
function setField(field: string, value: unknown): void {
    fieldError.value = null;
    router.put(taskUrl(), { [field]: value === '' ? null : value } as Record<string, string | number | null>, {
        preserveScroll: true,
        onError: (errors) => (fieldError.value = Object.values(errors)[0] ?? 'Could not save.'),
    });
}

function addDependency(id: string | number | null | undefined): void {
    if (!id) {
        return;
    }
    router.post(
        TaskFlowController.addDependency.url({ workspace: ws.value, task: props.task.id }),
        { depends_on_task_id: Number(id) },
        { preserveScroll: true, onError: (e) => (fieldError.value = e.depends_on_task_id ?? null) },
    );
}

function removeDependency(dep: TaskRow): void {
    router.delete(
        TaskFlowController.removeDependency.url({ workspace: ws.value, task: props.task.id, dependency: dep.id }),
        { preserveScroll: true },
    );
}

function toggleWatch(): void {
    router.post(TaskController.watch.url({ workspace: ws.value, task: props.task.id }), {}, { preserveScroll: true });
}

function destroyTask(): void {
    if (window.confirm(`Delete "${props.task.title}"?`)) {
        router.delete(TaskController.destroy.url({ workspace: ws.value, task: props.task.id }));
    }
}

function deleteComment(id: number): void {
    if (window.confirm('Delete this comment?')) {
        router.delete(CommentController.destroy.url({ workspace: ws.value, comment: id }), { preserveScroll: true });
    }
}

const actionLabels: Record<string, string> = {
    'task.created': 'created the task',
    'task.updated': 'updated',
    'task.commented': 'commented',
    'task.handoff-sent': 'handed off to another department',
    'task.handoff-received': 'received a handoff',
    'task.unblocked': 'unblocked (previous step done)',
    'task.dependency-added': 'added a dependency',
    'task.dependency-removed': 'removed a dependency',
    'task.time-logged': 'logged time',
};

function describe(entry: TimelineEntry): string {
    const changes = (entry.properties?.changes ?? null) as Record<string, { from: unknown; to: unknown }> | null;
    if (entry.action === 'task.updated' && changes) {
        return Object.entries(changes)
            .map(([field, c]) => `${field.replace('_id', '')}: ${c.from ?? '—'} → ${c.to ?? '—'}`)
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
                <Link :href="tasksIndex(ws)" class="text-xs text-muted-foreground hover:underline">← Tasks</Link>
                <h2 class="text-lg font-semibold">{{ task.title }}</h2>
                <TaskMeta :task="task" />
                <p v-if="task.handoff_from" class="text-xs text-violet-700">
                    Handed off from
                    <Link :href="show({ workspace: ws, task: task.handoff_from.id })" class="underline">{{ task.handoff_from.title }}</Link>
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button variant="outline" size="sm" @click="toggleWatch">
                    <component :is="task.watching ? BellOff : Bell" /> {{ task.watching ? 'Unwatch' : 'Watch' }}
                </Button>
                <Button v-if="can" variant="outline" size="sm" @click="handingOff = !handingOff">
                    <ArrowRightLeft /> Send to department
                </Button>
                <Button v-if="can && !editing" variant="outline" size="sm" @click="editing = true"><Pencil /> Edit</Button>
                <Button v-if="canDelete" variant="ghost" size="sm" aria-label="Delete task" @click="destroyTask"><Trash2 /></Button>
            </div>
        </div>

        <InputError :message="fieldError ?? undefined" />

        <!-- Handoff -->
        <Card v-if="handingOff">
            <CardHeader>
                <CardTitle class="text-base">Send to another department</CardTitle>
                <CardDescription>
                    Creates a linked task for that department. It waits in Backlog until this task is Done, then moves
                    to To Do automatically.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Form
                    v-bind="TaskFlowController.handoff.form({ workspace: ws, task: task.id })"
                    v-slot="{ errors, processing }"
                    class="grid gap-3 sm:grid-cols-2"
                >
                    <div class="grid gap-1">
                        <Label for="ho-dept">Department</Label>
                        <NativeSelect id="ho-dept" name="department_id" model-value="" required>
                            <option value="" disabled>Choose…</option>
                            <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
                        </NativeSelect>
                        <InputError :message="errors.department_id" />
                    </div>
                    <div class="grid gap-1">
                        <Label for="ho-assignee">Assign to</Label>
                        <NativeSelect id="ho-assignee" name="assignee_id" model-value="">
                            <option value="">Department lead decides</option>
                            <option v-for="m in members" :key="m.id" :value="m.id">{{ m.name }}</option>
                        </NativeSelect>
                    </div>
                    <div class="grid gap-1">
                        <Label for="ho-title">Title (optional)</Label>
                        <Input id="ho-title" name="title" placeholder="Defaults to Department: task title" />
                    </div>
                    <div class="grid gap-1">
                        <Label for="ho-due">Due</Label>
                        <Input id="ho-due" name="due_on" type="date" />
                    </div>
                    <div class="grid gap-1 sm:col-span-2">
                        <Label for="ho-note">Handoff note</Label>
                        <MentionTextarea id="ho-note" name="note" :members="members" :rows="3" placeholder="What they need to know. Type @ to tag." />
                    </div>
                    <div class="flex gap-2 sm:col-span-2">
                        <Button type="submit" :disabled="processing">Send</Button>
                        <Button type="button" variant="ghost" @click="handingOff = false"><X /> Cancel</Button>
                    </div>
                </Form>
            </CardContent>
        </Card>

        <div class="grid gap-4 lg:grid-cols-3">
            <div class="space-y-4 lg:col-span-2">
                <!-- Details / edit -->
                <Card>
                    <CardContent class="pt-6">
                        <Form
                            v-if="editing"
                            v-bind="TaskController.update.form({ workspace: ws, task: task.id })"
                            v-slot="{ errors, processing }"
                            class="space-y-3"
                            preserve-scroll
                            @success="editing = false"
                        >
                            <div class="grid gap-1">
                                <Label for="t-title">Title</Label>
                                <Input id="t-title" name="title" :default-value="task.title" required />
                                <InputError :message="errors.title" />
                            </div>
                            <div class="grid gap-1">
                                <Label for="t-desc">Description</Label>
                                <MentionTextarea id="t-desc" name="description" :members="members" :default-value="task.description" :rows="6" />
                                <InputError :message="errors.description" />
                            </div>
                            <div class="grid gap-3 sm:grid-cols-3">
                                <div class="grid gap-1">
                                    <Label for="t-priority">Priority</Label>
                                    <NativeSelect id="t-priority" name="priority" :model-value="task.priority">
                                        <option v-for="(label, key) in priorities" :key="key" :value="key">{{ label }}</option>
                                    </NativeSelect>
                                </div>
                                <div class="grid gap-1">
                                    <Label for="t-project">Project</Label>
                                    <NativeSelect id="t-project" name="project_id" :model-value="task.project?.id ?? ''">
                                        <option value="">None</option>
                                        <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }}</option>
                                    </NativeSelect>
                                </div>
                                <div class="grid gap-1">
                                    <Label for="t-estimate">Estimate (minutes)</Label>
                                    <Input id="t-estimate" name="estimate_minutes" type="number" min="0" :default-value="task.estimate_minutes ?? ''" />
                                </div>
                            </div>
                            <fieldset v-if="labels.length" class="flex flex-wrap gap-2">
                                <label
                                    v-for="l in labels"
                                    :key="l.id"
                                    class="inline-flex cursor-pointer items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="chipColor[l.color] ?? chipColor.slate"
                                >
                                    <input type="checkbox" name="label_ids[]" :value="l.id" :checked="task.labels.some((x) => x.id === l.id)" class="size-3" />
                                    {{ l.name }}
                                </label>
                                <input type="hidden" name="label_ids[]" value="" />
                            </fieldset>
                            <div class="flex gap-2">
                                <Button type="submit" :disabled="processing">Save</Button>
                                <Button type="button" variant="ghost" @click="editing = false">Cancel</Button>
                            </div>
                        </Form>
                        <template v-else>
                            <MentionText v-if="task.description" :text="task.description" />
                            <p v-else class="text-sm text-muted-foreground">No description.</p>
                        </template>
                    </CardContent>
                </Card>

                <!-- Subtasks -->
                <Card>
                    <CardHeader>
                        <CardTitle class="text-base">Subtasks</CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <ul v-if="task.subtasks.length" class="divide-y text-sm">
                            <li v-for="s in task.subtasks" :key="s.id" class="flex items-center justify-between gap-2 py-2">
                                <Link :href="show({ workspace: ws, task: s.id })" class="hover:underline" :class="{ 'line-through text-muted-foreground': s.status.category === 'done' }">{{ s.title }}</Link>
                                <LabelBadge :name="s.status.name" :color="s.status.color" />
                            </li>
                        </ul>
                        <QuickTaskForm v-if="can" :workspace-slug="ws" :departments="departments" :members="members" :parent-id="task.id" :project-id="task.project?.id ?? null" />
                    </CardContent>
                </Card>

                <!-- Comments -->
                <Card>
                    <CardHeader>
                        <CardTitle class="text-base">Discussion</CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <p v-if="task.comments.length === 0" class="text-sm text-muted-foreground">No comments yet.</p>
                        <div v-for="c in task.comments" :key="c.id" class="space-y-1 border-b pb-3 last:border-0">
                            <div class="flex items-center justify-between text-xs text-muted-foreground">
                                <span>
                                    <span class="font-medium text-foreground">{{ c.author?.name ?? 'Former member' }}</span>
                                    · {{ timeAgo(c.created_at) }}<span v-if="c.edited_at"> · edited</span>
                                </span>
                                <span class="flex gap-1">
                                    <button v-if="c.can_edit" type="button" class="hover:underline" @click="editingComment = c.id">Edit</button>
                                    <button v-if="c.can_delete" type="button" class="hover:underline" @click="deleteComment(c.id)">Delete</button>
                                </span>
                            </div>
                            <Form
                                v-if="editingComment === c.id"
                                v-bind="CommentController.update.form({ workspace: ws, comment: c.id })"
                                class="space-y-2"
                                preserve-scroll
                                @success="editingComment = null"
                            >
                                <MentionTextarea name="body" :members="members" :default-value="c.body" :rows="3" />
                                <div class="flex gap-2">
                                    <Button type="submit" size="sm">Save</Button>
                                    <Button type="button" size="sm" variant="ghost" @click="editingComment = null">Cancel</Button>
                                </div>
                            </Form>
                            <MentionText v-else :text="c.body" />
                        </div>

                        <Form
                            v-if="can"
                            v-bind="CommentController.store.form({ workspace: ws, task: task.id })"
                            v-slot="{ errors, processing }"
                            class="space-y-2"
                            preserve-scroll
                            reset-on-success
                        >
                            <MentionTextarea :key="task.comments.length" name="body" :members="members" :rows="3" placeholder="Write an update. Type @ to tag someone." />
                            <InputError :message="errors.body" />
                            <Button type="submit" size="sm" :disabled="processing">Comment</Button>
                        </Form>
                    </CardContent>
                </Card>
            </div>

            <div class="space-y-4">
                <!-- Quick fields -->
                <Card>
                    <CardContent class="space-y-3 pt-6 text-sm">
                        <div class="grid gap-1">
                            <Label for="q-status">Status</Label>
                            <NativeSelect id="q-status" :model-value="task.status.id" :disabled="!can" @update:model-value="(v) => setField('status_id', v)">
                                <option v-for="s in statuses" :key="s.id" :value="s.id">{{ s.name }}</option>
                            </NativeSelect>
                        </div>
                        <div class="grid gap-1">
                            <Label for="q-assignee">Assignee</Label>
                            <NativeSelect id="q-assignee" :model-value="task.assignee?.id ?? ''" :disabled="!can" @update:model-value="(v) => setField('assignee_id', v)">
                                <option value="">Unassigned</option>
                                <option v-for="m in members" :key="m.id" :value="m.id">{{ m.name }}</option>
                            </NativeSelect>
                        </div>
                        <div class="grid gap-1">
                            <Label for="q-dept">Department</Label>
                            <NativeSelect id="q-dept" :model-value="task.department?.id ?? ''" :disabled="!can" @update:model-value="(v) => setField('department_id', v)">
                                <option value="">None</option>
                                <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
                            </NativeSelect>
                        </div>
                        <div class="grid gap-1">
                            <Label for="q-due">Due</Label>
                            <Input id="q-due" type="date" :default-value="task.due_on ?? ''" :disabled="!can" @change="(e: Event) => setField('due_on', (e.target as HTMLInputElement).value)" />
                        </div>
                        <p class="text-xs text-muted-foreground">
                            Reported by {{ task.reporter?.name ?? '—' }} · {{ timeAgo(task.created_at) }}
                        </p>
                        <p class="text-xs text-muted-foreground">Watching: {{ task.watchers.map((w) => w.name).join(', ') || 'nobody' }}</p>
                    </CardContent>
                </Card>

                <TaskTimeCard v-if="time" :workspace-slug="ws" :task-id="task.id" :time="time" :can-log="can" />

                <WorkDetailsCard
                    :key="`${task.id}-${task.department?.id}`"
                    :workspace-slug="ws"
                    :task-id="task.id"
                    :department-name="task.department?.name ?? null"
                    :department-slug="task.department?.slug ?? null"
                    :fields="workDetailFields"
                    :values="task.work_details"
                    :can-edit="can"
                />

                <!-- Dependencies -->
                <Card>
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2 text-base"><Hourglass class="size-4" /> Waiting on</CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-3 text-sm">
                        <p v-if="task.dependencies.length === 0" class="text-muted-foreground">Nothing — free to finish.</p>
                        <ul v-else class="space-y-2">
                            <li v-for="d in task.dependencies" :key="d.id" class="flex items-center justify-between gap-2">
                                <span class="min-w-0">
                                    <Link :href="show({ workspace: ws, task: d.id })" class="hover:underline">{{ d.title }}</Link>
                                    <span class="block text-xs text-muted-foreground">{{ d.department?.name ?? '—' }} · {{ d.status.name }}</span>
                                </span>
                                <button v-if="can" type="button" class="rounded p-1 hover:bg-accent" :aria-label="`Stop waiting on ${d.title}`" @click="removeDependency(d)">
                                    <X class="size-3.5" />
                                </button>
                            </li>
                        </ul>
                        <NativeSelect v-if="can && dependencyOptions.length" model-value="" aria-label="Add a task this one waits on" @update:model-value="addDependency">
                            <option value="">+ Wait on another task…</option>
                            <option v-for="o in dependencyOptions" :key="o.id" :value="o.id">{{ o.title }}</option>
                        </NativeSelect>

                        <div v-if="task.dependents.length" class="border-t pt-3">
                            <div class="mb-1 text-xs font-medium text-muted-foreground">Blocks</div>
                            <ul class="space-y-1">
                                <li v-for="d in task.dependents" :key="d.id">
                                    <Link :href="show({ workspace: ws, task: d.id })" class="hover:underline">{{ d.title }}</Link>
                                    <span class="text-xs text-muted-foreground"> · {{ d.department?.name ?? '—' }}</span>
                                </li>
                            </ul>
                        </div>
                    </CardContent>
                </Card>

                <AttachmentsPanel attachable-type="task" :attachable-id="task.id" :attachments="attachments" :limits="fileLimits" :can-upload="can" />

                <Card>
                    <CardHeader>
                        <CardTitle class="text-base">References</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <ReferencePanel source-type="task" :source-id="task.id" :links="links" :can-edit="can" />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle class="text-base">Timeline</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <ul class="space-y-2 text-xs">
                            <li v-for="e in timeline" :key="e.id">
                                <span class="font-medium">{{ e.actor ?? 'System' }}</span>
                                {{ describe(e) }}
                                <span class="text-muted-foreground"> · {{ timeAgo(e.at) }}</span>
                            </li>
                        </ul>
                    </CardContent>
                </Card>
            </div>
        </div>
    </div>
</template>
