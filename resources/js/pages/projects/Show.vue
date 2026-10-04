<script setup lang="ts">
import { Form, Head, Link, router } from '@inertiajs/vue3';
import { Pencil, Trash2, X } from '@lucide/vue';
import { ref } from 'vue';
import ProjectController from '@/actions/App/Http/Controllers/Workspaces/ProjectController';
import LabelBadge from '@/components/LabelBadge.vue';
import MentionText from '@/components/MentionText.vue';
import ReferencePanel from '@/components/ReferencePanel.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import WorkspaceHeader from '@/components/WorkspaceHeader.vue';
import { formatDate, isOverdue, projectStatusColor, timeAgo } from '@/lib/format';
import { index as workspacesIndex } from '@/routes/workspaces';
import { index as projectsIndex } from '@/routes/workspaces/projects';
import type {
    LabelOption,
    LinkRow,
    MemberOption,
    ProjectDetail,
    WorkspaceHeader as Header,
} from '@/types/workspace';
import ProjectFields from './partials/ProjectFields.vue';

const props = defineProps<{
    workspace: Header;
    project: ProjectDetail;
    links: LinkRow[];
    types: Record<string, string>;
    statuses: Record<string, string>;
    labels: LabelOption[];
    members: MemberOption[];
    canDelete: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Workspaces', href: workspacesIndex() }],
    },
});

const editing = ref(false);

function destroy(): void {
    if (!window.confirm(`Delete "${props.project.name}"? It can be restored by an admin from the database.`)) {
        return;
    }

    router.delete(
        ProjectController.destroy.url({ workspace: props.workspace.slug, project: props.project.id }),
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
                <div class="mt-1 flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                    <span>{{ types[project.type] ?? project.type }}</span>
                    <LabelBadge
                        :name="statuses[project.status] ?? project.status"
                        :color="projectStatusColor[project.status] ?? 'slate'"
                    />
                    <LabelBadge v-for="l in project.labels" :key="l.id" :name="l.name" :color="l.color" />
                </div>
            </div>
            <div class="flex gap-2">
                <Button v-if="workspace.can.contribute && !editing" variant="outline" @click="editing = true">
                    <Pencil /> Edit
                </Button>
                <Button v-if="canDelete" variant="ghost" aria-label="Delete project" @click="destroy">
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
                    v-bind="ProjectController.update.form({ workspace: workspace.slug, project: project.id })"
                    v-slot="{ errors, processing }"
                    class="flex flex-col gap-4"
                    preserve-scroll
                    @success="editing = false"
                >
                    <ProjectFields
                        :project="project"
                        :types="types"
                        :statuses="statuses"
                        :labels="labels"
                        :members="members"
                        :errors="errors"
                    />
                    <div class="flex gap-2">
                        <Button type="submit" :disabled="processing"><Spinner v-if="processing" /> Save</Button>
                        <Button type="button" variant="ghost" @click="editing = false"><X /> Cancel</Button>
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
                    <MentionText v-if="project.description" :text="project.description" />
                    <p v-else class="text-sm text-muted-foreground">No brief yet.</p>
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
                    <div class="flex justify-between">
                        <span class="text-muted-foreground">Start</span>
                        <span>{{ formatDate(project.start_on) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-muted-foreground">Due</span>
                        <span
                            :class="isOverdue(project.due_on) && project.status !== 'completed' ? 'font-medium text-rose-600' : ''"
                            >{{ formatDate(project.due_on) }}</span
                        >
                    </div>
                    <div class="flex justify-between">
                        <span class="text-muted-foreground">Updated</span>
                        <span>{{ timeAgo(project.updated_at) }}</span>
                    </div>
                    <div class="pt-2">
                        <div class="mb-1 text-muted-foreground">Team</div>
                        <p v-if="project.members.length === 0" class="text-muted-foreground">Nobody yet.</p>
                        <ul v-else class="space-y-1">
                            <li v-for="m in project.members" :key="m.id">
                                {{ m.name }}
                                <span v-if="m.title" class="text-xs text-muted-foreground">· {{ m.title }}</span>
                            </li>
                        </ul>
                    </div>
                </CardContent>
            </Card>
        </div>

        <Card>
            <CardHeader>
                <CardTitle class="text-base">References</CardTitle>
                <CardDescription>
                    Connect this work to related projects or campaigns, including other clients'
                    workspaces. Backlinks show automatically.
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
    </div>
</template>
