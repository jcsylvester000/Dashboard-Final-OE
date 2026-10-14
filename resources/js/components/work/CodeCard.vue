<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import {
    ExternalLink,
    GitBranch,
    GitCommitHorizontal,
    GitPullRequest,
} from '@lucide/vue';
import { computed } from 'vue';
import TaskPreviewController from '@/actions/App/Http/Controllers/Work/TaskPreviewController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { timeAgo } from '@/lib/format';
import type { CodeLink } from '@/types/work';

/**
 * E-24 / E-26: code and preview for a task. Pull requests, branches and commits that
 * name the task key appear here from GitHub (with checks and reviewers); the preview
 * link is checked every 15 minutes and shows green or red.
 */
const props = defineProps<{
    workspaceSlug: string;
    taskId: number;
    taskKey: string | null;
    links: CodeLink[];
    preview: {
        url: string;
        status: 'up' | 'down' | null;
        http: number | null;
        checked_at: string | null;
    } | null;
    canEdit: boolean;
    showEmpty: boolean;
}>();

const form = useForm({ preview_url: props.preview?.url ?? '' });
const editing = computed(() => props.canEdit);
const prs = computed(() => props.links.filter((l) => l.kind === 'pr'));
const others = computed(() => props.links.filter((l) => l.kind !== 'pr'));

function save(): void {
    form.put(
        TaskPreviewController.update.url({
            workspace: props.workspaceSlug,
            task: props.taskId,
        }),
        { preserveScroll: true, only: ['task', 'flash'] },
    );
}
const stateClass = (s: CodeLink['state']) =>
    s === 'merged'
        ? 'bg-violet-100 text-violet-900 dark:bg-violet-950 dark:text-violet-200'
        : s === 'open'
          ? 'bg-emerald-100 text-emerald-900 dark:bg-emerald-950 dark:text-emerald-200'
          : 'bg-muted text-muted-foreground';
const checkLabel: Record<string, string> = {
    success: 'Checks passed',
    failure: 'Checks failed',
    pending: 'Checks running',
};
</script>

<template>
    <Card v-if="showEmpty || links.length || preview">
        <CardHeader>
            <CardTitle class="text-base">Code and preview</CardTitle>
            <CardDescription
                >Put
                <code class="rounded bg-muted px-1">{{
                    taskKey ?? 'the task key'
                }}</code>
                in a branch name or PR title and it links here.</CardDescription
            >
        </CardHeader>
        <CardContent class="space-y-3 text-sm">
            <ul v-if="prs.length" class="space-y-2">
                <li v-for="l in prs" :key="l.id" class="flex items-start gap-2">
                    <GitPullRequest
                        class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                    />
                    <div class="min-w-0 flex-1">
                        <a
                            :href="l.url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="block truncate font-medium hover:underline"
                            >{{ l.ref }} {{ l.title }}</a
                        >
                        <p
                            class="flex flex-wrap items-center gap-1 text-xs text-muted-foreground"
                        >
                            <span
                                class="rounded px-1 capitalize"
                                :class="stateClass(l.state)"
                                >{{ l.state ?? 'open' }}</span
                            >
                            <span
                                v-if="l.checks"
                                class="inline-flex items-center gap-1"
                                :title="checkLabel[l.checks]"
                                ><span
                                    class="inline-block size-2 rounded-full"
                                    :class="
                                        l.checks === 'success'
                                            ? 'bg-emerald-500'
                                            : l.checks === 'failure'
                                              ? 'bg-rose-500'
                                              : 'bg-amber-400'
                                    "
                                />{{ checkLabel[l.checks] }}</span
                            >
                            <span v-if="l.reviewers.length"
                                >· reviewers {{ l.reviewers.join(', ') }}</span
                            >
                            <span>· {{ l.repository }}</span>
                            <span v-if="l.updated_at"
                                >· {{ timeAgo(l.updated_at) }}</span
                            >
                        </p>
                    </div>
                </li>
            </ul>
            <ul v-if="others.length" class="space-y-1 text-xs">
                <li
                    v-for="l in others"
                    :key="l.id"
                    class="flex items-center gap-2"
                >
                    <GitBranch
                        v-if="l.kind === 'branch'"
                        class="size-3.5 text-muted-foreground"
                    />
                    <GitCommitHorizontal
                        v-else
                        class="size-3.5 text-muted-foreground"
                    />
                    <a
                        :href="l.url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="truncate hover:underline"
                        >{{
                            l.kind === 'commit'
                                ? `${l.ref} ${l.title ?? ''}`
                                : l.ref
                        }}</a
                    >
                </li>
            </ul>

            <div class="space-y-1 border-t pt-3">
                <p class="flex items-center gap-2 text-xs font-medium">
                    Preview link
                    <span
                        v-if="preview?.status"
                        class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[11px]"
                        :class="
                            preview.status === 'up'
                                ? 'bg-emerald-100 text-emerald-900 dark:bg-emerald-950 dark:text-emerald-200'
                                : 'bg-rose-100 text-rose-900 dark:bg-rose-950 dark:text-rose-200'
                        "
                        role="status"
                        >{{ preview.status === 'up' ? 'Up' : 'Down'
                        }}<template v-if="preview.http">
                            · {{ preview.http }}</template
                        ></span
                    >
                    <span
                        v-if="preview?.checked_at"
                        class="font-normal text-muted-foreground"
                        >checked {{ timeAgo(preview.checked_at) }}</span
                    >
                    <a
                        v-if="preview"
                        :href="preview.url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="ml-auto inline-flex items-center gap-1 font-normal hover:underline"
                        ><ExternalLink class="size-3.5" /> Open</a
                    >
                </p>
                <form v-if="editing" class="flex gap-2" @submit.prevent="save">
                    <Input
                        v-model="form.preview_url"
                        type="url"
                        class="min-w-0 flex-1"
                        placeholder="https://staging.example.com"
                        aria-label="Preview link"
                    />
                    <Button
                        type="submit"
                        size="sm"
                        variant="secondary"
                        :disabled="form.processing"
                        >{{ form.processing ? 'Checking…' : 'Save' }}</Button
                    >
                </form>
                <InputError :message="form.errors.preview_url" />
            </div>
        </CardContent>
    </Card>
</template>
