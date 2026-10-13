<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { computed, ref } from 'vue';
import TaskController from '@/actions/App/Http/Controllers/Work/TaskController';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { DepartmentOption } from '@/types/admin';
import type { MemberOption } from '@/types/workspace';

/**
 * One-line task creation. Compact mode: title + department + assignee.
 * `full` adds project, due date, estimate (hours) and priority so a task is
 * ready to work in one step. Stays on the current page after saving; the
 * toast links to the new task.
 */
const props = defineProps<{
    workspaceSlug: string;
    departments: DepartmentOption[];
    members: MemberOption[];
    statusId?: number;
    parentId?: number;
    projectId?: number | string | null;
    projects?: { id: number; name: string }[];
    full?: boolean;
    autofocus?: boolean;
}>();

const project = ref<string | number>(
    props.projectId ? String(props.projectId) : '',
);
const hours = ref<string>('');
const estimateMinutes = computed(() => {
    const h = Number.parseFloat(hours.value);

    return Number.isFinite(h) && h > 0 ? Math.round(h * 60) : '';
});

// Remounting the form after a save clears every field (title, selects, date),
// so the same task can't be added twice by accident.
const formKey = ref(0);

function resetExtras(): void {
    hours.value = '';
    project.value = props.projectId ? String(props.projectId) : '';
    formKey.value++;
}
</script>

<template>
    <Form
        :key="formKey"
        v-bind="TaskController.store.form(workspaceSlug)"
        v-slot="{ errors, processing }"
        class="flex flex-wrap items-start gap-2"
        :options="{ preserveScroll: true }"
        reset-on-success
        @success="resetExtras"
    >
        <input type="hidden" name="stay" value="1" />
        <input
            v-if="statusId"
            type="hidden"
            name="status_id"
            :value="statusId"
        />
        <input
            v-if="parentId"
            type="hidden"
            name="parent_id"
            :value="parentId"
        />
        <input
            v-if="!(full && projects?.length) && projectId"
            type="hidden"
            name="project_id"
            :value="projectId"
        />
        <input
            v-if="estimateMinutes !== ''"
            type="hidden"
            name="estimate_minutes"
            :value="estimateMinutes"
        />
        <div class="min-w-48 flex-1">
            <Input
                v-focus="autofocus === true || formKey > 0"
                name="title"
                placeholder="New task…"
                required
                aria-label="Task title"
            />
            <InputError :message="errors.title" />
        </div>
        <NativeSelect
            name="department_id"
            class="w-40"
            model-value=""
            aria-label="Department"
        >
            <option value="">Department…</option>
            <option v-for="d in departments" :key="d.id" :value="d.id">
                {{ d.name }}
            </option>
        </NativeSelect>
        <NativeSelect
            name="assignee_id"
            class="w-40"
            model-value=""
            aria-label="Assignee"
        >
            <option value="">Unassigned</option>
            <option v-for="m in members" :key="m.id" :value="m.id">
                {{ m.name }}
            </option>
        </NativeSelect>
        <template v-if="full">
            <NativeSelect
                v-if="projects?.length"
                v-model="project"
                name="project_id"
                class="w-48"
                aria-label="Project"
            >
                <option value="">No project</option>
                <option v-for="p in projects" :key="p.id" :value="String(p.id)">
                    {{ p.name }}
                </option>
            </NativeSelect>
            <div>
                <Input
                    name="due_on"
                    type="date"
                    class="w-40"
                    aria-label="Due date"
                    title="Due date"
                />
                <InputError :message="errors.due_on" />
            </div>
            <div>
                <Input
                    v-model="hours"
                    type="number"
                    min="0"
                    step="0.25"
                    class="w-28"
                    placeholder="Est. hours"
                    aria-label="Estimate (hours)"
                />
                <InputError :message="errors.estimate_minutes" />
            </div>
            <NativeSelect
                name="type"
                class="w-28"
                model-value="task"
                aria-label="Type"
            >
                <option value="task">Task</option>
                <option value="story">Story</option>
                <option value="bug">Bug</option>
                <option value="chore">Chore</option>
                <option value="milestone">Milestone</option>
            </NativeSelect>
            <NativeSelect
                name="priority"
                class="w-32"
                model-value="normal"
                aria-label="Priority"
            >
                <option value="low">Low</option>
                <option value="normal">Normal</option>
                <option value="high">High</option>
                <option value="urgent">Urgent</option>
            </NativeSelect>
        </template>
        <Button type="submit" :disabled="processing"><Plus /> Add</Button>
    </Form>
</template>
