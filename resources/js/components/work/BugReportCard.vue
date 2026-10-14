<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Bug } from '@lucide/vue';
import TaskController from '@/actions/App/Http/Controllers/Work/TaskController';
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
import { Label } from '@/components/ui/label';
import type { BugReport } from '@/types/work';

/**
 * E-21: steps, expected, actual (required), environment and severity, so a bug can be
 * reproduced on the first read. Paste screenshots into a comment (E-10).
 */
const props = defineProps<{
    workspaceSlug: string;
    taskId: number;
    report: BugReport;
    canEdit: boolean;
}>();

const form = useForm({
    steps: props.report.steps ?? '',
    expected: props.report.expected ?? '',
    actual: props.report.actual ?? '',
    environment: props.report.environment ?? '',
    severity: props.report.severity ?? 'medium',
});

function save(): void {
    form.transform((d) => ({
        bug_report: { ...d, environment: d.environment || null },
    })).put(
        TaskController.update.url({
            workspace: props.workspaceSlug,
            task: props.taskId,
        }),
        { preserveScroll: true },
    );
}
const err = (k: string): string | undefined =>
    (form.errors as Record<string, string>)[`bug_report.${k}`];
const fields = [
    {
        key: 'steps',
        label: 'Steps to reproduce',
        rows: 4,
        placeholder: '1. Open …\n2. Click …\n3. See …',
    },
    {
        key: 'expected',
        label: 'Expected result',
        rows: 2,
        placeholder: 'What should happen',
    },
    {
        key: 'actual',
        label: 'Actual result',
        rows: 2,
        placeholder: 'What happens instead (paste the error message)',
    },
] as const;
</script>

<template>
    <Card class="border-rose-200 dark:border-rose-900">
        <CardHeader>
            <CardTitle class="flex items-center gap-2 text-base"
                ><Bug class="size-4 text-rose-600" /> Bug report</CardTitle
            >
            <CardDescription
                >Steps, expected and actual are required. Paste screenshots into
                a comment.</CardDescription
            >
        </CardHeader>
        <CardContent>
            <form class="grid gap-3 text-sm" @submit.prevent="save">
                <div v-for="f in fields" :key="f.key" class="grid gap-1">
                    <Label :for="`bug-${f.key}`">{{ f.label }}</Label>
                    <textarea
                        :id="`bug-${f.key}`"
                        v-model="form[f.key]"
                        :rows="f.rows"
                        :placeholder="f.placeholder"
                        :disabled="!canEdit"
                        class="rounded-md border bg-transparent px-3 py-2 font-mono text-xs"
                    />
                    <InputError :message="err(f.key)" />
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="grid gap-1">
                        <Label for="bug-env">Environment</Label>
                        <input
                            id="bug-env"
                            v-model="form.environment"
                            :disabled="!canEdit"
                            maxlength="500"
                            class="h-9 rounded-md border bg-transparent px-3"
                            placeholder="Chrome 141 · Windows 11 · staging · build 2026.10.14"
                        />
                    </div>
                    <div class="grid gap-1">
                        <Label for="bug-sev">Severity</Label>
                        <NativeSelect
                            id="bug-sev"
                            v-model="form.severity"
                            :disabled="!canEdit"
                        >
                            <option value="critical">
                                Critical (blocks work)
                            </option>
                            <option value="high">High</option>
                            <option value="medium">Medium</option>
                            <option value="low">Low</option>
                        </NativeSelect>
                    </div>
                </div>
                <div v-if="canEdit">
                    <Button size="sm" type="submit" :disabled="form.processing"
                        >Save bug report</Button
                    >
                </div>
            </form>
        </CardContent>
    </Card>
</template>
