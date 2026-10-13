<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { reactive, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import {
    addDays,
    departmentDot,
    formatDate,
    formatMinutes,
} from '@/lib/format';
import { workload } from '@/routes';

/**
 * Workload (B-05): planned hours (task estimates spread from start to due) and logged hours
 * per person per week against capacity (8h weekdays minus holidays and approved leave).
 */
type Cell = {
    week: string;
    capacity: number;
    planned: number;
    logged: number;
    leave_days: number;
    load: number;
    remaining_only?: boolean;
    tasks: { key: string; title: string; minutes: number }[];
};

const props = defineProps<{
    range: { from: string; to: string; weeks: number; today: string };
    weekStarts: string[];
    holidays: { date: string; name: string; kind: string }[];
    people: {
        id: number;
        name: string;
        title: string | null;
        department: { id: number; name: string; color: string } | null;
        weeks: Cell[];
    }[];
    departments: { id: number; name: string }[];
    filters: { department?: number | string };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Workload', href: workload() }] },
});

const form = reactive({
    department: props.filters.department
        ? String(props.filters.department)
        : '',
    weeks: String(props.range.weeks),
});
function visit(from: string): void {
    const query = Object.fromEntries(
        Object.entries({ ...form, from }).filter(([, v]) => v !== ''),
    );
    router.get(
        workload.url({ query }),
        {},
        { preserveScroll: true, preserveState: true, replace: true },
    );
}
watch(form, () => visit(props.range.from));

const tone = (c: Cell) =>
    c.capacity === 0 && c.planned === 0
        ? 'bg-muted text-muted-foreground'
        : c.load > 100
          ? 'bg-rose-100 text-rose-900 dark:bg-rose-950 dark:text-rose-100'
          : c.load >= 80
            ? 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-100'
            : 'bg-emerald-50 text-emerald-900 dark:bg-emerald-950/50 dark:text-emerald-100';
const holidaysIn = (wk: string) =>
    props.holidays.filter(
        (h) =>
            h.date >= wk &&
            h.date <= addDays(wk, 6) &&
            h.kind !== 'special_working',
    );
const tip = (c: Cell) =>
    [
        `Planned ${formatMinutes(c.planned)} of ${formatMinutes(c.capacity)} available${c.remaining_only ? ' for the rest of this week' : ''}`,
        c.leave_days ? `${c.leave_days} day(s) leave` : '',
        ...c.tasks.map(
            (t) => `${t.key} ${t.title}: ${formatMinutes(t.minutes)}`,
        ),
    ]
        .filter(Boolean)
        .join('\n');
</script>

<template>
    <Head title="Workload" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <Heading
            title="Workload"
            description="Planned work (task estimates spread from start to due) against each person's available hours. Leave and holidays reduce capacity."
        />

        <div class="flex flex-wrap items-center gap-2">
            <Button
                variant="outline"
                size="sm"
                aria-label="Earlier"
                @click="visit(addDays(range.from, -7))"
                ><ChevronLeft
            /></Button>
            <span class="min-w-52 text-center text-sm font-medium"
                >{{ formatDate(range.from) }} – {{ formatDate(range.to) }}</span
            >
            <Button
                variant="outline"
                size="sm"
                aria-label="Later"
                @click="visit(addDays(range.from, 7))"
                ><ChevronRight
            /></Button>
            <Button variant="ghost" size="sm" @click="visit(range.today)"
                >This week</Button
            >
            <div class="ml-auto flex gap-2">
                <NativeSelect
                    v-model="form.weeks"
                    class="h-8 w-28 text-xs"
                    aria-label="Weeks"
                >
                    <option value="2">2 weeks</option>
                    <option value="4">4 weeks</option>
                    <option value="6">6 weeks</option>
                    <option value="8">8 weeks</option>
                </NativeSelect>
                <NativeSelect
                    v-if="departments.length > 1"
                    v-model="form.department"
                    class="h-8 w-40 text-xs"
                    aria-label="Department"
                >
                    <option value="">All my departments</option>
                    <option
                        v-for="d in departments"
                        :key="d.id"
                        :value="String(d.id)"
                    >
                        {{ d.name }}
                    </option>
                </NativeSelect>
            </div>
        </div>

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-xs text-muted-foreground">
                    <tr>
                        <th class="px-3 py-2 text-left font-medium">Person</th>
                        <th
                            v-for="wk in weekStarts"
                            :key="wk"
                            class="px-2 py-2 text-center font-medium"
                        >
                            Week of {{ formatDate(wk) }}
                            <span
                                v-for="h in holidaysIn(wk)"
                                :key="h.date"
                                class="block text-[10px] font-normal text-rose-600"
                                :title="h.name"
                                >{{ formatDate(h.date) }} holiday</span
                            >
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="p in people" :key="p.id" class="border-t">
                        <td class="px-3 py-2">
                            <div class="flex items-center gap-1.5 font-medium">
                                <span
                                    class="size-2 rounded-full"
                                    :class="
                                        departmentDot[
                                            p.department?.color ?? ''
                                        ] ?? 'bg-slate-400'
                                    "
                                />{{ p.name }}
                            </div>
                            <div class="text-xs text-muted-foreground">
                                {{ p.title ?? p.department?.name }}
                            </div>
                        </td>
                        <td
                            v-for="c in p.weeks"
                            :key="c.week"
                            class="px-1.5 py-1.5"
                        >
                            <div
                                class="rounded-md px-2 py-1.5 text-center"
                                :class="tone(c)"
                                :title="tip(c)"
                            >
                                <div class="font-semibold tabular-nums">
                                    {{
                                        c.capacity
                                            ? `${c.load}%`
                                            : c.planned
                                              ? 'Off'
                                              : '—'
                                    }}
                                </div>
                                <div class="text-[11px] tabular-nums">
                                    {{ formatMinutes(c.planned) }} /
                                    {{ formatMinutes(c.capacity)
                                    }}<template v-if="c.remaining_only">
                                        left</template
                                    >
                                </div>
                                <div
                                    v-if="c.logged"
                                    class="text-[10px] opacity-80"
                                >
                                    logged {{ formatMinutes(c.logged) }}
                                </div>
                                <div
                                    v-if="c.leave_days"
                                    class="text-[10px] font-medium"
                                >
                                    {{ c.leave_days }}d leave
                                </div>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="people.length === 0">
                        <td
                            :colspan="weekStarts.length + 1"
                            class="px-3 py-8 text-center text-muted-foreground"
                        >
                            Nobody in your departments yet.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="flex flex-wrap gap-4 text-xs text-muted-foreground">
            <span class="inline-flex items-center gap-1"
                ><span
                    class="h-3 w-5 rounded bg-emerald-50 ring-1 ring-emerald-200"
                />
                Under 80%</span
            >
            <span class="inline-flex items-center gap-1"
                ><span class="h-3 w-5 rounded bg-amber-100" /> 80–100%</span
            >
            <span class="inline-flex items-center gap-1"
                ><span class="h-3 w-5 rounded bg-rose-100" /> Over
                capacity</span
            >
            <span
                >Hover a cell for the tasks behind it. Only tasks with an
                estimate and a due date count; planned work starts from
                today.</span
            >
        </div>
    </div>
</template>
