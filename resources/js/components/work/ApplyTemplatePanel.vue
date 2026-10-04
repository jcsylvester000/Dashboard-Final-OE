<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Workflow, X } from '@lucide/vue';
import { reactive, ref } from 'vue';
import TaskFlowController from '@/actions/App/Http/Controllers/Work/TaskFlowController';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { DepartmentOption } from '@/types/admin';
import type { MemberOption } from '@/types/workspace';

/**
 * Start a cross-department workflow from a template: one task per step,
 * each department's step waiting on the previous one.
 */
const props = defineProps<{
    workspaceSlug: string;
    templates: { id: number; name: string }[];
    projects: { id: number; name: string }[];
    departments: DepartmentOption[];
    members: MemberOption[];
}>();

const open = ref(false);
const processing = ref(false);
const errors = ref<Record<string, string>>({});
const form = reactive({
    template: props.templates[0]?.id ? String(props.templates[0].id) : '',
    project_id: '',
    start_on: new Date().toISOString().slice(0, 10),
    assignees: {} as Record<number, string>,
});

function submit(): void {
    if (!form.template) {
        return;
    }

    processing.value = true;
    const assignees = Object.fromEntries(
        Object.entries(form.assignees).filter(([, v]) => v !== ''),
    );

    router.post(
        TaskFlowController.applyTemplate.url({ workspace: props.workspaceSlug, template: Number(form.template) }),
        { project_id: form.project_id || null, start_on: form.start_on, assignees },
        {
            onError: (e) => (errors.value = e),
            onSuccess: () => (open.value = false),
            onFinish: () => (processing.value = false),
        },
    );
}
</script>

<template>
    <div>
        <Button v-if="!open" variant="outline" :disabled="templates.length === 0" @click="open = true">
            <Workflow /> Start a workflow
        </Button>

        <div v-else class="space-y-4 rounded-lg border p-4">
            <div class="flex items-center justify-between">
                <h3 class="font-medium">Start a cross-department workflow</h3>
                <button type="button" class="rounded p-1 hover:bg-accent" aria-label="Close" @click="open = false">
                    <X class="size-4" />
                </button>
            </div>
            <div class="grid gap-3 sm:grid-cols-3">
                <div class="grid gap-1">
                    <Label for="tpl">Template</Label>
                    <NativeSelect id="tpl" v-model="form.template">
                        <option v-for="t in templates" :key="t.id" :value="String(t.id)">{{ t.name }}</option>
                    </NativeSelect>
                </div>
                <div class="grid gap-1">
                    <Label for="tpl-project">Project (optional)</Label>
                    <NativeSelect id="tpl-project" v-model="form.project_id">
                        <option value="">None</option>
                        <option v-for="p in projects" :key="p.id" :value="String(p.id)">{{ p.name }}</option>
                    </NativeSelect>
                </div>
                <div class="grid gap-1">
                    <Label for="tpl-start">Start date</Label>
                    <Input id="tpl-start" v-model="form.start_on" type="date" />
                    <InputError :message="errors.start_on" />
                </div>
            </div>
            <div>
                <p class="mb-2 text-sm text-muted-foreground">Who owns each department's step? (optional)</p>
                <div class="grid gap-2 sm:grid-cols-3">
                    <label v-for="d in departments" :key="d.id" class="grid gap-1 text-sm">
                        <span>{{ d.name }}</span>
                        <NativeSelect v-model="form.assignees[d.id]">
                            <option value="">Unassigned</option>
                            <option v-for="m in members" :key="m.id" :value="String(m.id)">{{ m.name }}</option>
                        </NativeSelect>
                    </label>
                </div>
            </div>
            <Button :disabled="processing || !form.template" @click="submit">Create tasks</Button>
        </div>
    </div>
</template>
