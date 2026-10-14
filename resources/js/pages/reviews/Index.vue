<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { FileText } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import TaskFlowController from '@/actions/App/Http/Controllers/Work/TaskFlowController';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import TaskMeta from '@/components/work/TaskMeta.vue';
import { timeAgo } from '@/lib/format';
import { index as reviewsIndex } from '@/routes/reviews';
import { show } from '@/routes/workspaces/tasks';
import type { TaskRow } from '@/types/work';

/**
 * E-32: everything waiting for my decision. Keys: J/K move, A approve, R request
 * changes (note), O or Enter open the task.
 */
type Item = TaskRow & {
    review_requested_at: string | null;
    progress: { approved: number; total: number; waiting: string[] } | null;
    file: {
        name: string;
        version: number;
        image: boolean;
        url: string;
        thumb_url: string | null;
    } | null;
};
const props = defineProps<{ items: Item[] }>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Reviews', href: reviewsIndex() }] },
});

const current = ref(0);
const noteFor = ref<number | null>(null);
const note = ref('');
const error = ref<{ id: number; message: string } | null>(null);
const item = computed(() => props.items[current.value]);

function decide(it: Item, decision: 'approve' | 'changes'): void {
    router.post(
        TaskFlowController.review.url({
            workspace: it.workspace.slug,
            task: it.id,
        }),
        { decision, note: decision === 'changes' ? note.value : null },
        {
            preserveScroll: true,
            // Keep local state (the error ref, the open note) across the reload.
            preserveState: true,
            onStart: () => {
                error.value = null;
            },
            // A blocked Done (open checklist item, unapproved file) comes back as a
            // validation error; show it on the row instead of failing silently.
            onError: (errors) => {
                error.value = {
                    id: it.id,
                    message:
                        Object.values(errors)[0] ??
                        'Could not save your decision.',
                };
            },
            onSuccess: () => {
                noteFor.value = null;
                note.value = '';
                current.value = Math.min(
                    current.value,
                    Math.max(0, props.items.length - 1),
                );
            },
        },
    );
}

function onKey(e: KeyboardEvent): void {
    const tag = (e.target as HTMLElement | null)?.tagName;
    if (tag === 'TEXTAREA' || tag === 'INPUT' || !item.value) {
        return;
    }
    if (e.key === 'j') {
        current.value = Math.min(props.items.length - 1, current.value + 1);
    } else if (e.key === 'k') {
        current.value = Math.max(0, current.value - 1);
    } else if (e.key === 'a') {
        decide(item.value, 'approve');
    } else if (e.key === 'r') {
        e.preventDefault();
        noteFor.value = item.value.id;
    } else if (e.key === 'o' || e.key === 'Enter') {
        router.visit(
            show.url({
                workspace: item.value.workspace.slug,
                task: item.value.id,
            }),
        );
    }
}
onMounted(() => window.addEventListener('keydown', onKey));
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));
</script>

<template>
    <Head title="Reviews" />

    <div class="flex max-w-4xl flex-1 flex-col gap-4 p-4">
        <Heading
            title="Reviews"
            :description="`${items.length} waiting for your decision, oldest first. Keys: J/K move · A approve · R request changes · O open.`"
        />

        <p
            v-if="items.length === 0"
            class="rounded-lg border p-6 text-center text-sm text-muted-foreground"
        >
            Nothing waiting for you. 🎉
        </p>

        <ul class="space-y-2">
            <li
                v-for="(it, i) in items"
                :key="it.id"
                class="flex gap-3 rounded-lg border p-3 transition"
                :class="
                    i === current ? 'border-primary ring-2 ring-primary/30' : ''
                "
                @click="current = i"
            >
                <div
                    class="flex size-16 shrink-0 items-center justify-center overflow-hidden rounded bg-muted"
                >
                    <img
                        v-if="it.file?.image"
                        :src="it.file.thumb_url ?? it.file.url"
                        :alt="it.file.name"
                        class="size-full object-cover"
                        loading="lazy"
                    />
                    <FileText v-else class="size-6 text-muted-foreground" />
                </div>
                <div class="min-w-0 flex-1 space-y-1">
                    <Link
                        prefetch
                        :href="
                            show({ workspace: it.workspace.slug, task: it.id })
                        "
                        class="font-medium hover:underline"
                        >{{ it.title }}</Link
                    >
                    <TaskMeta :task="it" show-workspace />
                    <p class="text-xs text-muted-foreground">
                        Waiting
                        {{
                            it.review_requested_at
                                ? timeAgo(it.review_requested_at)
                                : '—'
                        }}
                        <template v-if="it.file">
                            · {{ it.file.name }} v{{
                                it.file.version
                            }}</template
                        >
                        <template v-if="it.progress">
                            · {{ it.progress.approved }}/{{
                                it.progress.total
                            }}
                            approved</template
                        >
                    </p>
                    <p
                        v-if="error?.id === it.id"
                        role="alert"
                        class="rounded-md bg-destructive/10 px-2 py-1 text-xs text-destructive"
                    >
                        {{ error.message }}
                        <Link
                            prefetch
                            :href="
                                show({
                                    workspace: it.workspace.slug,
                                    task: it.id,
                                })
                            "
                            class="font-medium underline"
                            >Open task</Link
                        >
                    </p>
                    <form
                        v-if="noteFor === it.id"
                        class="flex flex-wrap gap-2 pt-1"
                        @submit.prevent="decide(it, 'changes')"
                    >
                        <textarea
                            v-model="note"
                            v-focus
                            rows="2"
                            required
                            class="min-w-0 flex-1 rounded-md border bg-transparent p-2 text-sm"
                            placeholder="What needs to change?"
                            aria-label="What needs to change"
                        />
                        <div class="flex flex-col gap-1">
                            <Button
                                size="sm"
                                type="submit"
                                :disabled="!note.trim()"
                                >Send back</Button
                            >
                            <Button
                                size="sm"
                                type="button"
                                variant="ghost"
                                @click="noteFor = null"
                                >Cancel</Button
                            >
                        </div>
                    </form>
                </div>
                <div
                    v-if="noteFor !== it.id"
                    class="flex shrink-0 flex-col gap-1"
                >
                    <Button size="sm" @click.stop="decide(it, 'approve')"
                        >Approve</Button
                    >
                    <Button
                        size="sm"
                        variant="outline"
                        @click.stop="noteFor = it.id"
                        >Changes</Button
                    >
                </div>
            </li>
        </ul>
    </div>
</template>
