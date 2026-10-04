<script setup lang="ts">
import { Form, Head, Link, router } from '@inertiajs/vue3';
import { Plus, X } from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import { reactive, ref, watch } from 'vue';
import ProjectController from '@/actions/App/Http/Controllers/Workspaces/ProjectController';
import LabelBadge from '@/components/LabelBadge.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import WorkspaceHeader from '@/components/WorkspaceHeader.vue';
import { formatDate, isOverdue, projectStatusColor } from '@/lib/format';
import { index as workspacesIndex } from '@/routes/workspaces';
import { index, show } from '@/routes/workspaces/projects';
import type { Paginated } from '@/types/admin';
import type {
    LabelOption,
    MemberOption,
    ProjectRow,
    WorkspaceHeader as Header,
} from '@/types/workspace';
import ProjectFields from './partials/ProjectFields.vue';

const props = defineProps<{
    workspace: Header;
    projects: Paginated<ProjectRow>;
    filters: { search?: string; type?: string; status?: string; label?: number | string; mine?: boolean | string };
    types: Record<string, string>;
    statuses: Record<string, string>;
    labels: LabelOption[];
    members: MemberOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Workspaces', href: workspacesIndex() }],
    },
});

const creating = ref(false);

const form = reactive({
    search: props.filters.search ?? '',
    type: props.filters.type ?? '',
    status: props.filters.status ?? '',
    label: props.filters.label ? String(props.filters.label) : '',
    mine: props.filters.mine ? '1' : '',
});

const apply = useDebounceFn(() => {
    const query = Object.fromEntries(Object.entries(form).filter(([, v]) => v !== ''));
    router.get(index.url(props.workspace.slug, { query }), {}, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}, 300);

watch(form, apply);
</script>

<template>
    <Head :title="`${workspace.name} · Projects`" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <WorkspaceHeader :workspace="workspace" />

        <div class="flex flex-wrap items-center gap-2">
            <Input v-model="form.search" type="search" class="w-56" placeholder="Search projects" aria-label="Search projects" />
            <NativeSelect v-model="form.type" class="w-44" aria-label="Type">
                <option value="">All types</option>
                <option v-for="(label, key) in types" :key="key" :value="key">{{ label }}</option>
            </NativeSelect>
            <NativeSelect v-model="form.status" class="w-40" aria-label="Status">
                <option value="">Open & done</option>
                <option v-for="(label, key) in statuses" :key="key" :value="key">{{ label }}</option>
            </NativeSelect>
            <NativeSelect v-if="labels.length" v-model="form.label" class="w-40" aria-label="Label">
                <option value="">Any label</option>
                <option v-for="l in labels" :key="l.id" :value="String(l.id)">{{ l.name }}</option>
            </NativeSelect>
            <label class="inline-flex items-center gap-2 text-sm">
                <input v-model="form.mine" type="checkbox" true-value="1" false-value="" class="size-4" />
                Mine
            </label>
            <Button v-if="workspace.can.contribute && !creating" class="ml-auto" @click="creating = true">
                <Plus /> New project
            </Button>
        </div>

        <Card v-if="creating">
            <CardHeader>
                <CardTitle class="text-base">New project / campaign</CardTitle>
            </CardHeader>
            <CardContent>
                <Form
                    v-bind="ProjectController.store.form(workspace.slug)"
                    v-slot="{ errors, processing }"
                    class="flex flex-col gap-4"
                >
                    <ProjectFields
                        :types="types"
                        :statuses="statuses"
                        :labels="labels"
                        :members="members"
                        :errors="errors"
                    />
                    <div class="flex gap-2">
                        <Button type="submit" :disabled="processing">
                            <Spinner v-if="processing" /> Create
                        </Button>
                        <Button type="button" variant="ghost" @click="creating = false"><X /> Cancel</Button>
                    </div>
                </Form>
            </CardContent>
        </Card>

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-left text-sm">
                <thead class="bg-muted/50 text-xs text-muted-foreground uppercase">
                    <tr>
                        <th class="px-4 py-2 font-medium">Name</th>
                        <th class="px-4 py-2 font-medium">Type</th>
                        <th class="px-4 py-2 font-medium">Status</th>
                        <th class="px-4 py-2 font-medium">Lead</th>
                        <th class="px-4 py-2 font-medium">Due</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-if="projects.data.length === 0">
                        <td colspan="5" class="px-4 py-8 text-center text-muted-foreground">
                            No projects match.
                        </td>
                    </tr>
                    <tr v-for="p in projects.data" :key="p.id">
                        <td class="px-4 py-2">
                            <Link
                                :href="show({ workspace: workspace.slug, project: p.id })"
                                class="font-medium hover:underline"
                                >{{ p.name }}</Link
                            >
                            <div v-if="p.labels.length" class="mt-1 flex flex-wrap gap-1">
                                <LabelBadge v-for="l in p.labels" :key="l.id" :name="l.name" :color="l.color" />
                            </div>
                        </td>
                        <td class="px-4 py-2 text-muted-foreground">{{ types[p.type] ?? p.type }}</td>
                        <td class="px-4 py-2">
                            <LabelBadge
                                :name="statuses[p.status] ?? p.status"
                                :color="projectStatusColor[p.status] ?? 'slate'"
                            />
                        </td>
                        <td class="px-4 py-2">
                            {{ p.lead ?? '—' }}
                            <span class="text-xs text-muted-foreground">· {{ p.members }} people</span>
                        </td>
                        <td
                            class="px-4 py-2 whitespace-nowrap"
                            :class="isOverdue(p.due_on) && p.status !== 'completed' ? 'font-medium text-rose-600' : 'text-muted-foreground'"
                        >
                            {{ formatDate(p.due_on) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :links="projects.links" :from="projects.from" :to="projects.to" :total="projects.total" />
    </div>
</template>
