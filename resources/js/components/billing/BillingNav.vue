<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { index as clientsIndex } from '@/routes/billing/clients';
import { index as invoicesIndex } from '@/routes/billing/invoices';
import { index as ratesIndex } from '@/routes/billing/rates';
import { index as reportsIndex } from '@/routes/billing/reports';

const { isCurrentOrParentUrl } = useCurrentUrl();

const items = [
    { title: 'Invoices', href: invoicesIndex() },
    { title: 'Clients', href: clientsIndex() },
    { title: 'Rates', href: ratesIndex() },
    { title: 'Reports', href: reportsIndex() },
];
</script>

<template>
    <nav class="flex flex-wrap gap-1 border-b text-sm" aria-label="Billing">
        <Link
            v-for="item in items"
            :key="item.title"
            :href="item.href"
            class="-mb-px border-b-2 px-3 py-2"
            :class="isCurrentOrParentUrl(item.href) ? 'border-foreground font-medium' : 'border-transparent text-muted-foreground hover:text-foreground'"
            >{{ item.title }}</Link
        >
    </nav>
</template>
