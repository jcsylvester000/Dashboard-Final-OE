<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import BillingNav from '@/components/billing/BillingNav.vue';
import RateTable from '@/components/billing/RateTable.vue';
import Heading from '@/components/Heading.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { index as invoicesIndex } from '@/routes/billing/invoices';
import type { RateRow } from '@/types/billing';

defineProps<{
    rates: RateRow[];
    departments: { id: number; name: string }[];
    people: { id: number; name: string }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Billing', href: invoicesIndex() }],
    },
});
</script>

<template>
    <Head title="Rates" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <Heading title="Billing" description="Agency-wide hourly rates. Client overrides live on each client's billing page." />
        <BillingNav />

        <Card>
            <CardHeader>
                <CardTitle class="text-base">Agency rates</CardTitle>
                <CardDescription>
                    Which rate applies: client + person, client + department, client default, then person, department,
                    agency default. The latest rate effective on the work date wins. Internal cost feeds profitability reports.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <RateTable :rates="rates" :workspace-id="null" currency="PHP" :departments="departments" :people="people" />
            </CardContent>
        </Card>
    </div>
</template>
