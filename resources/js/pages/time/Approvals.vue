<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import TimeController from '@/actions/App/Http/Controllers/Work/TimeController';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { addDays, formatDate, formatMinutes } from '@/lib/format';
import { approvals } from '@/routes/time';
import { show } from '@/routes/workspaces/tasks';
import type { TimeRow } from '@/types/time';

type Row = TimeRow & { user: { id: number; name: string }; approver: string | null; is_mine: boolean };

const props = defineProps<{
    week: { start: string; end: string };
    filters: { workspace?: number | string; status: 'pending' | 'approved' };
    entries: Row[];
    workspaces: { id: number; name: string }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Time approvals', href: approvals() }],
    },
});

const selected = ref<number[]>([]);
watch(() => props.entries, () => (selected.value = []));

const selectable = computed(() => props.entries.filter((e) => !e.is_mine));
const total = computed(() => props.entries.reduce((s, e) => s + e.minutes, 0));
const allSelected = computed(() => selectable.value.length > 0 && selected.value.length === selectable.value.length);

function toggleAll(): void {
    selected.value = allSelected.value ? [] : selectable.value.map((e) => e.id);
}

function visit(query: Record<string, string>): void {
    const base: Record<string, string> = { week: props.week.start, status: props.filters.status };
    if (props.filters.workspace) {
        base.workspace = String(props.filters.workspace);
    }
    router.get(approvals.url({ query: { ...base, ...query } }), {}, { preserveScroll: true });
}

function act(action: 'approve' | 'unapprove'): void {
    router.post(TimeController.approve.url(), { ids: selected.value, action }, { preserveScroll: true });
}
</script>

<template>
    <Head title="Time approvals" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">Time approvals</h1>
                <p class="text-sm text-muted-foreground">
                    Time logged in workspaces you lead. Approved time is locked and becomes billable in invoices (P6).
                </p>
            </div>
            <div class="flex items-center gap-2">
                <Button variant="outline" size="sm" aria-label="Previous week" @click="visit({ week: addDays(week.start, -7) })"><ChevronLeft /></Button>
                <span class="text-sm">{{ week.start }} – {{ week.end }}</span>
                <Button variant="outline" size="sm" aria-label="Next week" @click="visit({ week: addDays(week.start, 7) })"><ChevronRight /></Button>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <NativeSelect :model-value="filters.status" class="w-36" aria-label="Status" @update:model-value="(v) => visit({ status: String(v) })">
                <option value="pending">Pending</option>
                <option value="approved">Approved</option>
            </NativeSelect>
            <NativeSelect :model-value="filters.workspace ? String(filters.workspace) : ''" class="w-48" aria-label="Client" @update:model-value="(v) => visit({ workspace: String(v ?? '') })">
                <option value="">All clients</option>
                <option v-for="w in workspaces" :key="w.id" :value="String(w.id)">{{ w.name }}</option>
            </NativeSelect>
            <span class="text-sm text-muted-foreground">{{ formatMinutes(total) }} shown</span>
            <div class="ml-auto flex gap-2">
                <Button v-if="filters.status === 'pending'" :disabled="selected.length === 0" @click="act('approve')">
                    Approve {{ selected.length || '' }}
                </Button>
                <Button v-else variant="outline" :disabled="selected.length === 0" @click="act('unapprove')">
                    Un-approve {{ selected.length || '' }}
                </Button>
            </div>
        </div>

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-left text-sm">
                <thead class="bg-muted/50 text-xs text-muted-foreground uppercase">
                    <tr>
                        <th class="w-8 px-3 py-2">
                            <input type="checkbox" class="size-4" :checked="allSelected" aria-label="Select all" @change="toggleAll" />
                        </th>
                        <th class="px-3 py-2 font-medium">Date</th>
                        <th class="px-3 py-2 font-medium">Person</th>
                        <th class="px-3 py-2 font-medium">Task</th>
                        <th class="px-3 py-2 text-right font-medium">Time</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-if="entries.length === 0">
                        <td colspan="5" class="px-3 py-8 text-center text-muted-foreground">Nothing to review for this week.</td>
                    </tr>
                    <tr v-for="e in entries" :key="e.id">
                        <td class="px-3 py-2">
                            <input
                                v-model="selected"
                                type="checkbox"
                                class="size-4"
                                :value="e.id"
                                :disabled="e.is_mine"
                                :title="e.is_mine ? 'You cannot approve your own time' : ''"
                                :aria-label="`Select entry ${e.id}`"
                            />
                        </td>
                        <td class="px-3 py-2 whitespace-nowrap">{{ formatDate(e.entry_date) }}</td>
                        <td class="px-3 py-2">{{ e.user.name }}</td>
                        <td class="px-3 py-2">
                            <Link v-if="e.task" :href="show({ workspace: e.workspace.slug, task: e.task.id })" class="hover:underline">{{ e.task.title }}</Link>
                            <span class="block text-xs text-muted-foreground">
                                {{ e.workspace.name }}<template v-if="e.note"> · {{ e.note }}</template
                                ><template v-if="!e.is_billable"> · non-billable</template
                                ><template v-if="e.approver"> · approved by {{ e.approver }}</template>
                            </span>
                        </td>
                        <td class="px-3 py-2 text-right font-medium tabular-nums">{{ formatMinutes(e.minutes) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
