<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { reactive, watch } from 'vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Input } from '@/components/ui/input';
import type { DepartmentOption } from '@/types/admin';
import type { TaskFilters, TaskStatus } from '@/types/work';
import type { MemberOption } from '@/types/workspace';

/**
 * Shared filters for the task list and board. Updates the URL query
 * (so filtered views can be bookmarked or shared with teammates).
 */
const props = defineProps<{
    url: string;
    filters: TaskFilters;
    statuses?: TaskStatus[];
    departments: DepartmentOption[];
    members: MemberOption[];
    projects: { id: number; name: string }[];
    showStatus?: boolean;
}>();

const form = reactive({
    search: props.filters.search ?? '',
    status: props.filters.status ? String(props.filters.status) : '',
    department: props.filters.department ? String(props.filters.department) : '',
    project: props.filters.project ? String(props.filters.project) : '',
    assignee: props.filters.assignee ?? '',
    due: props.filters.due ?? '',
});

const apply = useDebounceFn(() => {
    const query = Object.fromEntries(Object.entries(form).filter(([, v]) => v !== ''));
    const qs = new URLSearchParams(query as Record<string, string>).toString();
    router.get(qs ? `${props.url}?${qs}` : props.url, {}, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}, 300);

watch(form, apply);
</script>

<template>
    <div class="flex flex-wrap items-center gap-2">
        <Input v-model="form.search" type="search" class="w-52" placeholder="Search tasks" aria-label="Search tasks" />
        <NativeSelect v-if="showStatus && statuses" v-model="form.status" class="w-36" aria-label="Status">
            <option value="">Open tasks</option>
            <option v-for="s in statuses" :key="s.id" :value="String(s.id)">{{ s.name }}</option>
        </NativeSelect>
        <NativeSelect v-model="form.department" class="w-40" aria-label="Department">
            <option value="">All departments</option>
            <option v-for="d in departments" :key="d.id" :value="String(d.id)">{{ d.name }}</option>
        </NativeSelect>
        <NativeSelect v-model="form.assignee" class="w-40" aria-label="Assignee">
            <option value="">Anyone</option>
            <option value="me">Me</option>
            <option value="none">Unassigned</option>
            <option v-for="m in members" :key="m.id" :value="String(m.id)">{{ m.name }}</option>
        </NativeSelect>
        <NativeSelect v-if="projects.length" v-model="form.project" class="w-44" aria-label="Project">
            <option value="">All projects</option>
            <option v-for="p in projects" :key="p.id" :value="String(p.id)">{{ p.name }}</option>
        </NativeSelect>
        <NativeSelect v-model="form.due" class="w-36" aria-label="Due">
            <option value="">Any due date</option>
            <option value="overdue">Overdue</option>
            <option value="week">Due this week</option>
        </NativeSelect>
    </div>
</template>
