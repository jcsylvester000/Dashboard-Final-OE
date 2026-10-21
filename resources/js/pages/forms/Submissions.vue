<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Download } from '@lucide/vue';
import FormController from '@/actions/App/Http/Controllers/Workspaces/FormController';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import WorkspaceHeader from '@/components/WorkspaceHeader.vue';
import { timeAgo } from '@/lib/format';
import type { WorkspaceHeader as Header } from '@/types/workspace';

/**
 * E-53 / E-54: submissions of one form (latest 500), each with the version it was made
 * with, who sent it and the task it created. Export to Excel, PDF or CSV.
 */
const props = defineProps<{
    workspace: Header;
    form: {
        id: number;
        name: string;
        version: number | null;
        can_fill: boolean;
    };
    submissions: {
        id: number;
        reference: string;
        version: number | null;
        created_at: string | null;
        by: string;
        public: boolean;
        answers: { label: string; value: string }[];
        task: { title: string; url: string } | null;
    }[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Form submissions', href: '#' }] },
});

const args = { workspace: props.workspace.slug, form: props.form.id };
const exportUrl = (format: string) =>
    FormController.submissions.url(args, { query: { format } });
</script>

<template>
    <Head :title="`${form.name} submissions`" />
    <div class="flex flex-1 flex-col gap-4 p-4">
        <WorkspaceHeader :workspace="workspace" />
        <Card>
            <CardHeader
                class="flex flex-row flex-wrap items-center justify-between gap-2"
            >
                <CardTitle class="text-base"
                    >{{ form.name }} · {{ submissions.length }} submission{{
                        submissions.length === 1 ? '' : 's'
                    }}</CardTitle
                >
                <div class="flex items-center gap-3 text-xs">
                    <Link
                        v-if="form.can_fill"
                        :href="FormController.fill.url(args)"
                        class="underline"
                        >Fill in</Link
                    >
                    <a
                        v-for="f in ['xlsx', 'pdf', 'csv']"
                        :key="f"
                        :href="exportUrl(f)"
                        class="inline-flex items-center gap-1 underline"
                        ><Download class="size-3.5" /> {{ f.toUpperCase() }}</a
                    >
                </div>
            </CardHeader>
            <CardContent>
                <p
                    v-if="!submissions.length"
                    class="text-sm text-muted-foreground"
                >
                    No submissions yet.
                </p>
                <ul class="space-y-3">
                    <li
                        v-for="s in submissions"
                        :key="s.id"
                        class="rounded border p-3 text-sm"
                    >
                        <p class="flex flex-wrap items-center gap-2">
                            <strong>{{ s.reference }}</strong>
                            <span class="text-xs text-muted-foreground"
                                >{{ s.by
                                }}<template v-if="s.public">
                                    (client link)</template
                                >
                                · {{ timeAgo(s.created_at) }} · form v{{
                                    s.version
                                }}</span
                            >
                            <Link
                                v-if="s.task"
                                :href="s.task.url"
                                class="ml-auto text-xs underline"
                                >Task: {{ s.task.title }}</Link
                            >
                        </p>
                        <dl
                            class="mt-2 grid gap-x-4 gap-y-1 sm:grid-cols-[12rem_1fr]"
                        >
                            <template v-for="(a, i) in s.answers" :key="i">
                                <dt class="text-xs text-muted-foreground">
                                    {{ a.label }}
                                </dt>
                                <dd class="whitespace-pre-line">
                                    {{ a.value }}
                                </dd>
                            </template>
                        </dl>
                    </li>
                </ul>
            </CardContent>
        </Card>
    </div>
</template>
