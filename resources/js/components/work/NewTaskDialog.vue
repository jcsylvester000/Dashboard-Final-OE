<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { useDebounceFn, useEventListener } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import TaskController from '@/actions/App/Http/Controllers/Work/TaskController';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { search as referenceSearch } from '@/routes/references';
import { newOptions } from '@/routes/tasks';

/**
 * "New task" from anywhere. Any team member can add their own task to a client
 * they work on (assigned to themselves by default) and link it to an existing ticket.
 */
const props = withDefaults(
    defineProps<{
        label?: string;
        variant?: 'default' | 'outline';
        shortcut?: boolean;
    }>(),
    { label: 'New task', variant: 'default', shortcut: false },
);

type Option = { id: number; name: string };
type Options = {
    workspaces: (Option & { slug: string })[];
    workspace_id: number | null;
    projects: Option[];
    members: Option[];
    departments: Option[];
    me: { id: number; department_id: number | null };
};

const open = ref(false);
const loading = ref(false);
const saving = ref(false);
const errors = ref<Record<string, string>>({});
const opts = ref<Options | null>(null);

const blank = () => ({
    workspace_id: '',
    title: '',
    description: '',
    project_id: '',
    department_id: '',
    assignee_id: '',
    due_on: '',
    hours: '',
    priority: 'normal',
    type: 'task',
    link_task_id: null as number | null,
    link_label: '',
    link_relation: 'relates',
});
const form = ref(blank());

async function load(workspaceId?: string): Promise<void> {
    loading.value = true;
    const query = workspaceId
        ? `?workspace=${encodeURIComponent(workspaceId)}`
        : '';
    const res = await fetch(newOptions.url() + query, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
    });
    opts.value = res.ok ? ((await res.json()) as Options) : null;
    loading.value = false;
    if (opts.value) {
        form.value.workspace_id = opts.value.workspace_id
            ? String(opts.value.workspace_id)
            : '';
        if (
            !form.value.assignee_id ||
            !opts.value.members.some(
                (m) => String(m.id) === form.value.assignee_id,
            )
        ) {
            form.value.assignee_id = opts.value.members.some(
                (m) => m.id === opts.value?.me.id,
            )
                ? String(opts.value.me.id)
                : '';
        }
        form.value.department_id ||= opts.value.me.department_id
            ? String(opts.value.me.department_id)
            : '';
        form.value.project_id = '';
    }
}

function show(): void {
    form.value = blank();
    errors.value = {};
    open.value = true;
    void load();
}

// The header copy answers the "c" keyboard shortcut (see GlobalSearch).
useEventListener(window, 'oe:new-task', () => {
    if (props.shortcut && !open.value) {
        show();
    }
});

watch(
    () => form.value.workspace_id,
    (id, old) => {
        if (old && id && id !== old) {
            void load(id);
        }
    },
);

const slug = computed(
    () =>
        opts.value?.workspaces.find(
            (w) => String(w.id) === form.value.workspace_id,
        )?.slug ?? null,
);

// Link to an existing ticket (any task you can see).
const term = ref('');
const results = ref<
    { record_id: number; label: string; context: string | null }[]
>([]);
const runSearch = useDebounceFn(async () => {
    if (term.value.trim().length < 2) {
        results.value = [];

        return;
    }
    const res = await fetch(referenceSearch.url({ query: { q: term.value } }), {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
    });
    const json = res.ok
        ? ((await res.json()) as {
              results: {
                  type: string;
                  record_id: number;
                  label: string;
                  context: string | null;
              }[];
          })
        : { results: [] };
    results.value = json.results.filter((r) => r.type === 'task');
}, 250);
watch(term, runSearch);

function pickLink(r: { record_id: number; label: string }): void {
    form.value.link_task_id = r.record_id;
    form.value.link_label = r.label;
    term.value = '';
    results.value = [];
}

function submit(): void {
    if (!slug.value) {
        errors.value = { workspace_id: 'Choose a client.' };

        return;
    }
    const f = form.value;
    const hours = Number.parseFloat(f.hours);
    saving.value = true;
    router.post(
        TaskController.store.url(slug.value),
        {
            stay: 1,
            title: f.title,
            description: f.description || null,
            project_id: f.project_id ? Number(f.project_id) : null,
            department_id: f.department_id ? Number(f.department_id) : null,
            assignee_id: f.assignee_id ? Number(f.assignee_id) : null,
            due_on: f.due_on || null,
            estimate_minutes:
                Number.isFinite(hours) && hours > 0
                    ? Math.round(hours * 60)
                    : null,
            priority: f.priority,
            type: f.type,
            link_task_id: f.link_task_id,
            link_relation: f.link_task_id ? f.link_relation : null,
        },
        {
            preserveScroll: true,
            onSuccess: () => (open.value = false),
            onError: (e) => (errors.value = e),
            onFinish: () => (saving.value = false),
        },
    );
}
</script>

<template>
    <Button :variant="variant" size="sm" data-testid="new-task" @click="show"
        ><Plus class="size-4" /> {{ label }}</Button
    >

    <Dialog v-model:open="open">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-xl">
            <DialogHeader>
                <DialogTitle>New task</DialogTitle>
                <DialogDescription
                    >Add your own work. It's assigned to you unless you pick
                    someone else, and you can link it to an existing
                    ticket.</DialogDescription
                >
            </DialogHeader>

            <p v-if="loading && !opts" class="text-sm text-muted-foreground">
                Loading…
            </p>
            <p
                v-else-if="opts && opts.workspaces.length === 0"
                class="text-sm text-muted-foreground"
            >
                You aren't a contributor on any client yet. Ask a lead to add
                you to a workspace.
            </p>
            <form
                v-else-if="opts"
                class="grid gap-3 text-sm"
                @submit.prevent="submit"
            >
                <label class="grid gap-1">
                    <span class="text-xs text-muted-foreground">Client</span>
                    <NativeSelect
                        v-model="form.workspace_id"
                        aria-label="Client"
                        required
                    >
                        <option
                            v-for="w in opts.workspaces"
                            :key="w.id"
                            :value="String(w.id)"
                        >
                            {{ w.name }}
                        </option>
                    </NativeSelect>
                    <InputError :message="errors.workspace_id" />
                </label>
                <label class="grid gap-1">
                    <span class="text-xs text-muted-foreground">Title</span>
                    <Input
                        v-model="form.title"
                        v-focus
                        required
                        placeholder="What needs doing?"
                    />
                    <InputError :message="errors.title" />
                </label>
                <label class="grid gap-1">
                    <span class="text-xs text-muted-foreground"
                        >Details (optional)</span
                    >
                    <textarea
                        v-model="form.description"
                        rows="3"
                        class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                    />
                </label>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="grid gap-1">
                        <span class="text-xs text-muted-foreground"
                            >Project</span
                        >
                        <NativeSelect
                            v-model="form.project_id"
                            aria-label="Project"
                        >
                            <option value="">No project</option>
                            <option
                                v-for="p in opts.projects"
                                :key="p.id"
                                :value="String(p.id)"
                            >
                                {{ p.name }}
                            </option>
                        </NativeSelect>
                    </label>
                    <label class="grid gap-1">
                        <span class="text-xs text-muted-foreground"
                            >Department</span
                        >
                        <NativeSelect
                            v-model="form.department_id"
                            aria-label="Department"
                        >
                            <option value="">None</option>
                            <option
                                v-for="d in opts.departments"
                                :key="d.id"
                                :value="String(d.id)"
                            >
                                {{ d.name }}
                            </option>
                        </NativeSelect>
                    </label>
                    <label class="grid gap-1">
                        <span class="text-xs text-muted-foreground"
                            >Assignee</span
                        >
                        <NativeSelect
                            v-model="form.assignee_id"
                            aria-label="Assignee"
                        >
                            <option value="">Unassigned</option>
                            <option
                                v-for="m in opts.members"
                                :key="m.id"
                                :value="String(m.id)"
                            >
                                {{
                                    m.id === opts.me.id
                                        ? `${m.name} (me)`
                                        : m.name
                                }}
                            </option>
                        </NativeSelect>
                    </label>
                    <label class="grid gap-1">
                        <span class="text-xs text-muted-foreground">Type</span>
                        <NativeSelect v-model="form.type" aria-label="Type">
                            <option value="task">Task</option>
                            <option value="story">Story</option>
                            <option value="bug">Bug</option>
                            <option value="chore">Chore</option>
                            <option value="milestone">Milestone</option>
                        </NativeSelect>
                    </label>
                    <label class="grid gap-1">
                        <span class="text-xs text-muted-foreground"
                            >Priority</span
                        >
                        <NativeSelect
                            v-model="form.priority"
                            aria-label="Priority"
                        >
                            <option value="low">Low</option>
                            <option value="normal">Normal</option>
                            <option value="high">High</option>
                            <option value="urgent">Urgent</option>
                        </NativeSelect>
                    </label>
                    <label class="grid gap-1">
                        <span class="text-xs text-muted-foreground">Due</span>
                        <Input v-model="form.due_on" type="date" />
                    </label>
                    <label class="grid gap-1">
                        <span class="text-xs text-muted-foreground"
                            >Estimate (hours)</span
                        >
                        <Input
                            v-model="form.hours"
                            type="number"
                            min="0"
                            step="0.25"
                        />
                    </label>
                </div>

                <div class="grid gap-1 rounded-md border p-2">
                    <span class="text-xs text-muted-foreground"
                        >Link to an existing ticket (optional)</span
                    >
                    <div
                        v-if="form.link_task_id"
                        class="flex flex-wrap items-center gap-2"
                    >
                        <NativeSelect
                            v-model="form.link_relation"
                            class="w-44"
                            aria-label="Link type"
                        >
                            <option value="relates">Relates to</option>
                            <option value="follows">Follow-up of</option>
                            <option value="duplicates">Duplicates</option>
                            <option value="blocked_by">
                                Waiting on (blocked by)
                            </option>
                            <option value="blocks">Blocks</option>
                        </NativeSelect>
                        <span class="min-w-0 flex-1 truncate font-medium">{{
                            form.link_label
                        }}</span>
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            @click="form.link_task_id = null"
                            >Remove</Button
                        >
                    </div>
                    <div v-else class="relative">
                        <Input
                            v-model="term"
                            placeholder="Search tasks by title…"
                            aria-label="Search tasks to link"
                        />
                        <ul
                            v-if="results.length"
                            class="absolute z-30 mt-1 w-full overflow-hidden rounded-md border bg-popover shadow-md"
                        >
                            <li
                                v-for="r in results"
                                :key="r.record_id"
                                class="cursor-pointer px-3 py-2 hover:bg-accent"
                                @mousedown.prevent="pickLink(r)"
                            >
                                <div class="truncate font-medium">
                                    {{ r.label }}
                                </div>
                                <div
                                    v-if="r.context"
                                    class="truncate text-xs text-muted-foreground"
                                >
                                    {{ r.context }}
                                </div>
                            </li>
                        </ul>
                    </div>
                    <InputError
                        :message="errors.link_relation ?? errors.link_task_id"
                    />
                </div>

                <div class="flex justify-end gap-2">
                    <Button type="button" variant="ghost" @click="open = false"
                        >Cancel</Button
                    >
                    <Button
                        type="submit"
                        :disabled="saving || !form.title.trim()"
                        >Create task</Button
                    >
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
