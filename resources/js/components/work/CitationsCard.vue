<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Archive, BookOpen, Trash2 } from '@lucide/vue';
import CitationController from '@/actions/App/Http/Controllers/Work/CitationController';
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
import { confirmDialog } from '@/composables/useConfirm';
import type { TaskCitation } from '@/types/work';

/**
 * E-29: paste a link and it becomes a source with its title, site and the
 * date it was read. Sources print at the end of the work report.
 */
const props = defineProps<{
    workspaceSlug: string;
    taskId: number;
    citations: TaskCitation[];
    canEdit: boolean;
}>();

const form = useForm({ url: '' });

function add(): void {
    form.post(
        CitationController.store.url({
            workspace: props.workspaceSlug,
            task: props.taskId,
        }),
        {
            preserveScroll: true,
            only: ['task', 'flash'],
            onSuccess: () => form.reset('url'),
        },
    );
}

async function remove(c: TaskCitation): Promise<void> {
    if (!(await confirmDialog(`Remove the source "${c.title ?? c.url}"?`))) {
        return;
    }
    router.delete(
        CitationController.destroy.url({
            workspace: props.workspaceSlug,
            task: props.taskId,
            citation: c.id,
        }),
        { preserveScroll: true, only: ['task'] },
    );
}

function day(iso: string): string {
    return new Date(iso).toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}
</script>

<template>
    <Card v-if="citations.length || canEdit">
        <CardHeader>
            <CardTitle class="flex items-center gap-2 text-base"
                ><BookOpen class="size-4" /> Sources
                <span
                    v-if="citations.length"
                    class="text-sm font-normal text-muted-foreground"
                    >· {{ citations.length }}</span
                ></CardTitle
            >
            <CardDescription
                >Paste a link; the title and date read are saved. Sources print
                in the work report.</CardDescription
            >
        </CardHeader>
        <CardContent class="space-y-3 text-sm">
            <ol v-if="citations.length" class="space-y-2">
                <li
                    v-for="c in citations"
                    :key="c.id"
                    class="group flex items-start gap-2"
                >
                    <div class="min-w-0 flex-1">
                        <a
                            :href="c.url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="block truncate font-medium hover:underline"
                            :title="c.url"
                            >{{ c.title ?? c.url }}</a
                        >
                        <p class="text-xs text-muted-foreground">
                            {{ c.site ?? 'Unknown site' }} · read
                            {{ day(c.accessed_at) }} ·
                            <a
                                :href="c.archive_url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex items-center gap-0.5 hover:underline"
                                ><Archive class="size-3" /> Archived copy</a
                            >
                        </p>
                    </div>
                    <button
                        v-if="canEdit"
                        type="button"
                        class="rounded p-0.5 text-muted-foreground opacity-0 group-hover:opacity-100 focus:opacity-100"
                        :aria-label="`Remove source ${c.title ?? c.url}`"
                        @click="remove(c)"
                    >
                        <Trash2 class="size-3.5" />
                    </button>
                </li>
            </ol>
            <form
                v-if="canEdit"
                class="flex flex-wrap items-center gap-2"
                @submit.prevent="add"
            >
                <Input
                    v-model="form.url"
                    type="url"
                    class="min-w-0 flex-1"
                    placeholder="https://…"
                    aria-label="Source link"
                    required
                />
                <Button
                    type="submit"
                    size="sm"
                    :disabled="form.processing || !form.url"
                    >{{ form.processing ? 'Reading…' : 'Add source' }}</Button
                >
                <InputError :message="form.errors.url" class="w-full" />
            </form>
        </CardContent>
    </Card>
</template>
