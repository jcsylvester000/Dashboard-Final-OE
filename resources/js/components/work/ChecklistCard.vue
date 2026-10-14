<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ListChecks, Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import ChecklistController from '@/actions/App/Http/Controllers/Work/ChecklistController';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';

/**
 * E-36: definition of done. Required lines must be ticked before the task can be Done.
 */
type Item = {
    id: number;
    label: string;
    required: boolean;
    done: boolean;
    done_by: string | null;
};
const props = defineProps<{
    workspaceSlug: string;
    taskId: number;
    items: Item[];
    canEdit: boolean;
}>();

const done = computed(() => props.items.filter((i) => i.done).length);
const form = useForm({ label: '', required: true });
const args = (item?: Item) => ({
    workspace: props.workspaceSlug,
    task: props.taskId,
    ...(item ? { checklistItem: item.id } : {}),
});

function add(): void {
    form.post(ChecklistController.store.url(args()), {
        preserveScroll: true,
        onSuccess: () => form.reset('label'),
    });
}
function toggle(item: Item): void {
    router.patch(
        ChecklistController.update.url(
            args(item) as {
                workspace: string;
                task: number;
                checklistItem: number;
            },
        ),
        { done: !item.done },
        { preserveScroll: true, only: ['task', 'timeline'] },
    );
}
function remove(item: Item): void {
    router.delete(
        ChecklistController.destroy.url(
            args(item) as {
                workspace: string;
                task: number;
                checklistItem: number;
            },
        ),
        { preserveScroll: true, only: ['task'] },
    );
}
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle class="flex items-center gap-2 text-base"
                ><ListChecks class="size-4" /> Checklist
                <span
                    v-if="items.length"
                    class="text-sm font-normal text-muted-foreground"
                    >· {{ done }}/{{ items.length }}</span
                ></CardTitle
            >
            <CardDescription
                >Required lines must be ticked before Done.</CardDescription
            >
        </CardHeader>
        <CardContent class="space-y-2 text-sm">
            <div
                v-if="items.length"
                class="h-1.5 overflow-hidden rounded bg-muted"
            >
                <div
                    class="h-full bg-emerald-500 transition-all"
                    :style="{ width: `${(done / items.length) * 100}%` }"
                />
            </div>
            <ul class="space-y-1">
                <li
                    v-for="i in items"
                    :key="i.id"
                    class="group flex items-center gap-2"
                >
                    <input
                        :id="`cl-${i.id}`"
                        type="checkbox"
                        class="size-4"
                        :checked="i.done"
                        :disabled="!canEdit"
                        @change="toggle(i)"
                    />
                    <label
                        :for="`cl-${i.id}`"
                        class="min-w-0 flex-1"
                        :class="
                            i.done ? 'text-muted-foreground line-through' : ''
                        "
                    >
                        {{ i.label
                        }}<span
                            v-if="!i.required"
                            class="ml-1 text-[10px] text-muted-foreground no-underline"
                            >(optional)</span
                        >
                    </label>
                    <button
                        v-if="canEdit"
                        type="button"
                        class="rounded p-0.5 text-muted-foreground opacity-0 group-hover:opacity-100 focus:opacity-100"
                        :aria-label="`Remove ${i.label}`"
                        @click="remove(i)"
                    >
                        <Trash2 class="size-3.5" />
                    </button>
                </li>
            </ul>
            <form
                v-if="canEdit"
                class="flex flex-wrap items-center gap-2 pt-1"
                @submit.prevent="add"
            >
                <Input
                    v-model="form.label"
                    class="h-8 min-w-0 flex-1"
                    maxlength="200"
                    placeholder="Add a line, e.g. Tested on mobile"
                    aria-label="New checklist line"
                    required
                />
                <label class="inline-flex items-center gap-1 text-xs"
                    ><input
                        v-model="form.required"
                        type="checkbox"
                        class="size-3.5"
                    />
                    Required</label
                >
                <Button size="sm" type="submit" :disabled="form.processing"
                    >Add</Button
                >
            </form>
        </CardContent>
    </Card>
</template>
