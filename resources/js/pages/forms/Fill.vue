<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import FormController from '@/actions/App/Http/Controllers/Workspaces/FormController';
import FormRenderer from '@/components/forms/FormRenderer.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import WorkspaceHeader from '@/components/WorkspaceHeader.vue';
import type { Answers, FormSchemaShape } from '@/lib/forms';
import { validate } from '@/lib/forms';
import type { WorkspaceHeader as Header } from '@/types/workspace';

/**
 * E-53: fill in a published form inside the app (QA checklists, internal requests).
 * The idempotency key makes a double click or a retry create only one submission.
 */
const props = defineProps<{
    workspace: Header;
    form: {
        id: number;
        name: string;
        description: string | null;
        schema: FormSchemaShape;
    };
    idempotencyKey: string;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Fill in form', href: '#' }] },
});

const answers = ref<Answers>({});
const files = ref<Record<string, File[]>>({});
const errors = ref<Record<string, string>>({});
const busy = ref(false);

function submit(): void {
    errors.value = validate(
        props.form.schema,
        answers.value,
        Object.fromEntries(
            Object.entries(files.value).map(([k, v]) => [k, v.length]),
        ),
    );
    if (Object.keys(errors.value).length) {
        return;
    }
    busy.value = true;
    router.post(
        FormController.submit.url({
            workspace: props.workspace.slug,
            form: props.form.id,
        }),
        {
            answers: answers.value,
            files: files.value,
            idempotency_key: props.idempotencyKey,
        },
        {
            forceFormData: true,
            onError: (e) => {
                errors.value = Object.fromEntries(
                    Object.entries(e).map(([k, v]) => [
                        k.replace(/^(answers|files)\./, ''),
                        String(v),
                    ]),
                );
            },
            onFinish: () => (busy.value = false),
        },
    );
}
</script>

<template>
    <Head :title="form.name" />
    <div class="flex flex-1 flex-col gap-4 p-4">
        <WorkspaceHeader :workspace="workspace" />
        <Card class="max-w-3xl">
            <CardHeader>
                <CardTitle>{{ form.name }}</CardTitle>
                <CardDescription
                    v-if="form.description"
                    class="whitespace-pre-line"
                    >{{ form.description }}</CardDescription
                >
            </CardHeader>
            <CardContent class="space-y-4">
                <FormRenderer
                    v-model:answers="answers"
                    v-model:files="files"
                    :schema="form.schema"
                    :errors="errors"
                />
                <p
                    v-if="errors.form"
                    class="text-sm text-rose-600"
                    role="alert"
                >
                    {{ errors.form }}
                </p>
                <Button :disabled="busy" @click="submit">{{
                    busy ? 'Sending…' : 'Submit'
                }}</Button>
            </CardContent>
        </Card>
    </div>
</template>
