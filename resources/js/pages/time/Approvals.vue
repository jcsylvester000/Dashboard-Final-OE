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

type Row = TimeRow & {
    user: { id: number; name: string };
    approver: string | null;
    is_mine: boolean;
    department: string | null;
};

const props = defineProps<{
    week: { start: string; end: string };
    filters: {
        workspace?: number | string;
        project?: number | string;
        status: 'pending' | 'approved';
    };
    entries: Row[];
    workspaces: { id: number; name: string }[];
    projects: { id: number; name: string }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Time approvals', href: approvals() }],
    },
});

const selected = ref<number[]>([]);
watch(
    () => props.entries,
    () => (selected.value = []),
);

const selectable = computed(() => props.entries.filter((e) => !e.is_mine));
const total = computed(() => props.entries.reduce((s, e) => s + e.minutes, 0));
const allSelected = computed(
    () =>
        selectable.value.length > 0 &&
        selected.value.length === selectable.value.length,
);

function toggleAll(): void {
    selected.value = allSelected.value ? [] : selectable.value.map((e) => e.id);
}

/** Entries grouped by person, with a subtotal and a per-person select-all. */
const groups = computed(() => {
    const map = new Map<
        number,
        { user: Row['user']; rows: Row[]; minutes: number }
    >();
    for (const e of props.entries) {
        const g = map.get(e.user.id) ?? { user: e.user, rows: [], minutes: 0 };
        g.rows.push(e);
        g.minutes += e.minutes;
        map.set(e.user.id, g);
    }

    return [...map.values()].sort((a, b) =>
        a.user.name.localeCompare(b.user.name),
    );
});

function groupIds(rows: Row[]): number[] {
    return rows.filter((r) => !r.is_mine).map((r) => r.id);
}

function groupSelected(rows: Row[]): boolean {
    const ids = groupIds(rows);

    return ids.length > 0 && ids.every((id) => selected.value.includes(id));
}

function toggleGroup(rows: Row[]): void {
    const ids = groupIds(rows);
    selected.value = groupSelected(rows)
        ? selected.value.filter((id) => !ids.includes(id))
        : [...new Set([...selected.value, ...ids])];
}

function visit(query: Record<string, string>): void {
    const base: Record<string, string> = {
        week: props.week.start,
        status: props.filters.status,
    };
    if (props.filters.workspace) {
        base.workspace = String(props.filters.workspace);
    }
    if (props.filters.project) {
        base.project = String(props.filters.project);
    }
    router.get(
        approvals.url({ query: { ...base, ...query } }),
        {},
        { preserveScroll: true },
    );
}

function act(action: 'approve' | 'unapprove'): void {
    router.post(
        TimeController.approve.url(),
        { ids: selected.value, action },
        { preserveScroll: true },
    );
}

const returning = ref(false);
const reason = ref('');

function sendBack(): void {
    router.post(
        TimeController.returnEntries.url(),
        { ids: selected.value, reason: reason.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                returning.value = false;
                reason.value = '';
            },
        },
    );
}
</script>

<template>
    <Head title="Time approvals" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">
                    Time approvals
                </h1>
                <p class="text-sm text-muted-foreground">
                    Time to review. Approved time is locked and can be invoiced.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    aria-label="Previous week"
                    @click="visit({ week: addDays(week.start, -7) })"
                    ><ChevronLeft
                /></Button>
                <span class="text-sm">{{ week.start }} – {{ week.end }}</span>
                <Button
                    variant="outline"
                    size="sm"
                    aria-label="Next week"
                    @click="visit({ week: addDays(week.start, 7) })"
                    ><ChevronRight
                /></Button>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <NativeSelect
                :model-value="filters.status"
                class="w-36"
                aria-label="Status"
                @update:model-value="(v) => visit({ status: String(v) })"
            >
                <option value="pending">Pending</option>
                <option value="approved">Approved</option>
            </NativeSelect>
            <NativeSelect
                :model-value="
                    filters.workspace ? String(filters.workspace) : ''
                "
                class="w-48"
                aria-label="Client"
                @update:model-value="
                    (v) => visit({ workspace: String(v ?? ''), project: '' })
                "
            >
                <option value="">All clients</option>
                <option
                    v-for="w in workspaces"
                    :key="w.id"
                    :value="String(w.id)"
                >
                    {{ w.name }}
                </option>
            </NativeSelect>
            <NativeSelect
                v-if="projects.length"
                :model-value="filters.project ? String(filters.project) : ''"
                class="w-56"
                aria-label="Project"
                @update:model-value="(v) => visit({ project: String(v ?? '') })"
            >
                <option value="">All projects</option>
                <option v-for="p in projects" :key="p.id" :value="String(p.id)">
                    {{ p.name }}
                </option>
            </NativeSelect>
            <span class="text-sm text-muted-foreground"
                >{{ formatMinutes(total) }} shown</span
            >
            <div class="ml-auto flex gap-2">
                <template v-if="filters.status === 'pending'">
                    <Button
                        variant="outline"
                        :disabled="selected.length === 0"
                        @click="returning = !returning"
                        >Return {{ selected.length || '' }}</Button
                    >
                    <Button
                        :disabled="selected.length === 0"
                        @click="act('approve')"
                        >Approve {{ selected.length || '' }}</Button
                    >
                </template>
                <Button
                    v-else
                    variant="outline"
                    :disabled="selected.length === 0"
                    @click="act('unapprove')"
                >
                    Un-approve {{ selected.length || '' }}
                </Button>
            </div>
        </div>

        <form
            v-if="returning && selected.length"
            class="flex flex-wrap items-center gap-2 rounded-lg border border-rose-300 bg-rose-50/60 p-3 text-sm dark:bg-rose-950/20"
            @submit.prevent="sendBack"
        >
            <span class="text-rose-800 dark:text-rose-200"
                >Return {{ selected.length }} entr{{
                    selected.length === 1 ? 'y' : 'ies'
                }}
                with a reason:</span
            >
            <input
                v-model="reason"
                v-focus
                class="h-8 min-w-64 flex-1 rounded-md border bg-background px-2"
                placeholder="e.g. Tuesday: please split the 4h between the two tasks"
                aria-label="Reason"
            />
            <Button size="sm" type="submit" :disabled="reason.trim().length < 3"
                >Send back</Button
            >
            <Button
                size="sm"
                type="button"
                variant="ghost"
                @click="returning = false"
                >Cancel</Button
            >
        </form>

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-left text-sm">
                <thead
                    class="bg-muted/50 text-xs text-muted-foreground uppercase"
                >
                    <tr>
                        <th class="w-8 px-3 py-2">
                            <input
                                type="checkbox"
                                class="size-4"
                                :checked="allSelected"
                                aria-label="Select all"
                                @change="toggleAll"
                            />
                        </th>
                        <th class="px-3 py-2 font-medium">Date</th>
                        <th class="px-3 py-2 font-medium">Department</th>
                        <th class="px-3 py-2 font-medium">Task</th>
                        <th class="px-3 py-2 text-right font-medium">Time</th>
                    </tr>
                </thead>
                <tbody v-if="entries.length === 0">
                    <tr>
                        <td
                            colspan="5"
                            class="px-3 py-8 text-center text-muted-foreground"
                        >
                            Nothing to review for this week.
                        </td>
                    </tr>
                </tbody>
                <tbody
                    v-for="g in groups"
                    :key="g.user.id"
                    class="divide-y border-t"
                >
                    <tr class="bg-muted/30">
                        <td class="px-3 py-2">
                            <input
                                v-if="groupIds(g.rows).length"
                                type="checkbox"
                                class="size-4"
                                :checked="groupSelected(g.rows)"
                                :aria-label="`Select all of ${g.user.name}'s entries`"
                                @change="toggleGroup(g.rows)"
                            />
                        </td>
                        <td colspan="3" class="px-3 py-2 font-medium">
                            {{ g.user.name }}
                            <span
                                class="text-xs font-normal text-muted-foreground"
                                >· {{ g.rows.length }} entries</span
                            >
                        </td>
                        <td
                            class="px-3 py-2 text-right font-semibold tabular-nums"
                        >
                            {{ formatMinutes(g.minutes) }}
                        </td>
                    </tr>
                    <tr v-for="e in g.rows" :key="e.id">
                        <td class="px-3 py-2">
                            <input
                                v-model="selected"
                                type="checkbox"
                                class="size-4"
                                :value="e.id"
                                :disabled="e.is_mine"
                                :title="
                                    e.is_mine
                                        ? 'You cannot approve your own time'
                                        : ''
                                "
                                :aria-label="`Select entry ${e.id}`"
                            />
                        </td>
                        <td class="px-3 py-2 whitespace-nowrap">
                            {{ formatDate(e.entry_date) }}
                        </td>
                        <td class="px-3 py-2 text-xs text-muted-foreground">
                            {{ e.department ?? '—'
                            }}<template v-if="e.is_mine">
                                · your entry</template
                            >
                            <span
                                v-if="e.submitted"
                                class="ml-1 rounded bg-blue-100 px-1 text-[11px] text-blue-800 dark:bg-blue-950 dark:text-blue-200"
                                >submitted</span
                            >
                            <span
                                v-else-if="e.returned"
                                class="ml-1 rounded bg-rose-100 px-1 text-[11px] text-rose-800 dark:bg-rose-950 dark:text-rose-200"
                                :title="e.return_reason ?? ''"
                                >returned</span
                            >
                            <span
                                v-else-if="!e.approved"
                                class="ml-1 rounded bg-muted px-1 text-[11px] text-muted-foreground"
                                >not submitted</span
                            >
                        </td>
                        <td class="px-3 py-2">
                            <Link
                                v-if="e.task"
                                :href="
                                    show({
                                        workspace: e.workspace.slug,
                                        task: e.task.id,
                                    })
                                "
                                class="hover:underline"
                                >{{ e.task.title }}</Link
                            >
                            <span class="block text-xs text-muted-foreground">
                                {{ e.workspace.name
                                }}<template v-if="e.note">
                                    · {{ e.note }}</template
                                ><template v-if="!e.is_billable">
                                    · non-billable</template
                                ><template v-if="e.approver">
                                    · approved by {{ e.approver }}</template
                                >
                            </span>
                        </td>
                        <td
                            class="px-3 py-2 text-right font-medium tabular-nums"
                        >
                            {{ formatMinutes(e.minutes) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
