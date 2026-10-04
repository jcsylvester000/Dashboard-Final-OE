<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import BillingNav from '@/components/billing/BillingNav.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { formatDate, formatMinutes } from '@/lib/format';
import { formatMoney } from '@/lib/money';
import { index as invoicesIndex } from '@/routes/billing/invoices';
import { index } from '@/routes/billing/reports';

const props = defineProps<{
    filters: { from: string; to: string; month: string };
    revenue: { client: string; currency: string; month: string; net_minor: number; tax_minor: number; total_minor: number; paid_minor: number }[];
    utilisation: { client: string; included_minutes: number; used_minutes: number; utilisation_pct: number }[];
    unbilled: { client: string; currency: string; minutes: number; value_minor: number; unpriced_minutes: number; oldest: string | null }[];
    profitability: {
        client: string;
        project: string;
        currency: string;
        minutes: number;
        revenue_minor: number;
        cost_minor: number;
        margin_minor: number;
        uncosted_minutes: number;
    }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Billing', href: invoicesIndex() }],
    },
});

const form = reactive({ ...props.filters });

function apply(): void {
    router.get(index.url({ query: { ...form } }), {}, { preserveState: true, preserveScroll: true });
}

function marginPct(r: { revenue_minor: number; margin_minor: number }): string {
    return r.revenue_minor > 0 ? `${Math.round((r.margin_minor / r.revenue_minor) * 100)}%` : '—';
}
</script>

<template>
    <Head title="Billing reports" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <Heading title="Billing" description="Numbers come straight from invoices and the time entries behind them." />
        <BillingNav />

        <form class="flex flex-wrap items-end gap-2 text-sm" @submit.prevent="apply">
            <label class="grid gap-1"><span class="text-xs text-muted-foreground">From month</span><Input v-model="form.from" type="month" class="w-40" /></label>
            <label class="grid gap-1"><span class="text-xs text-muted-foreground">To month</span><Input v-model="form.to" type="month" class="w-40" /></label>
            <label class="grid gap-1"><span class="text-xs text-muted-foreground">Retainer month</span><Input v-model="form.month" type="month" class="w-40" /></label>
            <Button size="sm" type="submit">Apply</Button>
        </form>

        <Card>
            <CardHeader>
                <CardTitle class="text-base">Revenue by client and month</CardTitle>
                <CardDescription>Finalized invoices by issue date, credit notes subtracted; void excluded.</CardDescription>
            </CardHeader>
            <CardContent class="overflow-x-auto">
                <p v-if="revenue.length === 0" class="text-sm text-muted-foreground">No invoices issued in this range.</p>
                <table v-else class="w-full text-sm">
                    <thead class="text-left text-xs text-muted-foreground">
                        <tr>
                            <th class="py-1.5 font-medium">Month</th>
                            <th class="py-1.5 font-medium">Client</th>
                            <th class="py-1.5 text-right font-medium">Net</th>
                            <th class="py-1.5 text-right font-medium">Tax</th>
                            <th class="py-1.5 text-right font-medium">Total</th>
                            <th class="py-1.5 text-right font-medium">Paid</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="r in revenue" :key="`${r.month}${r.client}${r.currency}`">
                            <td class="py-1.5">{{ r.month }}</td>
                            <td class="py-1.5">{{ r.client }}</td>
                            <td class="py-1.5 text-right tabular-nums">{{ formatMoney(r.net_minor, r.currency) }}</td>
                            <td class="py-1.5 text-right tabular-nums">{{ formatMoney(r.tax_minor, r.currency) }}</td>
                            <td class="py-1.5 text-right tabular-nums">{{ formatMoney(r.total_minor, r.currency) }}</td>
                            <td class="py-1.5 text-right tabular-nums">{{ formatMoney(r.paid_minor, r.currency) }}</td>
                        </tr>
                    </tbody>
                </table>
            </CardContent>
        </Card>

        <div class="grid gap-4 lg:grid-cols-2">
            <Card>
                <CardHeader>
                    <CardTitle class="text-base">Retainer utilisation ({{ filters.month }})</CardTitle>
                    <CardDescription>Approved billable time against the hours each retainer includes.</CardDescription>
                </CardHeader>
                <CardContent>
                    <p v-if="utilisation.length === 0" class="text-sm text-muted-foreground">No retainers with included hours.</p>
                    <ul v-else class="space-y-2 text-sm">
                        <li v-for="u in utilisation" :key="u.client">
                            <div class="flex justify-between">
                                <span>{{ u.client }}</span>
                                <span class="tabular-nums" :class="{ 'font-medium text-rose-600': u.utilisation_pct > 100 }">
                                    {{ formatMinutes(u.used_minutes) }} / {{ formatMinutes(u.included_minutes) }} · {{ u.utilisation_pct }}%
                                </span>
                            </div>
                            <div class="mt-1 h-2 rounded bg-muted">
                                <div class="h-2 rounded" :class="u.utilisation_pct > 100 ? 'bg-rose-500' : 'bg-emerald-500'" :style="{ width: `${Math.min(100, u.utilisation_pct)}%` }" />
                            </div>
                        </li>
                    </ul>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle class="text-base">Unbilled approved time</CardTitle>
                    <CardDescription>Valued at current rates; not yet on a finalized invoice.</CardDescription>
                </CardHeader>
                <CardContent>
                    <p v-if="unbilled.length === 0" class="text-sm text-muted-foreground">Everything approved has been billed.</p>
                    <ul v-else class="divide-y text-sm">
                        <li v-for="u in unbilled" :key="u.client" class="flex justify-between gap-2 py-1.5">
                            <span>
                                {{ u.client }}
                                <span class="text-xs text-muted-foreground">since {{ formatDate(u.oldest) }}</span>
                                <span v-if="u.unpriced_minutes" class="text-xs text-amber-700"> · {{ formatMinutes(u.unpriced_minutes) }} without a rate</span>
                            </span>
                            <span class="tabular-nums">{{ formatMinutes(u.minutes) }} · {{ formatMoney(u.value_minor, u.currency) }}</span>
                        </li>
                    </ul>
                </CardContent>
            </Card>
        </div>

        <Card>
            <CardHeader>
                <CardTitle class="text-base">Profitability by project</CardTitle>
                <CardDescription>
                    Billed hours (invoices issued in the range) against internal cost from the rate cards. Retainer-covered
                    hours carry no line amount; their revenue is on the retainer line.
                </CardDescription>
            </CardHeader>
            <CardContent class="overflow-x-auto">
                <p v-if="profitability.length === 0" class="text-sm text-muted-foreground">No billed time in this range.</p>
                <table v-else class="w-full text-sm">
                    <thead class="text-left text-xs text-muted-foreground">
                        <tr>
                            <th class="py-1.5 font-medium">Client</th>
                            <th class="py-1.5 font-medium">Project</th>
                            <th class="py-1.5 text-right font-medium">Hours</th>
                            <th class="py-1.5 text-right font-medium">Billed</th>
                            <th class="py-1.5 text-right font-medium">Cost</th>
                            <th class="py-1.5 text-right font-medium">Margin</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="r in profitability" :key="`${r.client}${r.project}`">
                            <td class="py-1.5">{{ r.client }}</td>
                            <td class="py-1.5">
                                {{ r.project }}
                                <span v-if="r.uncosted_minutes" class="text-xs text-amber-700">({{ formatMinutes(r.uncosted_minutes) }} without a cost rate)</span>
                            </td>
                            <td class="py-1.5 text-right tabular-nums">{{ formatMinutes(r.minutes) }}</td>
                            <td class="py-1.5 text-right tabular-nums">{{ formatMoney(r.revenue_minor, r.currency) }}</td>
                            <td class="py-1.5 text-right tabular-nums">{{ formatMoney(r.cost_minor, r.currency) }}</td>
                            <td class="py-1.5 text-right tabular-nums" :class="{ 'text-rose-600': r.margin_minor < 0 }">
                                {{ formatMoney(r.margin_minor, r.currency) }} <span class="text-xs text-muted-foreground">{{ marginPct(r) }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </CardContent>
        </Card>
    </div>
</template>
