<script setup lang="ts">
import { Form, Head, Link, router } from '@inertiajs/vue3';
import { Pencil, Trash2, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import ProjectController from '@/actions/App/Http/Controllers/Workspaces/ProjectController';
import LabelBadge from '@/components/LabelBadge.vue';
import MentionText from '@/components/MentionText.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import AttachmentsPanel from '@/components/files/AttachmentsPanel.vue';
import ProjectBudgetCard from '@/components/projects/ProjectBudgetCard.vue';
import ReferencePanel from '@/components/ReferencePanel.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import WorkspaceHeader from '@/components/WorkspaceHeader.vue';
import QuickTaskForm from '@/components/work/QuickTaskForm.vue';
import TaskMeta from '@/components/work/TaskMeta.vue';
import { confirmDialog } from '@/composables/useConfirm';
import {
    formatDate,
    formatMinutes,
    isOverdue,
    projectStatusColor,
    timeAgo,
} from '@/lib/format';
import { index as workspacesIndex } from '@/routes/workspaces';
import {
    index as projectsIndex,
    statusDetail as statusDetailRoute,
    statusLink as statusLinkRoute,
} from '@/routes/workspaces/projects';
import { destroy as statusLinkDestroy } from '@/routes/workspaces/projects/status-link';
import { board, show as taskShow } from '@/routes/workspaces/tasks';
import type { DepartmentOption } from '@/types/admin';
import type { TaskRow } from '@/types/work';
import type { AttachmentRow, FileLimits } from '@/types/files';
import type {
    LabelOption,
    LinkRow,
    MemberOption,
    ProjectBudget,
    ProjectDetail,
    WorkspaceHeader as Header,
} from '@/types/workspace';
import ProjectFields from './partials/ProjectFields.vue';

const props = defineProps<{
    workspace: Header;
    project: ProjectDetail;
    links: LinkRow[];
    budget: ProjectBudget | null;
    types: Record<string, string>;
    statuses: Record<string, string>;
    labels: LabelOption[];
    members: MemberOption[];
    canDelete: boolean;
    attachments: AttachmentRow[];
    fileLimits: FileLimits;
    tasks: (TaskRow & { estimate_minutes: number | null })[];
    progress: {
        total: number;
        done: number;
        overdue: number;
        percent: number;
        estimate_minutes: number;
        estimate_done_minutes: number;
        logged_minutes: number;
        by_status: { name: string; color: string; count: number }[];
    };
    taskFiles: AttachmentRow[];
    departments: DepartmentOption[];
}>();

const showDone = ref(false);
const visibleTasks = computed(() =>
    showDone.value
        ? props.tasks
        : props.tasks.filter((t) => t.status.category !== 'done'),
);

// B-06: client status link.
const statusExpiry = ref('');
const copiedLink = ref(false);
async function makeStatusLink(): Promise<void> {
    if (
        props.project.status_link &&
        !(await confirmDialog('Create a new link? The old link stops working.'))
    ) {
        return;
    }
    router.post(
        statusLinkRoute.url({
            workspace: props.workspace.slug,
            project: props.project.id,
        }),
        {
            expires_days: statusExpiry.value
                ? Number(statusExpiry.value)
                : null,
        },
        { preserveScroll: true },
    );
}
async function stopStatusLink(): Promise<void> {
    if (
        await confirmDialog(
            'Turn off the client status link? Anyone with it will see "not found".',
            { danger: true },
        )
    ) {
        router.delete(
            statusLinkDestroy.url({
                workspace: props.workspace.slug,
                project: props.project.id,
            }),
            { preserveScroll: true },
        );
    }
}
// R-22: simple wording (default) or our real statuses and late dates.
function setStatusDetail(value: string): void {
    router.put(
        statusDetailRoute.url({
            workspace: props.workspace.slug,
            project: props.project.id,
        }),
        { status_detail: value },
        { preserveScroll: true },
    );
}
function copyStatusLink(): void {
    void navigator.clipboard
        ?.writeText(props.project.status_link?.url ?? '')
        .then(() => {
            copiedLink.value = true;
            window.setTimeout(() => (copiedLink.value = false), 1500);
        });
}

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Workspaces', href: workspacesIndex() }],
    },
});

const editing = ref(false);

async function destroy(): Promise<void> {
    if (
        !(await confirmDialog(
            `Delete "${props.project.name}"? It can be restored by an admin from the database.`,
        ))
    ) {
        return;
    }

    router.delete(
        ProjectController.destroy.url({
            workspace: props.workspace.slug,
            project: props.project.id,
        }),
    );
}
</script>

<template>
    <Head :title="project.name" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <WorkspaceHeader :workspace="workspace" />

        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <Link
                    :href="projectsIndex(workspace.slug)"
                    class="text-xs text-muted-foreground hover:underline"
                    >← Projects</Link
                >
                <h2 class="text-lg font-semibold">{{ project.name }}</h2>
                <div
                    class="mt-1 flex flex-wrap items-center gap-2 text-sm text-muted-foreground"
                >
                    <span>{{ types[project.type] ?? project.type }}</span>
                    <LabelBadge
                        :name="statuses[project.status] ?? project.status"
                        :color="projectStatusColor[project.status] ?? 'slate'"
                    />
                    <LabelBadge
                        v-for="l in project.labels"
                        :key="l.id"
                        :name="l.name"
                        :color="l.color"
                    />
                </div>
            </div>
            <div class="flex gap-2">
                <Button
                    v-if="workspace.can.contribute && !editing"
                    variant="outline"
                    @click="editing = true"
                >
                    <Pencil /> Edit
                </Button>
                <Button
                    v-if="canDelete"
                    variant="ghost"
                    aria-label="Delete project"
                    @click="destroy"
                >
                    <Trash2 />
                </Button>
            </div>
        </div>

        <Card v-if="editing">
            <CardHeader>
                <CardTitle class="text-base">Edit project</CardTitle>
            </CardHeader>
            <CardContent>
                <Form
                    v-bind="
                        ProjectController.update.form({
                            workspace: workspace.slug,
                            project: project.id,
                        })
                    "
                    v-slot="{ errors, processing }"
                    class="flex flex-col gap-4"
                    :options="{ preserveScroll: true }"
                    @success="editing = false"
                >
                    <ProjectFields
                        :project="project"
                        :types="types"
                        :statuses="statuses"
                        :labels="labels"
                        :members="members"
                        :departments="departments"
                        :errors="errors"
                    />
                    <div class="flex gap-2">
                        <Button type="submit" :disabled="processing"
                            ><Spinner v-if="processing" /> Save</Button
                        >
                        <Button
                            type="button"
                            variant="ghost"
                            @click="editing = false"
                            ><X /> Cancel</Button
                        >
                    </div>
                </Form>
            </CardContent>
        </Card>

        <div v-else class="grid gap-4 lg:grid-cols-3">
            <Card class="lg:col-span-2">
                <CardHeader>
                    <CardTitle class="text-base">Brief</CardTitle>
                    <CardDescription v-if="project.mentioned.length">
                        Tagged: {{ project.mentioned.join(', ') }}
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <MentionText
                        v-if="project.description"
                        :text="project.description"
                    />
                    <p v-else class="text-sm text-muted-foreground">
                        No brief yet.
                    </p>
                </CardContent>
            </Card>

            <Card v-if="project.can_share_status">
                <CardHeader>
                    <CardTitle class="text-base">Client status page</CardTitle>
                    <CardDescription
                        >A read-only link the client can open without logging
                        in: progress, milestones, what's coming up. No names,
                        hours or comments.</CardDescription
                    >
                </CardHeader>
                <CardContent class="space-y-2 text-sm">
                    <template v-if="project.status_link">
                        <div class="flex gap-2">
                            <input
                                :value="project.status_link.url"
                                readonly
                                class="h-8 min-w-0 flex-1 rounded-md border bg-muted/40 px-2 text-xs"
                                aria-label="Client status link"
                                @focus="
                                    (e) =>
                                        (e.target as HTMLInputElement).select()
                                "
                            />
                            <Button
                                size="sm"
                                variant="outline"
                                @click="copyStatusLink"
                                >{{ copiedLink ? 'Copied' : 'Copy' }}</Button
                            >
                        </div>
                        <p class="text-xs text-muted-foreground">
                            Opened {{ project.status_link.views }} time{{
                                project.status_link.views === 1 ? '' : 's'
                            }}<template v-if="project.status_link.expires_at">
                                · expires
                                {{
                                    formatDate(
                                        project.status_link.expires_at.slice(
                                            0,
                                            10,
                                        ),
                                    )
                                }}</template
                            >
                        </p>
                        <label class="grid gap-1 text-xs">
                            <span class="text-muted-foreground"
                                >What the client sees</span
                            >
                            <NativeSelect
                                :model-value="
                                    project.status_link.detail ?? 'simple'
                                "
                                class="h-8 text-xs"
                                @update:model-value="
                                    (v) => setStatusDetail(String(v))
                                "
                            >
                                <option value="simple">
                                    Simple: Planned / In progress / Done, no
                                    late dates
                                </option>
                                <option value="detailed">
                                    Detailed: our status names and late dates
                                </option>
                            </NativeSelect>
                        </label>
                        <div class="flex flex-wrap gap-2">
                            <Button
                                size="sm"
                                variant="outline"
                                :as="'a'"
                                :href="project.status_link.url"
                                target="_blank"
                                rel="noopener"
                                >Preview</Button
                            >
                            <Button
                                size="sm"
                                variant="ghost"
                                @click="makeStatusLink"
                                >New link</Button
                            >
                            <Button
                                size="sm"
                                variant="ghost"
                                class="text-destructive"
                                @click="stopStatusLink"
                                >Turn off</Button
                            >
                        </div>
                    </template>
                    <div v-else class="flex flex-wrap items-center gap-2">
                        <NativeSelect
                            v-model="statusExpiry"
                            class="h-8 w-36 text-xs"
                            aria-label="Link expires"
                        >
                            <option value="">Never expires</option>
                            <option value="7">Expires in 7 days</option>
                            <option value="30">Expires in 30 days</option>
                            <option value="90">Expires in 90 days</option>
                        </NativeSelect>
                        <Button size="sm" @click="makeStatusLink"
                            >Create link</Button
                        >
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle class="text-base">Details</CardTitle>
                </CardHeader>
                <CardContent class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-muted-foreground">Lead</span>
                        <span>{{ project.lead ?? '—' }}</span>
                    </div>
                    <div
                        v-if="project.involved_departments?.length"
                        class="flex justify-between gap-2"
                    >
                        <span class="text-muted-foreground">Departments</span>
                        <span class="text-right">{{
                            project.involved_departments
                                .map((d) => d.name)
                                .join(', ')
                        }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-muted-foreground">Start</span>
                        <span>{{ formatDate(project.start_on) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-muted-foreground">Due</span>
                        <span
                            :class="
                                isOverdue(project.due_on) &&
                                project.status !== 'completed'
                                    ? 'font-medium text-rose-600'
                                    : ''
                            "
                            >{{ formatDate(project.due_on) }}</span
                        >
                    </div>
                    <div class="flex justify-between">
                        <span class="text-muted-foreground">Updated</span>
                        <span>{{ timeAgo(project.updated_at) }}</span>
                    </div>
                    <div class="pt-2">
                        <div class="mb-1 text-muted-foreground">Team</div>
                        <p
                            v-if="project.members.length === 0"
                            class="text-muted-foreground"
                        >
                            Nobody yet.
                        </p>
                        <ul v-else class="space-y-1">
                            <li v-for="m in project.members" :key="m.id">
                                {{ m.name }}
                                <span
                                    v-if="m.title"
                                    class="text-xs text-muted-foreground"
                                    >· {{ m.title }}</span
                                >
                            </li>
                        </ul>
                    </div>
                </CardContent>
            </Card>
        </div>

        <!-- E-76: budget and burn (leads and Finance only) -->
        <ProjectBudgetCard
            v-if="!editing && budget"
            :budget="budget"
            :workspace-slug="workspace.slug"
            :project-id="project.id"
        />

        <!-- Progress + tasks -->
        <Card v-if="!editing">
            <CardHeader class="gap-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <CardTitle class="text-base"
                        >Tasks &amp; progress</CardTitle
                    >
                    <Link
                        :href="
                            board.url(workspace.slug, {
                                query: { project: String(project.id) },
                            })
                        "
                        class="text-xs hover:underline"
                        >Open on board →</Link
                    >
                </div>
                <div class="space-y-2">
                    <div class="flex items-baseline justify-between text-sm">
                        <span class="font-medium"
                            >{{ progress.percent }}% done</span
                        >
                        <span class="text-xs text-muted-foreground">
                            {{ progress.done }} of {{ progress.total }} tasks
                            <template v-if="progress.overdue">
                                ·
                                <span class="font-medium text-rose-600"
                                    >{{ progress.overdue }} overdue</span
                                ></template
                            >
                            <template v-if="progress.estimate_minutes">
                                ·
                                {{
                                    formatMinutes(
                                        progress.estimate_done_minutes,
                                    )
                                }}
                                of
                                {{ formatMinutes(progress.estimate_minutes) }}
                                estimated done</template
                            >
                            ·
                            {{ formatMinutes(progress.logged_minutes) }} logged
                        </span>
                    </div>
                    <div
                        class="h-2 overflow-hidden rounded-full bg-muted"
                        role="progressbar"
                        :aria-valuenow="progress.percent"
                        aria-valuemin="0"
                        aria-valuemax="100"
                    >
                        <div
                            class="h-full rounded-full bg-emerald-500 transition-all"
                            :style="{ width: `${progress.percent}%` }"
                        />
                    </div>
                    <div class="flex flex-wrap gap-1.5">
                        <LabelBadge
                            v-for="s in progress.by_status"
                            :key="s.name"
                            :name="`${s.name} ${s.count}`"
                            :color="s.color"
                        />
                    </div>
                </div>
            </CardHeader>
            <CardContent class="space-y-3">
                <QuickTaskForm
                    v-if="workspace.can.contribute"
                    :workspace-slug="workspace.slug"
                    :departments="departments"
                    :members="members"
                    :project-id="project.id"
                />
                <p
                    v-if="tasks.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    No tasks in this project yet.
                </p>
                <ul v-else class="divide-y text-sm">
                    <li
                        v-for="t in visibleTasks"
                        :key="t.id"
                        class="flex items-start justify-between gap-3 py-2"
                    >
                        <div class="min-w-0 space-y-1">
                            <Link
                                :href="
                                    taskShow({
                                        workspace: workspace.slug,
                                        task: t.id,
                                    })
                                "
                                class="font-medium hover:underline"
                                :class="{
                                    'text-muted-foreground line-through':
                                        t.status.category === 'done',
                                }"
                            >
                                <span class="text-xs text-muted-foreground"
                                    >#{{ t.id }}</span
                                >
                                {{ t.title }}
                            </Link>
                            <TaskMeta :task="{ ...t, project: null }" />
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <span
                                v-if="t.estimate_minutes"
                                class="text-xs text-muted-foreground tabular-nums"
                                >{{ formatMinutes(t.estimate_minutes) }}</span
                            >
                            <LabelBadge
                                :name="t.status.name"
                                :color="t.status.color"
                            />
                        </div>
                    </li>
                </ul>
                <button
                    v-if="progress.done > 0"
                    type="button"
                    class="text-xs text-muted-foreground hover:underline"
                    @click="showDone = !showDone"
                >
                    {{
                        showDone
                            ? 'Hide done tasks'
                            : `Show ${progress.done} done task(s)`
                    }}
                </button>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle class="text-base">References</CardTitle>
                <CardDescription>
                    Connect this work to related projects or campaigns,
                    including other clients' workspaces. Backlinks show
                    automatically.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <ReferencePanel
                    source-type="project"
                    :source-id="project.id"
                    :links="links"
                    :can-edit="workspace.can.contribute"
                />
            </CardContent>
        </Card>

        <AttachmentsPanel
            attachable-type="project"
            :attachable-id="project.id"
            :attachments="attachments"
            :limits="fileLimits"
            :can-upload="workspace.can.contribute"
        />

        <AttachmentsPanel
            v-if="taskFiles.length"
            attachable-type="project"
            :attachable-id="project.id"
            :attachments="taskFiles"
            :limits="fileLimits"
            :can-upload="false"
            title="Files on this project's tasks"
            description="Every file uploaded to the project's tasks. Approved files first."
        />
    </div>
</template>
