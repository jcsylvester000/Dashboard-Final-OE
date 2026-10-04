<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import TaskController from '@/actions/App/Http/Controllers/Work/TaskController';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { DepartmentOption } from '@/types/admin';
import type { MemberOption } from '@/types/workspace';

/**
 * One-line task creation: title + department + assignee (+ optional status).
 * Stays on the current page after saving.
 */
defineProps<{
    workspaceSlug: string;
    departments: DepartmentOption[];
    members: MemberOption[];
    statusId?: number;
    parentId?: number;
    projectId?: number | null;
}>();
</script>

<template>
    <Form
        v-bind="TaskController.store.form(workspaceSlug)"
        v-slot="{ errors, processing }"
        class="flex flex-wrap items-start gap-2"
        preserve-scroll
        reset-on-success
    >
        <input type="hidden" name="stay" value="1" />
        <input v-if="statusId" type="hidden" name="status_id" :value="statusId" />
        <input v-if="parentId" type="hidden" name="parent_id" :value="parentId" />
        <input v-if="projectId" type="hidden" name="project_id" :value="projectId" />
        <div class="min-w-48 flex-1">
            <Input name="title" placeholder="New task…" required aria-label="Task title" />
            <InputError :message="errors.title" />
        </div>
        <NativeSelect name="department_id" class="w-40" model-value="" aria-label="Department">
            <option value="">Department…</option>
            <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
        </NativeSelect>
        <NativeSelect name="assignee_id" class="w-40" model-value="" aria-label="Assignee">
            <option value="">Unassigned</option>
            <option v-for="m in members" :key="m.id" :value="m.id">{{ m.name }}</option>
        </NativeSelect>
        <Button type="submit" :disabled="processing"><Plus /> Add</Button>
    </Form>
</template>
