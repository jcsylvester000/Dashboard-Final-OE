<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import LabelBadge from '@/components/LabelBadge.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import WorkspaceHeader from '@/components/WorkspaceHeader.vue';
import ApplyTemplatePanel from '@/components/work/ApplyTemplatePanel.vue';
import QuickTaskForm from '@/components/work/QuickTaskForm.vue';
import SavedViews from '@/components/work/SavedViews.vue';
import TaskFilterBar from '@/components/work/TaskFilterBar.vue';
import TaskMeta from '@/components/work/TaskMeta.vue';
import { confirmDialog } from '@/composables/useConfirm';
import { index as workspacesIndex } from '@/routes/workspaces';
import {
    bulk,
    index,
    show,
    update as updateTask,
} from '@/routes/workspaces/tasks';
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

// Group-by (brutal scenario M1). The server sorts by the group first, so groups stay together.
const groupLabel = (t: TaskRow): string => {
    switch (props.filters.group) {
        case 'status':
            return t.status.name;
        case 'assignee':
            return t.assignee?.name ?? 'Unassigned';
        case 'project':
            return t.project?.name ?? 'No project';
        case 'department':
            return t.department?.name ?? 'No department';
        case 'sprint':
            return t.sprint?.name ?? 'Backlog';
        case 'priority':
            return props.priorities[t.priority] ?? t.priority;
        default:
            return '';
    }
};
const groups = computed(() => {
    const out: { label: string; tasks: TaskRow[] }[] = [];
    for (const t of props.tasks.data) {
        const label = groupLabel(t);
        const last = out[out.length - 1];
        if (last && last.label === label) {
            last.tasks.push(t);
        } else {
            out.push({ label, tasks: [t] });
        }
    }

    return out;
});

// Inline edit: change status, owner, due date or priority right in the row.
const resetKey = ref(0);
function setField(
    t: TaskRow,
    field: 'status_id' | 'assignee_id' | 'due_on' | 'priority',
    value: unknown,
): void {
    const v =
        value === '' || value === null || value === undefined
            ? null
            : field === 'status_id' || field === 'assignee_id'
              ? Number(value)
              : String(value);
    router.put(
        updateTask.url({ workspace: props.workspace.slug, task: t.id }),
        { [field]: v },
        {
            preserveScroll: true,
            preserveState: true,
            onError: (errors) => {
                toast.error(Object.values(errors)[0] ?? 'Could not save.');
                // The props didn't change, so re-mount the row's controls to show the saved values again.
                resetKey.value++;
            },
        },
    );
}

// Bulk edit (brutal scenarios M2 / J3): tick tasks, pick changes, apply once.
const selected = ref<number[]>([]);
const allOnPage = computed(
    () =>
        props.tasks.data.length > 0 &&
        props.tasks.data.every((t) => selected.value.includes(t.id)),
);
const blankChanges = () => ({
    status_id: '',
    assignee_id: '',
    priority: '',
    type: '',
    project_id: '',
    sprint_id: '',
    story_points: '',
    due_on: '',
    due_shift_days: '',
});
const changes = reactive(blankChanges());
const busy = ref(false);
const hasChange = computed(() => Object.values(changes).some((v) => v !== ''));

watch(
    () => props.tasks.data,
    () =>
        (selected.value = selected.value.filter((id) =>
            props.tasks.data.some((t) => t.id === id),
        )),
);

function toggleAll(): void {
    selected.value = allOnPage.value ? [] : props.tasks.data.map((t) => t.id);
}

async function send(action: 'update' | 'delete'): Promise<void> {
    if (
        action === 'delete' &&
        !(await confirmDialog(
            `Delete ${selected.value.length} task(s)? This can't be undone from here.`,
        ))
    ) {
        return;
    }
    const payload: Record<string, unknown> = { ids: selected.value, action };
    if (action === 'update') {
        for (const [k, v] of Object.entries(changes)) {
            if (v !== '') {
                payload[k] = [
                    'due_shift_days',
                    'status_id',
                    'story_points',
                ].includes(k)
                    ? Number(v)
                    : v;
            }
        }
    }
    busy.value = true;
    router.post(bulk.url(props.workspace.slug), payload as never, {
        preserveScroll: true,
        onSuccess: () => {
            selected.value = [];
            Object.assign(changes, blankChanges());
        },
        onFinish: () => (busy.value = false),
    });
}
</script>

<template>
    <Head :title="`${workspace.name} · Tasks`" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <WorkspaceHeader :workspace="workspace" />

        <div
            v-if="workspace.can.contribute"
            class="flex flex-wrap items-start justify-between gap-3"
        >
            <QuickTaskForm
                class="flex-1"
                :workspace-slug="workspace.slug"
                :departments="departments"
                :members="members"
                :projects="projects"
                :project-id="filters.project ?? null"
                full
            />
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
            :sprints="sprintOptions"
            show-status
            show-group
        />
        <SavedViews
            :workspace-slug="workspace.slug"
            page="list"
            :views="views"
            :filters="filters"
            :can-share="workspace.can.contribute"
        />

        <div
            v-if="selected.length"
            class="sticky top-2 z-10 flex flex-wrap items-center gap-2 rounded-lg border bg-background/95 p-2 text-sm shadow-sm backdrop-blur"
            role="region"
            aria-label="Bulk edit"
        >
            <span class="px-1 font-medium">{{ selected.length }} selected</span>
            <NativeSelect
                v-model="changes.status_id"
                class="h-8 w-36"
                aria-label="Set status"
            >
                <option value="">Status…</option>
                <option
                    v-for="st in statuses"
                    :key="st.id"
                    :value="String(st.id)"
                >
                    {{ st.name }}
                </option>
            </NativeSelect>
            <NativeSelect
                v-model="changes.assignee_id"
                class="h-8 w-40"
                aria-label="Set owner"
            >
                <option value="">Owner…</option>
                <option value="none">Unassigned</option>
                <option v-for="m in members" :key="m.id" :value="String(m.id)">
                    {{ m.name }}
                </option>
            </NativeSelect>
            <NativeSelect
                v-model="changes.priority"
                class="h-8 w-32"
                aria-label="Set priority"
            >
                <option value="">Priority…</option>
                <option
                    v-for="(label, key) in priorities"
                    :key="key"
                    :value="key"
                >
                    {{ label }}
                </option>
            </NativeSelect>
            <NativeSelect
                v-model="changes.type"
                class="h-8 w-28"
                aria-label="Set type"
            >
                <option value="">Type…</option>
                <option value="task">Task</option>
                <option value="story">Story</option>
                <option value="bug">Bug</option>
                <option value="chore">Chore</option>
                <option value="milestone">Milestone</option>
            </NativeSelect>
            <NativeSelect
                v-model="changes.project_id"
                class="h-8 w-40"
                aria-label="Set project"
            >
                <option value="">Project…</option>
                <option value="none">No project</option>
                <option v-for="p in projects" :key="p.id" :value="String(p.id)">
                    {{ p.name }}
                </option>
            </NativeSelect>
            <NativeSelect
                v-model="changes.sprint_id"
                class="h-8 w-36"
                aria-label="Move to sprint"
            >
                <option value="">Sprint…</option>
                <option value="none">Backlog</option>
                <option
                    v-for="sp in sprintOptions"
                    :key="sp.id"
                    :value="String(sp.id)"
                >
                    {{ sp.name }}
                </option>
            </NativeSelect>
            <NativeSelect
                v-model="changes.story_points"
                class="h-8 w-28"
                aria-label="Set story points"
            >
                <option value="">Points…</option>
                <option
                    v-for="p in [0, 1, 2, 3, 5, 8, 13, 21]"
                    :key="p"
                    :value="String(p)"
                >
                    {{ p }}
                </option>
            </NativeSelect>
            <Input
                v-model="changes.due_on"
                type="date"
                class="h-8 w-40"
                aria-label="Set due date"
                title="Set due date"
                :disabled="changes.due_shift_days !== ''"
            />
            <NativeSelect
                v-model="changes.due_shift_days"
                class="h-8 w-36"
                aria-label="Shift due dates"
                :disabled="changes.due_on !== ''"
            >
                <option value="">Shift due…</option>
                <option value="-1">1 day earlier</option>
                <option value="1">+1 day</option>
                <option value="3">+3 days</option>
                <option value="7">+1 week</option>
                <option value="14">+2 weeks</option>
            </NativeSelect>
            <Button
                size="sm"
                :disabled="busy || !hasChange"
                @click="send('update')"
                >Apply</Button
            >
            <Button
                size="sm"
                variant="ghost"
                class="text-destructive"
                :disabled="busy"
                @click="send('delete')"
                >Delete</Button
            >
            <Button size="sm" variant="ghost" @click="selected = []"
                >Clear</Button
            >
        </div>

        <div class="overflow-hidden rounded-lg border">
            <p
                v-if="tasks.data.length === 0"
                class="p-8 text-center text-sm text-muted-foreground"
            >
                No tasks match. Add one above or start a workflow.
            </p>
            <ul v-else class="divide-y">
                <li
                    v-if="workspace.can.contribute"
                    class="flex items-center gap-3 bg-muted/40 px-4 py-1.5 text-xs text-muted-foreground"
                >
                    <input
                        type="checkbox"
                        class="size-4"
                        :checked="allOnPage"
                        aria-label="Select all on this page"
                        @change="toggleAll"
                    />
                    <span>Select all on this page to change many at once</span>
                </li>
                <template v-for="g in groups" :key="g.label + g.tasks[0].id">
                    <li
                        v-if="filters.group"
                        class="sticky top-0 z-[1] flex items-center gap-2 bg-muted px-4 py-1.5 text-xs font-semibold tracking-wide uppercase"
                    >
                        {{ g.label }}
                        <span
                            class="font-normal text-muted-foreground normal-case"
                            >· {{ g.tasks.length }}</span
                        >
                    </li>
                    <li
                        v-for="t in g.tasks"
                        :key="t.id"
                        class="flex flex-wrap items-start justify-between gap-3 px-4 py-3 sm:flex-nowrap"
                        :class="{ 'bg-accent/40': selected.includes(t.id) }"
                    >
                        <input
                            v-if="workspace.can.contribute"
                            v-model="selected"
                            type="checkbox"
                            class="mt-1 size-4 shrink-0"
                            :value="t.id"
                            :aria-label="`Select ${t.key} ${t.title}`"
                        />
                        <div class="min-w-0 flex-1 space-y-1">
                            <Link
                                prefetch
                                :href="
                                    show({
                                        workspace: workspace.slug,
                                        task: t.id,
                                    })
                                "
                                class="font-medium hover:underline"
                                :class="{
                                    'text-muted-foreground line-through':
                                        t.status.category === 'done',
                                }"
                                >{{ t.title }}</Link
                            >
                            <TaskMeta :task="t" />
                        </div>
                        <div
                            v-if="workspace.can.contribute"
                            :key="`${t.id}-${resetKey}`"
                            class="flex shrink-0 flex-wrap items-center gap-1.5"
                            @click.stop
                        >
                            <NativeSelect
                                :model-value="String(t.status.id)"
                                class="h-8 w-32 text-xs"
                                :aria-label="`Status of ${t.key}`"
                                @update:model-value="
                                    (v) => setField(t, 'status_id', v)
                                "
                            >
                                <option
                                    v-for="st in statuses"
                                    :key="st.id"
                                    :value="String(st.id)"
                                >
                                    {{ st.name }}
                                </option>
                            </NativeSelect>
                            <NativeSelect
                                :model-value="
                                    t.assignee ? String(t.assignee.id) : ''
                                "
                                class="h-8 w-36 text-xs"
                                :aria-label="`Owner of ${t.key}`"
                                @update:model-value="
                                    (v) => setField(t, 'assignee_id', v)
                                "
                            >
                                <option value="">Unassigned</option>
                                <option
                                    v-for="m in members"
                                    :key="m.id"
                                    :value="String(m.id)"
                                >
                                    {{ m.name }}
                                </option>
                            </NativeSelect>
                            <Input
                                type="date"
                                class="h-8 w-36 text-xs"
                                :model-value="t.due_on ?? ''"
                                :aria-label="`Due date of ${t.key}`"
                                @change="
                                    (e: Event) =>
                                        setField(
                                            t,
                                            'due_on',
                                            (e.target as HTMLInputElement)
                                                .value,
                                        )
                                "
                            />
                            <NativeSelect
                                :model-value="t.priority"
                                class="h-8 w-24 text-xs"
                                :aria-label="`Priority of ${t.key}`"
                                @update:model-value="
                                    (v) => setField(t, 'priority', v)
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
                        <div
                            v-else
                            class="flex shrink-0 flex-col items-end gap-1"
                        >
                            <LabelBadge
                                :name="t.status.name"
                                :color="t.status.color"
                            />
                        </div>
                    </li>
                </template>
            </ul>
        </div>

        <Pagination
            :links="tasks.links"
            :from="tasks.from"
            :to="tasks.to"
            :total="tasks.total"
        />
    </div>
</template>
