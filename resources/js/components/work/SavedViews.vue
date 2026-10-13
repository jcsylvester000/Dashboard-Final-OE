<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Bookmark, Users, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { confirmDialog } from '@/composables/useConfirm';
import { destroy, store } from '@/routes/workspaces/views';
import { board, index } from '@/routes/workspaces/tasks';
import type { SavedViewRow, TaskFilters } from '@/types/work';

/**
 * Saved views (brutal scenario M1): one click to a named set of filters and grouping,
 * yours or shared with the client team ("My team this week").
 */
const props = defineProps<{
    workspaceSlug: string;
    page: 'list' | 'board';
    views: SavedViewRow[];
    filters: TaskFilters;
    canShare: boolean;
}>();

const KEYS = [
    'search',
    'status',
    'department',
    'project',
    'label',
    'assignee',
    'due',
    'type',
    'sprint',
    'group',
] as const;

const current = computed<Record<string, string>>(() => {
    const out: Record<string, string> = {};
    for (const k of KEYS) {
        const v = props.filters[k];
        if (v !== undefined && v !== null && v !== '') {
            out[k] = String(v);
        }
    }

    return out;
});
const same = (a: Record<string, string>, b: Record<string, string>) =>
    Object.keys(a).length === Object.keys(b).length &&
    Object.entries(a).every(([k, v]) => String(b[k] ?? '') === v);
const activeId = computed(
    () =>
        props.views.find(
            (v) => v.page === props.page && same(current.value, v.filters),
        )?.id ?? null,
);

function open(view: SavedViewRow): void {
    const url =
        view.page === 'board'
            ? board.url(props.workspaceSlug)
            : index.url(props.workspaceSlug);
    router.get(url, view.filters, {
        preserveScroll: false,
        preserveState: false,
    });
}

const saving = ref(false);
const form = useForm({ name: '', shared: false });

function save(): void {
    form.transform((d) => ({
        ...d,
        page: props.page,
        filters: current.value,
    })).post(store.url(props.workspaceSlug), {
        preserveScroll: true,
        onSuccess: () => {
            saving.value = false;
            form.reset();
        },
    });
}

async function remove(view: SavedViewRow): Promise<void> {
    if (
        await confirmDialog(
            `Delete the view "${view.name}"${view.shared ? ' for everyone' : ''}?`,
        )
    ) {
        router.delete(
            destroy.url({ workspace: props.workspaceSlug, view: view.id }),
            { preserveScroll: true },
        );
    }
}
</script>

<template>
    <div class="flex flex-wrap items-center gap-1.5 text-sm">
        <span
            class="mr-1 inline-flex items-center gap-1 text-xs text-muted-foreground"
            ><Bookmark class="size-3.5" /> Views</span
        >
        <span
            v-for="v in views"
            :key="v.id"
            class="inline-flex items-center rounded-full border text-xs"
            :class="v.id === activeId ? 'border-primary bg-primary/10' : ''"
        >
            <button
                type="button"
                class="inline-flex items-center gap-1 py-1 pr-1 pl-2.5"
                :title="`${v.page === 'board' ? 'Board' : 'List'} view by ${v.owner}`"
                @click="open(v)"
            >
                <Users
                    v-if="v.shared"
                    class="size-3 text-muted-foreground"
                    aria-label="Shared"
                />
                {{ v.name }}
            </button>
            <button
                v-if="v.can_delete"
                type="button"
                class="rounded-full p-1 text-muted-foreground hover:text-foreground"
                :aria-label="`Delete view ${v.name}`"
                @click="remove(v)"
            >
                <X class="size-3" />
            </button>
        </span>
        <span v-if="views.length === 0" class="text-xs text-muted-foreground"
            >None yet. Set filters, then save them as a view.</span
        >

        <Button
            v-if="!saving"
            size="sm"
            variant="ghost"
            class="h-7 text-xs"
            :disabled="Object.keys(current).length === 0"
            :title="
                Object.keys(current).length === 0
                    ? 'Set a filter or grouping first'
                    : ''
            "
            @click="saving = true"
        >
            + Save current view
        </Button>
        <form
            v-else
            class="flex flex-wrap items-center gap-2"
            @submit.prevent="save"
        >
            <Input
                v-model="form.name"
                v-focus
                class="h-7 w-48 text-xs"
                maxlength="60"
                placeholder="e.g. My team this week"
                aria-label="View name"
                required
            />
            <label
                v-if="canShare"
                class="inline-flex items-center gap-1 text-xs"
            >
                <input v-model="form.shared" type="checkbox" class="size-3.5" />
                Share with team
            </label>
            <Button
                size="sm"
                class="h-7 text-xs"
                type="submit"
                :disabled="form.processing"
                >Save</Button
            >
            <Button
                size="sm"
                variant="ghost"
                class="h-7 text-xs"
                type="button"
                @click="saving = false"
                >Cancel</Button
            >
            <InputError :message="form.errors.name" />
        </form>
    </div>
</template>
