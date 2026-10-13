<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Download } from '@lucide/vue';
import { computed, reactive, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatMinutes } from '@/lib/format';
import { formatMoney } from '@/lib/money';
import { people as peopleReport } from '@/routes/reports';
import { csv } from '@/routes/reports/people';

/**
 * People report (B-16): per person per month - capacity, logged, billable %, utilisation,
 * cost vs value at client rates. CSV for payroll / finance.
 */
type Row = {
    id: number;
    name: string;
    title: string | null;
    department: string | null;
    active: boolean;
    capacity: number;
    leave_days: number;
    logged: number;
    billable: number;
    billable_pct: number;
    utilisation_pct: number;
    logged_pct: number;
    cost_minor: number;
    value_minor: number;
    margin_minor: number;
    missing_cost: boolean;
};

const props = defineProps<{
    month: string;
    report: {
        from: string;
        to: string;
        rows: Row[];
        totals: {
            capacity: number;
            logged: number;
            billable: number;
            cost_minor: number;
            value_minor: number;
        };
    };
    departments: { id: number; name: string }[];
    filters: { department?: number | string };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'People report', href: peopleReport() }] },
});

const form = reactive({
    month: props.month,
    department: props.filters.department
        ? String(props.filters.department)
        : '',
});
const query = computed(() =>
    Object.fromEntries(Object.entries(form).filter(([, v]) => v !== '')),
);
watch(form, () =>
    router.get(
        peopleReport.url({ query: query.value }),
        {},
        { preserveScroll: true, preserveState: true, replace: true },
    ),
);

const t = computed(() => props.report.totals);
const pct = (a: number, b: number) => (b > 0 ? Math.round((a / b) * 100) : 0);
const utilTone = (p: number) =>
    p >= 75
        ? 'text-emerald-700 dark:text-emerald-300'
        : p >= 50
          ? 'text-amber-700 dark:text-amber-300'
          : 'text-rose-700 dark:text-rose-300';
</script>

<template>
    <Head title="People report" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <Heading
            title="People report"
            description="Utilisation = billable hours ÷ available hours (8h weekdays minus holidays and approved leave). Value uses current client rates; cost uses internal cost rates."
        />

        <div class="flex flex-wrap items-center gap-2">
            <Input
                v-model="form.month"
                type="month"
                class="h-9 w-44"
                aria-label="Month"
            />
            <NativeSelect
                v-model="form.department"
                class="h-9 w-44"
                aria-label="Department"
            >
                <option value="">All departments</option>
                <option
                    v-for="d in departments"
                    :key="d.id"
                    :value="String(d.id)"
                >
                    {{ d.name }}
                </option>
            </NativeSelect>
            <Button
                as="a"
                :href="csv.url({ query })"
                variant="outline"
                size="sm"
                class="ml-auto"
                ><Download /> CSV</Button
            >
        </div>

        <div class="grid gap-3 sm:grid-cols-4">
            <div class="rounded-lg border p-3">
                <div class="text-xs text-muted-foreground">Logged</div>
                <div class="text-xl font-semibold">
                    {{ formatMinutes(t.logged) }}
                </div>
                <div class="text-xs text-muted-foreground">
                    of {{ formatMinutes(t.capacity) }} available
                </div>
            </div>
            <div class="rounded-lg border p-3">
                <div class="text-xs text-muted-foreground">Utilisation</div>
                <div
                    class="text-xl font-semibold"
                    :class="utilTone(pct(t.billable, t.capacity))"
                >
                    {{ pct(t.billable, t.capacity) }}%
                </div>
                <div class="text-xs text-muted-foreground">
                    {{ formatMinutes(t.billable) }} billable
                </div>
            </div>
            <div class="rounded-lg border p-3">
                <div class="text-xs text-muted-foreground">Value at rates</div>
                <div class="text-xl font-semibold">
                    {{ formatMoney(t.value_minor) }}
                </div>
            </div>
            <div class="rounded-lg border p-3">
                <div class="text-xs text-muted-foreground">Margin</div>
                <div class="text-xl font-semibold">
                    {{ formatMoney(t.value_minor - t.cost_minor) }}
                </div>
                <div class="text-xs text-muted-foreground">
                    cost {{ formatMoney(t.cost_minor) }}
                </div>
            </div>
        </div>

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-sm">
                <thead
                    class="bg-muted/50 text-left text-xs text-muted-foreground"
                >
                    <tr>
                        <th class="px-3 py-2 font-medium">Person</th>
                        <th class="px-3 py-2 text-right font-medium">
                            Available
                        </th>
                        <th class="px-3 py-2 text-right font-medium">Logged</th>
                        <th class="px-3 py-2 text-right font-medium">
                            Billable
                        </th>
                        <th class="px-3 py-2 text-right font-medium">
                            Billable %
                        </th>
                        <th class="px-3 py-2 text-right font-medium">
                            Utilisation
                        </th>
                        <th class="px-3 py-2 text-right font-medium">Cost</th>
                        <th class="px-3 py-2 text-right font-medium">Value</th>
                        <th class="px-3 py-2 text-right font-medium">Margin</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in report.rows" :key="r.id" class="border-t">
                        <td class="px-3 py-2">
                            <div class="font-medium">
                                {{ r.name
                                }}<span
                                    v-if="!r.active"
                                    class="ml-1 text-xs text-muted-foreground"
                                    >(inactive)</span
                                >
                            </div>
                            <div class="text-xs text-muted-foreground">
                                {{ r.department
                                }}<template v-if="r.leave_days">
                                    · {{ r.leave_days }}d leave</template
                                >
                            </div>
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">
                            {{ formatMinutes(r.capacity) }}
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">
                            {{ formatMinutes(r.logged) }}
                            <span class="text-xs text-muted-foreground"
                                >({{ r.logged_pct }}%)</span
                            >
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">
                            {{ formatMinutes(r.billable) }}
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">
                            {{ r.billable_pct }}%
                        </td>
                        <td
                            class="px-3 py-2 text-right font-medium tabular-nums"
                            :class="utilTone(r.utilisation_pct)"
                        >
                            {{ r.utilisation_pct }}%
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">
                            {{ formatMoney(r.cost_minor)
                            }}<span
                                v-if="r.missing_cost"
                                class="text-amber-600"
                                title="Some time has no internal cost rate"
                            >
                                *</span
                            >
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">
                            {{ formatMoney(r.value_minor) }}
                        </td>
                        <td
                            class="px-3 py-2 text-right tabular-nums"
                            :class="r.margin_minor < 0 ? 'text-rose-600' : ''"
                        >
                            {{ formatMoney(r.margin_minor) }}
                        </td>
                    </tr>
                    <tr v-if="report.rows.length === 0">
                        <td
                            colspan="9"
                            class="px-3 py-8 text-center text-muted-foreground"
                        >
                            No people in this filter.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="text-xs text-muted-foreground">
            * Some of this person's time has no internal cost rate, so cost is
            understated. Add cost rates in Billing → Rates.
        </p>
    </div>
</template>
