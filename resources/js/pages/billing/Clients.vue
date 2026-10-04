<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import BillingNav from '@/components/billing/BillingNav.vue';
import Heading from '@/components/Heading.vue';
import { departmentDot, formatMinutes } from '@/lib/format';
import { formatMoney } from '@/lib/money';
import { show } from '@/routes/billing/clients';
import { index as invoicesIndex } from '@/routes/billing/invoices';

defineProps<{
    clients: {
        id: number;
        name: string;
        slug: string;
        status: string;
        color: string;
        configured: boolean;
        currency: string;
        retainer_minor: number;
        unbilled_minutes: number;
        balance_minor: number;
    }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Billing', href: invoicesIndex() }],
    },
});
</script>

<template>
    <Head title="Client billing" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <Heading title="Billing" description="Each client's billing setup, unbilled approved time and open balance." />
        <BillingNav />

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-xs text-muted-foreground">
                    <tr>
                        <th class="px-3 py-2 font-medium">Client</th>
                        <th class="px-3 py-2 font-medium">Setup</th>
                        <th class="px-3 py-2 text-right font-medium">Retainer</th>
                        <th class="px-3 py-2 text-right font-medium">Unbilled approved time</th>
                        <th class="px-3 py-2 text-right font-medium">Open balance</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="c in clients" :key="c.id">
                        <td class="px-3 py-2">
                            <Link :href="show(c.slug)" class="flex items-center gap-2 font-medium hover:underline">
                                <span class="size-2 rounded-full" :class="departmentDot[c.color] ?? 'bg-slate-500'" />
                                {{ c.name }}
                            </Link>
                        </td>
                        <td class="px-3 py-2 text-xs">
                            <span v-if="c.configured" class="text-emerald-700 dark:text-emerald-400">Configured · {{ c.currency }}</span>
                            <span v-else class="text-amber-700 dark:text-amber-400">Not set up</span>
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ c.retainer_minor ? formatMoney(c.retainer_minor, c.currency) : '—' }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ c.unbilled_minutes ? formatMinutes(c.unbilled_minutes) : '—' }}</td>
                        <td class="px-3 py-2 text-right tabular-nums" :class="{ 'font-medium': c.balance_minor }">
                            {{ c.balance_minor ? formatMoney(c.balance_minor, c.currency) : '—' }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
