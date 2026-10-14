<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { BadgeCheck, CircleDashed, RotateCcw, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import TaskFlowController from '@/actions/App/Http/Controllers/Work/TaskFlowController';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { avatarColor, getInitials } from '@/composables/useInitials';

/**
 * E-07: who reviews this task and how many must approve. Chips show each reviewer's
 * decision for the current round (a new round starts when the task enters In Review).
 */
type Reviewer = {
    user_id: number;
    name: string;
    decision: 'approved' | 'changes' | null;
    note: string | null;
};
const props = defineProps<{
    workspaceSlug: string;
    taskId: number;
    assigneeId: number | null;
    reviewers: Reviewer[];
    rule: 'all' | 'any';
    members: { id: number; name: string }[];
    canEdit: boolean;
}>();

const error = ref<string | null>(null);
const approved = computed(
    () => props.reviewers.filter((r) => r.decision === 'approved').length,
);
const candidates = computed(() =>
    props.members.filter(
        (m) =>
            m.id !== props.assigneeId &&
            !props.reviewers.some((r) => r.user_id === m.id),
    ),
);

function save(ids: number[], rule: string): void {
    error.value = null;
    router.put(
        TaskFlowController.reviewers.url({
            workspace: props.workspaceSlug,
            task: props.taskId,
        }),
        { user_ids: ids, rule },
        {
            preserveScroll: true,
            onError: (e) =>
                (error.value = Object.values(e)[0] ?? 'Could not save.'),
        },
    );
}
function add(id: string | number | null | undefined): void {
    if (id) {
        save(
            [...props.reviewers.map((r) => r.user_id), Number(id)],
            props.rule,
        );
    }
}
function remove(id: number): void {
    save(
        props.reviewers.filter((r) => r.user_id !== id).map((r) => r.user_id),
        props.rule,
    );
}
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle class="text-base">Reviewers</CardTitle>
            <CardDescription v-if="reviewers.length">
                {{ approved }} of {{ reviewers.length }} approved ·
                {{
                    rule === 'any'
                        ? 'any one approval is enough'
                        : 'everyone must approve'
                }}
            </CardDescription>
            <CardDescription v-else
                >No one named: the project or department lead
                reviews.</CardDescription
            >
        </CardHeader>
        <CardContent class="space-y-3 text-sm">
            <ul v-if="reviewers.length" class="space-y-1.5">
                <li
                    v-for="r in reviewers"
                    :key="r.user_id"
                    class="flex items-center gap-2"
                >
                    <span
                        class="flex size-6 shrink-0 items-center justify-center rounded-full text-[10px] font-semibold"
                        :class="avatarColor(r.name)"
                        >{{ getInitials(r.name) }}</span
                    >
                    <span class="min-w-0 flex-1 truncate">{{ r.name }}</span>
                    <span
                        v-if="r.decision === 'approved'"
                        class="inline-flex items-center gap-1 text-xs text-emerald-700"
                        ><BadgeCheck class="size-3.5" /> Approved</span
                    >
                    <span
                        v-else-if="r.decision === 'changes'"
                        class="inline-flex items-center gap-1 text-xs text-amber-700"
                        :title="r.note ?? ''"
                        ><RotateCcw class="size-3.5" /> Changes</span
                    >
                    <span
                        v-else
                        class="inline-flex items-center gap-1 text-xs text-muted-foreground"
                        ><CircleDashed class="size-3.5" /> Waiting</span
                    >
                    <button
                        v-if="canEdit"
                        type="button"
                        class="rounded p-0.5 text-muted-foreground hover:text-foreground"
                        :aria-label="`Remove ${r.name} as reviewer`"
                        @click="remove(r.user_id)"
                    >
                        <X class="size-3.5" />
                    </button>
                </li>
            </ul>
            <div v-if="canEdit" class="grid gap-2">
                <NativeSelect
                    :model-value="''"
                    aria-label="Add reviewer"
                    @update:model-value="add"
                >
                    <option value="">+ Add reviewer…</option>
                    <option v-for="m in candidates" :key="m.id" :value="m.id">
                        {{ m.name }}
                    </option>
                </NativeSelect>
                <NativeSelect
                    v-if="reviewers.length > 1"
                    :model-value="rule"
                    aria-label="Approval rule"
                    @update:model-value="
                        (v) =>
                            save(
                                reviewers.map((r) => r.user_id),
                                String(v),
                            )
                    "
                >
                    <option value="all">Everyone must approve</option>
                    <option value="any">Any one approval is enough</option>
                </NativeSelect>
            </div>
            <InputError :message="error ?? undefined" />
        </CardContent>
    </Card>
</template>
