<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, Lock, Send, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { confirmDialog } from '@/composables/useConfirm';
import { addDays, formatMinutes, localToday } from '@/lib/format';
import {
    destroy as destroyEntry,
    submit as submitWeek,
    timesheet,
    update as updateEntry,
} from '@/routes/time';
import { show } from '@/routes/workspaces/tasks';
import { store as storeTime } from '@/routes/workspaces/tasks/time';
import type { TimeRow } from '@/types/time';

/**
 * My week: a grid per task, add time from here, fix returned entries, and submit
 * the week for approval (HRIS-style weekly timesheet).
 */
const props = defineProps<{
    week: { start: string; end: string };
    entries: TimeRow[];
    capacityMinutes: number;
    holidays?: { date: string; name: string; kind: string }[];
    status: 'empty' | 'draft' | 'submitted' | 'returned' | 'approved';
    tasks: {
        id: number;
        title: string;
        workspace: { name: string; slug: string };
    }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'My timesheet', href: timesheet() }],
    },
});

const today = localToday();
const days = computed(() =>
    Array.from({ length: 7 }, (_, i) => addDays(props.week.start, i)),
);

type Row = {
    key: string;
    task: TimeRow['task'];
    workspace: TimeRow['workspace'];
    perDay: Record<string, number>;
    total: number;
    locked: boolean;
};

const rows = computed<Row[]>(() => {
    const map = new Map<string, Row>();
    for (const e of props.entries) {
        const key = `${e.workspace.id}-${e.task?.id ?? 'none'}`;
        const row = map.get(key) ?? {
            key,
            task: e.task,
            workspace: e.workspace,
            perDay: {},
            total: 0,
            locked: true,
        };
        row.perDay[e.entry_date] = (row.perDay[e.entry_date] ?? 0) + e.minutes;
        row.total += e.minutes;
        row.locked = row.locked && e.locked;
        map.set(key, row);
    }

    return [...map.values()].sort((a, b) => b.total - a.total);
});

const dayTotals = computed(() =>
    Object.fromEntries(
        days.value.map((d) => [
            d,
            props.entries
                .filter((e) => e.entry_date === d)
                .reduce((s, e) => s + e.minutes, 0),
        ]),
    ),
);
const weekTotal = computed(() =>
    props.entries.reduce((s, e) => s + e.minutes, 0),
);
const billable = computed(() =>
    props.entries
        .filter((e) => e.is_billable)
        .reduce((s, e) => s + e.minutes, 0),
);
const approved = computed(() =>
    props.entries.filter((e) => e.approved).reduce((s, e) => s + e.minutes, 0),
);
const capacityPct = computed(() =>
    Math.min(
        100,
        Math.round(
            (weekTotal.value / Math.max(1, props.capacityMinutes)) * 100,
        ),
    ),
);
const returned = computed(() => props.entries.filter((e) => e.returned));
const canSubmit = computed(() =>
    props.entries.some((e) => !e.approved && !e.submitted && !e.running),
);

const statusLabel: Record<string, { text: string; cls: string }> = {
    empty: { text: 'Nothing logged', cls: 'bg-muted text-muted-foreground' },
    draft: {
        text: 'Draft - not submitted',
        cls: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
    },
    submitted: {
        text: 'Submitted - waiting for approval',
        cls: 'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-200',
    },
    returned: {
        text: 'Returned - needs changes',
        cls: 'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-200',
    },
    approved: {
        text: 'Approved',
        cls: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200',
    },
};

function go(offset: number): void {
    router.get(
        timesheet.url({ query: { week: addDays(props.week.start, offset) } }),
        {},
        { preserveScroll: true },
    );
}

function dayLabel(d: string): string {
    const [y, m, day] = d.split('-').map(Number);

    return new Date(y, m - 1, day).toLocaleDateString(undefined, {
        weekday: 'short',
        day: 'numeric',
    });
}

async function submit(): Promise<void> {
    if (
        await confirmDialog(
            'Submit this week for approval? Submitted entries can only be changed if your lead returns them.',
        )
    ) {
        router.post(
            submitWeek.url(),
            { week: props.week.start },
            { preserveScroll: true },
        );
    }
}

// Add time from the grid.
const add = useForm({
    task: '',
    entry_date: days.value.includes(today) ? today : props.week.start,
    hours: '',
    minutes: '',
    note: '',
});
const addError = ref<string | null>(null);

function addTime(): void {
    const task = props.tasks.find((t) => String(t.id) === add.task);
    if (!task) {
        addError.value = 'Choose a task.';

        return;
    }
    addError.value = null;
    add.transform((d) => ({
        entry_date: d.entry_date,
        hours: d.hours || 0,
        minutes: d.minutes || 0,
        note: d.note,
        is_billable: true,
    })).post(storeTime.url({ workspace: task.workspace.slug, task: task.id }), {
        preserveScroll: true,
        onSuccess: () => add.reset('hours', 'minutes', 'note'),
    });
}

// Fix a returned (or draft) entry.
const editing = ref<number | null>(null);
const editMinutes = ref('');

function startEdit(e: TimeRow): void {
    editing.value = e.id;
    editMinutes.value = String(e.minutes);
}

function saveEdit(e: TimeRow): void {
    router.put(
        updateEntry.url(e.id),
        { minutes: Number(editMinutes.value) },
        { preserveScroll: true, onSuccess: () => (editing.value = null) },
    );
}

async function remove(e: TimeRow): Promise<void> {
    if (await confirmDialog('Delete this entry?')) {
        router.delete(destroyEntry.url(e.id), { preserveScroll: true });
    }
}
</script>

<template>
    <Head title="My timesheet" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="space-y-1">
                <h1
                    class="flex flex-wrap items-center gap-2 text-xl font-semibold tracking-tight"
                >
                    My timesheet
                    <span
                        class="rounded px-2 py-0.5 text-xs font-medium"
                        :class="statusLabel[status].cls"
                        >{{ statusLabel[status].text }}</span
                    >
                </h1>
                <p class="text-sm text-muted-foreground">
                    {{ formatMinutes(weekTotal) }} of
                    {{ formatMinutes(capacityMinutes) }} ·
                    {{ formatMinutes(billable) }} billable ·
                    {{ formatMinutes(approved) }} approved
                </p>
                <div
                    class="h-1.5 w-64 overflow-hidden rounded-full bg-muted"
                    role="progressbar"
                    :aria-valuenow="capacityPct"
                    aria-valuemin="0"
                    aria-valuemax="100"
                >
                    <div
                        class="h-full rounded-full"
                        :class="
                            capacityPct >= 100 ? 'bg-emerald-500' : 'bg-primary'
                        "
                        :style="{ width: `${capacityPct}%` }"
                    />
                </div>
            </div>
            <div class="flex items-center gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    aria-label="Previous week"
                    @click="go(-7)"
                    ><ChevronLeft
                /></Button>
                <span class="text-sm">{{ week.start }} – {{ week.end }}</span>
                <Button
                    variant="outline"
                    size="sm"
                    aria-label="Next week"
                    @click="go(7)"
                    ><ChevronRight
                /></Button>
                <Button size="sm" :disabled="!canSubmit" @click="submit"
                    ><Send class="size-4" /> Submit week</Button
                >
            </div>
        </div>

        <!-- Returned entries: what to fix -->
        <div
            v-if="returned.length"
            class="space-y-2 rounded-lg border border-rose-300 bg-rose-50/60 p-3 text-sm dark:bg-rose-950/20"
        >
            <p class="font-medium text-rose-800 dark:text-rose-200">
                {{ returned.length }} entr{{
                    returned.length === 1 ? 'y was' : 'ies were'
                }}
                returned. {{ returned.length === 1 ? 'Fix it' : 'Fix them' }},
                then submit the week again.
            </p>
            <ul class="space-y-1.5">
                <li
                    v-for="e in returned"
                    :key="e.id"
                    class="flex flex-wrap items-center gap-2"
                >
                    <span class="w-24 text-xs text-muted-foreground">{{
                        dayLabel(e.entry_date)
                    }}</span>
                    <span class="min-w-0 flex-1 truncate"
                        >{{ e.task?.title ?? 'No task' }} ·
                        <em class="text-rose-700"
                            >"{{ e.return_reason }}"</em
                        ></span
                    >
                    <template v-if="editing === e.id">
                        <Input
                            v-model="editMinutes"
                            type="number"
                            min="1"
                            class="h-8 w-24"
                            aria-label="Minutes"
                        />
                        <Button size="sm" class="h-8" @click="saveEdit(e)"
                            >Save</Button
                        >
                    </template>
                    <template v-else>
                        <span class="tabular-nums">{{
                            formatMinutes(e.minutes)
                        }}</span>
                        <Button
                            size="sm"
                            variant="outline"
                            class="h-8"
                            @click="startEdit(e)"
                            >Edit minutes</Button
                        >
                        <Button
                            size="sm"
                            variant="ghost"
                            class="h-8"
                            aria-label="Delete entry"
                            @click="remove(e)"
                            ><Trash2 class="size-4"
                        /></Button>
                    </template>
                </li>
            </ul>
        </div>

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-xs text-muted-foreground">
                    <tr>
                        <th class="px-3 py-2 text-left font-medium">Task</th>
                        <th
                            v-for="d in days"
                            :key="d"
                            class="px-2 py-2 text-right font-medium whitespace-nowrap"
                            :class="
                                d === today
                                    ? 'bg-primary/10 text-foreground'
                                    : ''
                            "
                        >
                            {{ dayLabel(d) }}
                            <span
                                v-for="h in (holidays ?? []).filter(
                                    (x) => x.date === d,
                                )"
                                :key="h.date"
                                class="block text-[10px] font-normal"
                                :class="
                                    h.kind === 'special_working'
                                        ? 'text-muted-foreground'
                                        : 'text-rose-600'
                                "
                                :title="h.name"
                                >{{
                                    h.kind === 'special_working'
                                        ? 'Working day'
                                        : 'Holiday'
                                }}</span
                            >
                        </th>
                        <th class="px-3 py-2 text-right font-medium">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-if="rows.length === 0">
                        <td
                            :colspan="days.length + 2"
                            class="px-3 py-8 text-center text-muted-foreground"
                        >
                            No time logged this week. Add time below, start a
                            timer, or use "Log time" on a task.
                        </td>
                    </tr>
                    <tr v-for="r in rows" :key="r.key">
                        <td class="px-3 py-2">
                            <Link
                                v-if="r.task"
                                :href="
                                    show({
                                        workspace: r.workspace.slug,
                                        task: r.task.id,
                                    })
                                "
                                class="font-medium hover:underline"
                                >{{ r.task.title }}</Link
                            >
                            <span v-else class="text-muted-foreground"
                                >No task</span
                            >
                            <span class="block text-xs text-muted-foreground">
                                {{ r.workspace.name }}
                                <Lock
                                    v-if="r.locked"
                                    class="inline size-3"
                                    aria-label="Approved"
                                />
                            </span>
                        </td>
                        <td
                            v-for="d in days"
                            :key="d"
                            class="px-2 py-2 text-right tabular-nums"
                            :class="d === today ? 'bg-primary/5' : ''"
                        >
                            {{ r.perDay[d] ? formatMinutes(r.perDay[d]) : '' }}
                        </td>
                        <td
                            class="px-3 py-2 text-right font-medium tabular-nums"
                        >
                            {{ formatMinutes(r.total) }}
                        </td>
                    </tr>
                </tbody>
                <tfoot v-if="rows.length" class="border-t bg-muted/30 text-xs">
                    <tr>
                        <td class="px-3 py-2 font-medium">Day total</td>
                        <td
                            v-for="d in days"
                            :key="d"
                            class="px-2 py-2 text-right tabular-nums"
                            :class="
                                dayTotals[d] > 8 * 60
                                    ? 'font-semibold text-amber-700'
                                    : ''
                            "
                        >
                            {{ formatMinutes(dayTotals[d]) }}
                        </td>
                        <td
                            class="px-3 py-2 text-right font-semibold tabular-nums"
                        >
                            {{ formatMinutes(weekTotal) }}
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- Add time from the timesheet -->
        <form
            class="flex flex-wrap items-end gap-2 rounded-lg border p-3 text-sm"
            @submit.prevent="addTime"
        >
            <label class="grid min-w-64 flex-1 gap-1">
                <span class="text-xs text-muted-foreground">Task</span>
                <NativeSelect v-model="add.task" aria-label="Task">
                    <option value="" disabled>Choose a task…</option>
                    <option
                        v-for="t in tasks"
                        :key="t.id"
                        :value="String(t.id)"
                    >
                        {{ t.title }} · {{ t.workspace.name }}
                    </option>
                </NativeSelect>
            </label>
            <label class="grid gap-1">
                <span class="text-xs text-muted-foreground">Day</span>
                <NativeSelect
                    v-model="add.entry_date"
                    class="w-36"
                    aria-label="Day"
                >
                    <option
                        v-for="d in days"
                        :key="d"
                        :value="d"
                        :disabled="d > today"
                    >
                        {{ dayLabel(d) }}
                    </option>
                </NativeSelect>
            </label>
            <label class="grid gap-1">
                <span class="text-xs text-muted-foreground">Hours</span>
                <Input
                    v-model="add.hours"
                    type="number"
                    min="0"
                    max="12"
                    class="w-20"
                />
            </label>
            <label class="grid gap-1">
                <span class="text-xs text-muted-foreground">Minutes</span>
                <Input
                    v-model="add.minutes"
                    type="number"
                    min="0"
                    max="59"
                    class="w-20"
                />
            </label>
            <label class="grid min-w-48 flex-1 gap-1">
                <span class="text-xs text-muted-foreground">Note</span>
                <Input v-model="add.note" placeholder="What did you do?" />
            </label>
            <Button type="submit" :disabled="add.processing">Add time</Button>
            <InputError
                class="w-full"
                :message="
                    addError ??
                    add.errors.minutes ??
                    add.errors.entry_date ??
                    (add.errors as Record<string, string>).entry
                "
            />
        </form>

        <p class="text-xs text-muted-foreground">
            Submitted and approved entries are locked. If something is wrong
            after you submit, ask your lead to return it.
        </p>
    </div>
</template>
