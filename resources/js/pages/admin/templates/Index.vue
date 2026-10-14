<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, Plus, Trash2 } from '@lucide/vue';
import { reactive, ref } from 'vue';
import WorkflowTemplateController from '@/actions/App/Http/Controllers/Admin/WorkflowTemplateController';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { confirmDialog } from '@/composables/useConfirm';
import { departmentDot } from '@/lib/format';
import { update as saveChecklistDefaults } from '@/routes/admin/checklist-defaults';
import { index } from '@/routes/admin/templates';
import type { DepartmentOption } from '@/types/admin';

type Step = {
    department_id: number | null;
    title: string;
    description: string | null;
    offset_days: number;
    depends_on_previous: boolean;
    // E-40: review step after this task, and its checklist.
    review_user_ids: number[];
    review_leads: boolean;
    review_mode: 'all' | 'any';
    review_sla_days: number | null;
    checklist: string;
};

type Template = {
    id: number;
    name: string;
    description: string | null;
    is_active: boolean;
    working_days?: boolean;
    steps: Step[];
};

const props = defineProps<{
    templates: Template[];
    departments: DepartmentOption[];
    // E-25
    checklistDefaults: Record<string, string>;
    taskTypes: Record<string, string>;
    reviewerOptions?: { id: number; name: string }[];
}>();

const blankStep = (offset = 0): Step => ({
    department_id: null,
    title: '',
    description: null,
    offset_days: offset,
    depends_on_previous: true,
    review_user_ids: [],
    review_leads: false,
    review_mode: 'all',
    review_sla_days: null,
    checklist: '',
});
const openReview = ref<number | null>(null);

// E-25: agency definition of done per task type.
const dodForm = useForm<{ templates: Record<string, string> }>({
    templates: { ...props.checklistDefaults },
});

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Workflow templates', href: index() }],
    },
});

const editingId = ref<number | 'new' | null>(null);
const errors = ref<Record<string, string>>({});
const draft = reactive<{
    name: string;
    description: string;
    is_active: boolean;
    working_days: boolean;
    steps: Step[];
}>({
    name: '',
    description: '',
    is_active: true,
    working_days: true,
    steps: [],
});

function edit(t: Template | null): void {
    errors.value = {};
    editingId.value = t ? t.id : 'new';
    draft.name = t?.name ?? '';
    draft.description = t?.description ?? '';
    draft.is_active = t?.is_active ?? true;
    draft.working_days = t?.working_days ?? true;
    openReview.value = null;
    draft.steps = t
        ? t.steps.map((s) => ({ ...blankStep(), ...s }))
        : [blankStep()];
}

function addStep(): void {
    const last = draft.steps[draft.steps.length - 1];
    draft.steps.push(blankStep((last?.offset_days ?? 0) + 3));
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
        working_days: draft.working_days,
        steps: draft.steps.map((s) => ({
            ...s,
            department_id: s.department_id || null,
        })),
    };
    const options = {
        preserveScroll: true,
        onError: (e: Record<string, string>) => (errors.value = e),
        onSuccess: () => (editingId.value = null),
    };

    if (editingId.value === 'new') {
        router.post(WorkflowTemplateController.store.url(), payload, options);
    } else if (typeof editingId.value === 'number') {
        router.put(
            WorkflowTemplateController.update.url(editingId.value),
            payload,
            options,
        );
    }
}

async function remove(t: Template): Promise<void> {
    if (
        await confirmDialog(
            `Delete template "${t.name}"? Tasks already created from it stay.`,
        )
    ) {
        router.delete(WorkflowTemplateController.destroy.url(t.id), {
            preserveScroll: true,
        });
    }
}

function deptName(id: number | null): string {
    return props.departments.find((d) => d.id === id)?.name ?? 'Any';
}

function deptColor(id: number | null): string {
    return (
        departmentDot[
            props.departments.find((d) => d.id === id)?.color ?? ''
        ] ?? 'bg-slate-400'
    );
}
</script>

<template>
    <Head title="Workflow templates" />

    <div class="flex max-w-5xl flex-1 flex-col gap-4 p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">
                    Workflow templates
                </h1>
                <p class="text-sm text-muted-foreground">
                    Reusable cross-department workflows. Each step becomes a
                    task for that department, waiting on the step before it.
                </p>
            </div>
            <Button v-if="editingId === null" @click="edit(null)"
                ><Plus /> New template</Button
            >
        </div>

        <Card v-if="editingId !== null">
            <CardHeader>
                <CardTitle class="text-base">{{
                    editingId === 'new' ? 'New template' : 'Edit template'
                }}</CardTitle>
            </CardHeader>
            <CardContent class="space-y-4">
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="grid gap-1">
                        <Input
                            v-model="draft.name"
                            placeholder="Template name"
                            aria-label="Template name"
                        />
                        <InputError :message="errors.name" />
                    </div>
                    <Input
                        v-model="draft.description"
                        placeholder="Short description"
                        aria-label="Description"
                    />
                </div>
                <label class="inline-flex items-center gap-2 text-sm">
                    <input
                        v-model="draft.is_active"
                        type="checkbox"
                        class="size-4"
                    />
                    Active (shown when starting a workflow)
                </label>
                <label class="ml-4 inline-flex items-center gap-2 text-sm">
                    <input
                        v-model="draft.working_days"
                        type="checkbox"
                        class="size-4"
                    />
                    Count days as working days (skip weekends and holidays)
                </label>

                <ol class="space-y-2">
                    <li
                        v-for="(s, i) in draft.steps"
                        :key="i"
                        class="grid gap-2 rounded-md border p-3 sm:grid-cols-12 sm:items-center"
                    >
                        <span
                            class="text-xs text-muted-foreground sm:col-span-1"
                            >Step {{ i + 1 }}</span
                        >
                        <NativeSelect
                            v-model="s.department_id"
                            class="sm:col-span-2"
                            aria-label="Department"
                        >
                            <option value="">Any dept</option>
                            <option
                                v-for="d in departments"
                                :key="d.id"
                                :value="d.id"
                            >
                                {{ d.name }}
                            </option>
                        </NativeSelect>
                        <div class="sm:col-span-4">
                            <Input
                                v-model="s.title"
                                placeholder="What this department does"
                                aria-label="Step title"
                            />
                            <InputError :message="errors[`steps.${i}.title`]" />
                        </div>
                        <label
                            class="flex items-center gap-1 text-xs sm:col-span-2"
                        >
                            Day +<Input
                                v-model.number="s.offset_days"
                                type="number"
                                min="0"
                                class="h-8 w-16"
                                aria-label="Due offset in days"
                            />
                        </label>
                        <label
                            v-if="i > 0"
                            class="flex items-center gap-1 text-xs sm:col-span-2"
                        >
                            <input
                                v-model="s.depends_on_previous"
                                type="checkbox"
                                class="size-4"
                            />
                            waits on previous
                        </label>
                        <span v-else class="sm:col-span-2"></span>
                        <span class="flex justify-end gap-1 sm:col-span-1">
                            <button
                                type="button"
                                class="rounded p-1 hover:bg-accent"
                                aria-label="Move up"
                                @click="move(i, -1)"
                            >
                                <ArrowUp class="size-3.5" />
                            </button>
                            <button
                                type="button"
                                class="rounded p-1 hover:bg-accent"
                                aria-label="Move down"
                                @click="move(i, 1)"
                            >
                                <ArrowDown class="size-3.5" />
                            </button>
                            <button
                                type="button"
                                class="rounded p-1 hover:bg-accent"
                                aria-label="Remove step"
                                @click="draft.steps.splice(i, 1)"
                            >
                                <Trash2 class="size-3.5" />
                            </button>
                        </span>
                        <!-- E-40: review step and checklist for this task -->
                        <div class="text-xs sm:col-span-12">
                            <button
                                type="button"
                                class="underline"
                                :aria-expanded="openReview === i"
                                @click="
                                    openReview = openReview === i ? null : i
                                "
                            >
                                Review and checklist<template
                                    v-if="
                                        s.review_user_ids.length ||
                                        s.review_leads ||
                                        s.checklist.trim()
                                    "
                                >
                                    ·
                                    {{
                                        s.review_user_ids.length +
                                        (s.review_leads ? 1 : 0)
                                    }}
                                    reviewer source(s),
                                    {{
                                        s.checklist
                                            .split('\n')
                                            .filter((l) => l.trim()).length
                                    }}
                                    checklist line(s)</template
                                >
                            </button>
                            <div
                                v-if="openReview === i"
                                class="mt-2 grid gap-3 rounded border bg-muted/30 p-3 sm:grid-cols-2"
                            >
                                <div class="grid gap-1">
                                    <span class="text-muted-foreground"
                                        >Reviewers (added where they are on the
                                        client)</span
                                    >
                                    <select
                                        v-model="s.review_user_ids"
                                        multiple
                                        size="5"
                                        class="rounded-md border bg-background p-1"
                                        aria-label="Reviewers"
                                    >
                                        <option
                                            v-for="u in reviewerOptions ?? []"
                                            :key="u.id"
                                            :value="u.id"
                                        >
                                            {{ u.name }}
                                        </option>
                                    </select>
                                    <label class="flex items-center gap-1">
                                        <input
                                            v-model="s.review_leads"
                                            type="checkbox"
                                            class="size-3.5"
                                        />
                                        The client's leads review
                                    </label>
                                    <div
                                        class="flex flex-wrap items-center gap-2"
                                    >
                                        <NativeSelect
                                            v-model="s.review_mode"
                                            class="h-8 w-40"
                                            aria-label="Who must approve"
                                        >
                                            <option value="all">
                                                All must approve
                                            </option>
                                            <option value="any">
                                                Any one approves
                                            </option>
                                        </NativeSelect>
                                        <label class="flex items-center gap-1"
                                            >SLA
                                            <input
                                                v-model.number="
                                                    s.review_sla_days
                                                "
                                                type="number"
                                                min="1"
                                                max="30"
                                                class="h-8 w-16 rounded-md border bg-background px-2"
                                                aria-label="Review SLA in working days"
                                            />
                                            working days</label
                                        >
                                    </div>
                                </div>
                                <label class="grid gap-1">
                                    <span class="text-muted-foreground"
                                        >Checklist (one per line; start with ?
                                        for optional)</span
                                    >
                                    <textarea
                                        v-model="s.checklist"
                                        rows="5"
                                        class="rounded-md border bg-background p-2"
                                        placeholder="Brief signed off&#10;? Moodboard attached"
                                    />
                                </label>
                            </div>
                        </div>
                    </li>
                </ol>
                <InputError :message="errors.steps" />
                <div class="flex gap-2">
                    <Button variant="outline" @click="addStep"
                        ><Plus /> Add step</Button
                    >
                    <Button @click="save">Save template</Button>
                    <Button variant="ghost" @click="editingId = null"
                        >Cancel</Button
                    >
                </div>
            </CardContent>
        </Card>

        <Card
            v-for="t in templates"
            :key="t.id"
            :class="{ 'opacity-60': !t.is_active }"
        >
            <CardHeader>
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <CardTitle class="text-base">{{ t.name }}</CardTitle>
                        <CardDescription>{{ t.description }}</CardDescription>
                    </div>
                    <div class="flex gap-1">
                        <Button size="sm" variant="outline" @click="edit(t)"
                            >Edit</Button
                        >
                        <Button
                            size="sm"
                            variant="ghost"
                            :aria-label="`Delete ${t.name}`"
                            @click="remove(t)"
                            ><Trash2
                        /></Button>
                    </div>
                </div>
            </CardHeader>
            <CardContent>
                <ol class="flex flex-wrap items-center gap-2 text-xs">
                    <li
                        v-for="(s, i) in t.steps"
                        :key="i"
                        class="flex items-center gap-2"
                    >
                        <span v-if="i > 0" class="text-muted-foreground">{{
                            s.depends_on_previous ? '→' : '∥'
                        }}</span>
                        <span
                            class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5"
                        >
                            <span
                                class="size-2 rounded-full"
                                :class="deptColor(s.department_id)"
                            />
                            {{ deptName(s.department_id) }}: {{ s.title }}
                            <span class="text-muted-foreground"
                                >(day {{ s.offset_days }})</span
                            >
                        </span>
                    </li>
                </ol>
            </CardContent>
        </Card>

        <!-- E-25: agency definition of done -->
        <Card>
            <CardHeader>
                <CardTitle class="text-base">Definition of done</CardTitle>
                <CardDescription
                    >Checklist added to every new task of that type, one line
                    per item, when its client has no checklist of its own. Each
                    line must be ticked before Done. Changes apply to new tasks
                    only.</CardDescription
                >
            </CardHeader>
            <CardContent>
                <form
                    class="grid gap-3 text-sm sm:grid-cols-2"
                    @submit.prevent="
                        dodForm.put(saveChecklistDefaults.url(), {
                            preserveScroll: true,
                        })
                    "
                >
                    <label
                        v-for="(label, type) in taskTypes"
                        :key="type"
                        class="grid gap-1"
                    >
                        <span class="text-xs text-muted-foreground">{{
                            label
                        }}</span>
                        <textarea
                            v-model="dodForm.templates[type]"
                            rows="5"
                            class="rounded-md border bg-transparent px-2 py-1 text-sm"
                        />
                    </label>
                    <div class="sm:col-span-2">
                        <Button
                            type="submit"
                            size="sm"
                            :disabled="dodForm.processing"
                            >Save definition of done</Button
                        >
                    </div>
                </form>
            </CardContent>
        </Card>
    </div>
</template>
