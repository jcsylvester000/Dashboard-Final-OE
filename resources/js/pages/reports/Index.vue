<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Download } from '@lucide/vue';
import { computed, reactive } from 'vue';
import Heading from '@/components/Heading.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Card, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { csv, index } from '@/routes/reports';
import type { ReportColumn, ReportFilters, ReportResult } from '@/types/reports';

const props = defineProps<{
    report: string;
    group: string;
    filters: ReportFilters;
    result: ReportResult;
    reports: Record<string, string>;
    groups: Record<string, string>;
    workspaces: { id: number; name: string }[];
    departments: { id: number; name: string }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Reports', href: index() }],
    },
});

const form = reactive({
    workspace: props.filters.workspace ? String(props.filters.workspace) : '',
    department: props.filters.department ? String(props.filters.department) : '',
    from: props.filters.from,
    to: props.filters.to,
});

function query(overrides: Record<string, string> = {}): Record<string, string> {
    const q: Record<string, string> = {
        report: props.report,
        from: form.from,
        to: form.to,
        ...overrides,
    };
    if (form.workspace) {
        q.workspace = form.workspace;
    }
    if (form.department) {
        q.department = form.department;
    }
    if ((overrides.report ?? props.report) === 'time') {
        q.group = overrides.group ?? props.group;
    }

    return q;
}

function apply(overrides: Record<string, string> = {}): void {
    router.get(index.url({ query: query(overrides) }), {}, { preserveState: true, preserveScroll: true });
}

const csvUrl = computed(() => csv.url({ query: query() }));

// Simple bar for the first numeric column, so trends are visible at a glance.
const barKey = computed(() => props.result.columns.find((c) => c.type !== 'text')?.key ?? null);
const barMax = computed(() => {
    const key = barKey.value;
    if (!key) {
        return 0;
    }

    return Math.max(0, ...props.result.rows.map((r) => Number(r[key] ?? 0)));
});

function format(value: string | number | null | undefined, col: ReportColumn): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }
    switch (col.type) {
        case 'hours':
            return `${Number(value).toFixed(2)} h`;
        case 'days':
            return `${Number(value).toFixed(1)} d`;
        case 'percent':
            return `${Number(value).toFixed(1)}%`;
        default:
            return String(value);
    }
}

function barWidth(row: Record<string, string | number | null>): string {
    const key = barKey.value;
    if (!key || barMax.value <= 0) {
        return '0%';
    }

    return `${Math.round((Number(row[key] ?? 0) / barMax.value) * 100)}%`;
}
</script>

<template>
    <Head title="Reports" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading title="Reports" description="Filter by client, department and dates. Export any report to CSV (opens in Excel)." />
            <Button variant="outline" size="sm" as-child>
                <a :href="csvUrl"><Download class="size-4" /> Export CSV</a>
            </Button>
        </div>

        <nav class="flex flex-wrap gap-1 border-b text-sm" aria-label="Reports">
            <Link
                v-for="(label, key) in reports"
                :key="key"
                :href="index({ query: query({ report: String(key) }) })"
                class="-mb-px border-b-2 px-3 py-2"
                :class="key === report ? 'border-foreground font-medium' : 'border-transparent text-muted-foreground hover:text-foreground'"
                preserve-scroll
                >{{ label }}</Link
            >
        </nav>

        <form class="flex flex-wrap items-end gap-2 text-sm" @submit.prevent="apply()">
            <label class="grid gap-1">
                <span class="text-xs text-muted-foreground">Client</span>
                <NativeSelect v-model="form.workspace" class="w-48">
                    <option value="">All my clients</option>
                    <option v-for="w in workspaces" :key="w.id" :value="String(w.id)">{{ w.name }}</option>
                </NativeSelect>
            </label>
            <label class="grid gap-1">
                <span class="text-xs text-muted-foreground">Department</span>
                <NativeSelect v-model="form.department" class="w-40">
                    <option value="">All departments</option>
                    <option v-for="d in departments" :key="d.id" :value="String(d.id)">{{ d.name }}</option>
                </NativeSelect>
            </label>
            <label class="grid gap-1">
                <span class="text-xs text-muted-foreground">From</span>
                <Input v-model="form.from" type="date" class="w-40" />
            </label>
            <label class="grid gap-1">
                <span class="text-xs text-muted-foreground">To</span>
                <Input v-model="form.to" type="date" class="w-40" />
            </label>
            <label v-if="report === 'time'" class="grid gap-1">
                <span class="text-xs text-muted-foreground">Group by</span>
                <NativeSelect :model-value="group" class="w-36" @update:model-value="(v) => apply({ group: String(v) })">
                    <option v-for="(label, key) in groups" :key="key" :value="key">{{ label }}</option>
                </NativeSelect>
            </label>
            <Button type="submit" size="sm">Apply</Button>
        </form>

        <p v-if="report === 'overdue' || report === 'workload'" class="text-xs text-muted-foreground">
            Open and overdue counts are as of today; the date range applies to hours logged.
        </p>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <Card v-for="(value, label) in result.summary" :key="label">
                <CardHeader>
                    <CardDescription>{{ label }}</CardDescription>
                    <CardTitle class="text-2xl">{{ value }}</CardTitle>
                </CardHeader>
            </Card>
        </div>

        <p v-if="result.rows.length === 0" class="py-10 text-center text-sm text-muted-foreground">No data for these filters.</p>

        <div v-else class="overflow-x-auto rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-xs text-muted-foreground">
                    <tr>
                        <th
                            v-for="col in result.columns"
                            :key="col.key"
                            class="px-3 py-2 font-medium"
                            :class="{ 'text-right': col.type !== 'text' }"
                        >
                            {{ col.label }}
                        </th>
                        <th class="w-40 px-3 py-2" aria-hidden="true" />
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="(row, i) in result.rows" :key="i">
                        <td
                            v-for="col in result.columns"
                            :key="col.key"
                            class="px-3 py-2"
                            :class="{ 'text-right tabular-nums': col.type !== 'text' }"
                        >
                            {{ format(row[col.key], col) }}
                        </td>
                        <td class="px-3 py-2" aria-hidden="true">
                            <div class="h-2 rounded bg-muted">
                                <div class="h-2 rounded bg-primary/70" :style="{ width: barWidth(row) }" />
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
