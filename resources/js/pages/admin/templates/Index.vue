<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, Plus, Trash2 } from '@lucide/vue';
import { reactive, ref } from 'vue';
import WorkflowTemplateController from '@/actions/App/Http/Controllers/Admin/WorkflowTemplateController';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { departmentDot } from '@/lib/format';
import { index } from '@/routes/admin/templates';
import type { DepartmentOption } from '@/types/admin';

type Step = {
    department_id: number | null;
    title: string;
    description: string | null;
    offset_days: number;
    depends_on_previous: boolean;
};

type Template = {
    id: number;
    name: string;
    description: string | null;
    is_active: boolean;
    steps: Step[];
};

const props = defineProps<{ templates: Template[]; departments: DepartmentOption[] }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Workflow templates', href: index() }],
    },
});

const editingId = ref<number | 'new' | null>(null);
const errors = ref<Record<string, string>>({});
const draft = reactive<{ name: string; description: string; is_active: boolean; steps: Step[] }>({
    name: '',
    description: '',
    is_active: true,
    steps: [],
});

function edit(t: Template | null): void {
    errors.value = {};
    editingId.value = t ? t.id : 'new';
    draft.name = t?.name ?? '';
    draft.description = t?.description ?? '';
    draft.is_active = t?.is_active ?? true;
    draft.steps = t
        ? t.steps.map((s) => ({ ...s }))
        : [{ department_id: null, title: '', description: null, offset_days: 0, depends_on_previous: true }];
}

function addStep(): void {
    const last = draft.steps[draft.steps.length - 1];
    draft.steps.push({ department_id: null, title: '', description: null, offset_days: (last?.offset_days ?? 0) + 3, depends_on_previous: true });
}

function move(i: number, dir: -1 | 1): void {
    const j = i + dir;
    if (j < 0 || j >= draft.steps.length) return;
    [draft.steps[i], draft.steps[j]] = [draft.steps[j], draft.steps[i]];
}

function save(): void {
    const payload = {
        name: draft.name,
        description: draft.description || null,
        is_active: draft.is_active,
        steps: draft.steps.map((s) => ({ ...s, department_id: s.department_id || null })),
    };
    const options = {
        preserveScroll: true,
        onError: (e: Record<string, string>) => (errors.value = e),
        onSuccess: () => (editingId.value = null),
    };

    if (editingId.value === 'new') {
        router.post(WorkflowTemplateController.store.url(), payload, options);
    } else if (typeof editingId.value === 'number') {
        router.put(WorkflowTemplateController.update.url(editingId.value), payload, options);
    }
}

function remove(t: Template): void {
    if (window.confirm(`Delete template "${t.name}"? Tasks already created from it stay.`)) {
        router.delete(WorkflowTemplateController.destroy.url(t.id), { preserveScroll: true });
    }
}

function deptName(id: number | null): string {
    return props.departments.find((d) => d.id === id)?.name ?? 'Any';
}

function deptColor(id: number | null): string {
    return departmentDot[props.departments.find((d) => d.id === id)?.color ?? ''] ?? 'bg-slate-400';
}
</script>

<template>
    <Head title="Workflow templates" />

    <div class="flex max-w-5xl flex-1 flex-col gap-4 p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">Workflow templates</h1>
                <p class="text-sm text-muted-foreground">
                    Reusable cross-department workflows. Each step becomes a task for that department, waiting on the
                    step before it.
                </p>
            </div>
            <Button v-if="editingId === null" @click="edit(null)"><Plus /> New template</Button>
        </div>

        <Card v-if="editingId !== null">
            <CardHeader>
                <CardTitle class="text-base">{{ editingId === 'new' ? 'New template' : 'Edit template' }}</CardTitle>
            </CardHeader>
            <CardContent class="space-y-4">
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="grid gap-1">
                        <Input v-model="draft.name" placeholder="Template name" aria-label="Template name" />
                        <InputError :message="errors.name" />
                    </div>
                    <Input v-model="draft.description" placeholder="Short description" aria-label="Description" />
                </div>
                <label class="inline-flex items-center gap-2 text-sm">
                    <input v-model="draft.is_active" type="checkbox" class="size-4" /> Active (shown when starting a workflow)
                </label>

                <ol class="space-y-2">
                    <li v-for="(s, i) in draft.steps" :key="i" class="grid gap-2 rounded-md border p-3 sm:grid-cols-12 sm:items-center">
                        <span class="text-xs text-muted-foreground sm:col-span-1">Step {{ i + 1 }}</span>
                        <NativeSelect v-model="s.department_id" class="sm:col-span-2" aria-label="Department">
                            <option value="">Any dept</option>
                            <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
                        </NativeSelect>
                        <div class="sm:col-span-4">
                            <Input v-model="s.title" placeholder="What this department does" aria-label="Step title" />
                            <InputError :message="errors[`steps.${i}.title`]" />
                        </div>
                        <label class="flex items-center gap-1 text-xs sm:col-span-2">
                            Day +<Input v-model.number="s.offset_days" type="number" min="0" class="h-8 w-16" aria-label="Due offset in days" />
                        </label>
                        <label v-if="i > 0" class="flex items-center gap-1 text-xs sm:col-span-2">
                            <input v-model="s.depends_on_previous" type="checkbox" class="size-4" /> waits on previous
                        </label>
                        <span v-else class="sm:col-span-2"></span>
                        <span class="flex justify-end gap-1 sm:col-span-1">
                            <button type="button" class="rounded p-1 hover:bg-accent" aria-label="Move up" @click="move(i, -1)"><ArrowUp class="size-3.5" /></button>
                            <button type="button" class="rounded p-1 hover:bg-accent" aria-label="Move down" @click="move(i, 1)"><ArrowDown class="size-3.5" /></button>
                            <button type="button" class="rounded p-1 hover:bg-accent" aria-label="Remove step" @click="draft.steps.splice(i, 1)"><Trash2 class="size-3.5" /></button>
                        </span>
                    </li>
                </ol>
                <InputError :message="errors.steps" />
                <div class="flex gap-2">
                    <Button variant="outline" @click="addStep"><Plus /> Add step</Button>
                    <Button @click="save">Save template</Button>
                    <Button variant="ghost" @click="editingId = null">Cancel</Button>
                </div>
            </CardContent>
        </Card>

        <Card v-for="t in templates" :key="t.id" :class="{ 'opacity-60': !t.is_active }">
            <CardHeader>
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <CardTitle class="text-base">{{ t.name }}</CardTitle>
                        <CardDescription>{{ t.description }}</CardDescription>
                    </div>
                    <div class="flex gap-1">
                        <Button size="sm" variant="outline" @click="edit(t)">Edit</Button>
                        <Button size="sm" variant="ghost" :aria-label="`Delete ${t.name}`" @click="remove(t)"><Trash2 /></Button>
                    </div>
                </div>
            </CardHeader>
            <CardContent>
                <ol class="flex flex-wrap items-center gap-2 text-xs">
                    <li v-for="(s, i) in t.steps" :key="i" class="flex items-center gap-2">
                        <span v-if="i > 0" class="text-muted-foreground">{{ s.depends_on_previous ? '→' : '∥' }}</span>
                        <span class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5">
                            <span class="size-2 rounded-full" :class="deptColor(s.department_id)" />
                            {{ deptName(s.department_id) }}: {{ s.title }}
                            <span class="text-muted-foreground">(day {{ s.offset_days }})</span>
                        </span>
                    </li>
                </ol>
            </CardContent>
        </Card>
    </div>
</template>
