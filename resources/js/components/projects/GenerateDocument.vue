<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { FileText } from '@lucide/vue';
import DocumentTemplateController from '@/actions/App/Http/Controllers/Workspaces/DocumentTemplateController';
import { Button } from '@/components/ui/button';

/**
 * E-52: make a PDF for this project from a document template (proposal, SOW, brief,
 * monthly report). It is saved to the project's files below.
 */
const props = defineProps<{
    workspaceSlug: string;
    projectId: number;
    templates: { id: number; name: string; kind: string }[];
}>();

const form = useForm({ template_id: props.templates[0]?.id ?? null });

function generate(): void {
    form.post(
        DocumentTemplateController.generate.url({
            workspace: props.workspaceSlug,
            project: props.projectId,
        }),
        {
            preserveScroll: true,
            only: ['attachments', 'flash'],
        },
    );
}
</script>

<template>
    <div
        v-if="templates.length"
        class="flex flex-wrap items-center gap-2 rounded-md border p-3 text-sm"
    >
        <FileText class="size-4 text-muted-foreground" />
        <span>Create a document:</span>
        <select
            v-model="form.template_id"
            class="h-8 rounded-md border bg-background px-2"
            aria-label="Document template"
        >
            <option v-for="t in templates" :key="t.id" :value="t.id">
                {{ t.name }}
            </option>
        </select>
        <Button
            size="sm"
            variant="secondary"
            :disabled="form.processing || !form.template_id"
            @click="generate"
            >{{ form.processing ? 'Making PDF…' : 'Make PDF' }}</Button
        >
        <span class="text-xs text-muted-foreground"
            >Saved to the project's files.</span
        >
    </div>
</template>
