<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import BillingNav from '@/components/billing/BillingNav.vue';
import Heading from '@/components/Heading.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import Pagination from '@/components/Pagination.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { formatDate, isOverdue } from '@/lib/format';
import { formatMoney, invoiceStatusClass, invoiceStatusLabel } from '@/lib/money';
import { index, show } from '@/routes/billing/invoices';
import type { Paginated } from '@/types/admin';
import type { InvoiceRow } from '@/types/billing';

const props = defineProps<{
    invoices: Paginated<InvoiceRow>;
    filters: { status?: string; workspace?: number | string };
    aging: Record<string, { current: number; '1_30': number; '31_60': number; '61_90': number; '90_plus': number; total: number }>;
    workspaces: { id: number; name: string }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Billing', href: index() }],
    },
});

const buckets = [
    { key: 'current', label: 'Current' },
    { key: '1_30', label: '1–30 days' },
    { key: '31_60', label: '31–60 days' },
    { key: '61_90', label: '61–90 days' },
    { key: '90_plus', label: '90+ days' },
] as const;

function filter(change: Record<string, string>): void {
    const query: Record<string, string> = {};
    const merged = { status: String(props.filters.status ?? ''), workspace: String(props.filters.workspace ?? ''), ...change };
    for (const [k, v] of Object.entries(merged)) {
        if (v) {
            query[k] = v;
        }
    }
    router.get(index.url({ query }), {}, { preserveState: true });
}
</script>

<template>
    <Head title="Invoices" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <Heading title="Billing" description="Invoices are PDFs you send yourself. Nothing here emails clients." />
        <BillingNav />

        <Card v-for="(row, currency) in aging" :key="currency">
            <CardHeader>
                <CardTitle class="text-base">Receivables ({{ currency }})</CardTitle>
                <CardDescription>Open balance by days past due · total {{ formatMoney(row.total, String(currency)) }}</CardDescription>
            </CardHeader>
            <CardContent class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-5">
                <div v-for="b in buckets" :key="b.key">
                    <p class="text-xs text-muted-foreground">{{ b.label }}</p>
                    <p class="font-medium tabular-nums" :class="{ 'text-rose-600': b.key !== 'current' && row[b.key] > 0 }">
                        {{ formatMoney(row[b.key], String(currency)) }}
                    </p>
                </div>
            </CardContent>
        </Card>

        <div class="flex flex-wrap gap-2">
            <NativeSelect :model-value="String(filters.status ?? '')" class="w-44" aria-label="Status" @update:model-value="(v) => filter({ status: String(v ?? '') })">
                <option value="">All statuses</option>
                <option value="open">Open (unpaid)</option>
                <option value="draft">Draft</option>
                <option value="partially_paid">Partially paid</option>
                <option value="paid">Paid</option>
                <option value="void">Void</option>
            </NativeSelect>
            <NativeSelect :model-value="String(filters.workspace ?? '')" class="w-52" aria-label="Client" @update:model-value="(v) => filter({ workspace: String(v ?? '') })">
                <option value="">All clients</option>
                <option v-for="w in workspaces" :key="w.id" :value="String(w.id)">{{ w.name }}</option>
            </NativeSelect>
        </div>

        <p v-if="invoices.data.length === 0" class="py-10 text-center text-sm text-muted-foreground">
            No invoices yet. Open a client under Clients, add a billing period and create its invoice.
        </p>

        <div v-else class="overflow-x-auto rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-xs text-muted-foreground">
                    <tr>
                        <th class="px-3 py-2 font-medium">Number</th>
                        <th class="px-3 py-2 font-medium">Client</th>
                        <th class="px-3 py-2 font-medium">Period</th>
                        <th class="px-3 py-2 font-medium">Status</th>
                        <th class="px-3 py-2 font-medium">Due</th>
                        <th class="px-3 py-2 text-right font-medium">Total</th>
                        <th class="px-3 py-2 text-right font-medium">Balance</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="inv in invoices.data" :key="inv.id">
                        <td class="px-3 py-2">
                            <Link :href="show(inv.id)" class="font-medium hover:underline">{{ inv.number }}</Link>
                            <span v-if="inv.kind === 'credit_note'" class="ml-1 text-xs text-muted-foreground">credit note</span>
                        </td>
                        <td class="px-3 py-2">{{ inv.workspace?.name ?? '—' }}</td>
                        <td class="px-3 py-2 text-xs whitespace-nowrap">{{ formatDate(inv.period_start) }} – {{ formatDate(inv.period_end) }}</td>
                        <td class="px-3 py-2">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="invoiceStatusClass[inv.status]">{{
                                invoiceStatusLabel[inv.status] ?? inv.status
                            }}</span>
                        </td>
                        <td class="px-3 py-2 text-xs whitespace-nowrap" :class="{ 'font-medium text-rose-600': inv.balance_minor > 0 && isOverdue(inv.due_date) }">
                            {{ formatDate(inv.due_date) }}
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ formatMoney(inv.total_minor, inv.currency) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ inv.balance_minor ? formatMoney(inv.balance_minor, inv.currency) : '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :links="invoices.links" :from="invoices.from" :to="invoices.to" :total="invoices.total" />
    </div>
</template>
