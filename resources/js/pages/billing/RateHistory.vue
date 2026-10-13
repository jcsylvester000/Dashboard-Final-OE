<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';
import BillingNav from '@/components/billing/BillingNav.vue';
import Heading from '@/components/Heading.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import Pagination from '@/components/Pagination.vue';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { formatDate, formatDateTime } from '@/lib/format';
import { index as invoicesIndex } from '@/routes/billing/invoices';
import { history, index as ratesIndex } from '@/routes/billing/rates';
import type { Paginated } from '@/types/admin';

type Entry = {
    id: number;
    at: string;
    actor: string;
    action: 'rate.set' | 'rate.removed' | 'rate.template-changed';
    scope: string;
    where: string;
    for: string;
    from: string | null;
    from_note: string | null;
    to: string | null;
    cost_from: string | null;
    cost_to: string | null;
    effective_from: string | null;
    via: string | null;
    reason?: string | null;
};

const props = defineProps<{
    entries: Paginated<Entry>;
    people: { id: number; name: string }[];
    filters: { person?: number | string; scope?: string };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Billing', href: invoicesIndex() }],
    },
});

const form = reactive({
    person: props.filters.person ? String(props.filters.person) : '',
    scope: props.filters.scope ?? '',
});
watch(form, () => {
    const query = Object.fromEntries(
        Object.entries(form).filter(([, v]) => v !== ''),
    );
    router.get(
        history.url({ query }),
        {},
        { preserveState: true, preserveScroll: true, replace: true },
    );
});

const verb: Record<Entry['action'], string> = {
    'rate.set': 'Set',
    'rate.removed': 'Removed',
    'rate.template-changed': 'Template edited',
};
</script>

<template>
    <Head title="Rate change history" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <Heading
            title="Billing"
            description="Every rate change: who made it, when, and the old and new amounts."
        />
        <BillingNav />

        <Card>
            <CardHeader>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <CardTitle class="text-base">Rate change history</CardTitle>
                    <Link
                        :href="ratesIndex()"
                        class="text-xs text-muted-foreground hover:underline"
                        >← Rates and templates</Link
                    >
                </div>
                <CardDescription
                    >Append-only. Rates already on invoices never change;
                    invoices keep their own copy.</CardDescription
                >
                <div class="flex flex-wrap gap-2 pt-2">
                    <NativeSelect
                        v-model="form.person"
                        class="w-52"
                        aria-label="Person"
                    >
                        <option value="">Everyone</option>
                        <option
                            v-for="p in people"
                            :key="p.id"
                            :value="String(p.id)"
                        >
                            {{ p.name }}
                        </option>
                    </NativeSelect>
                    <NativeSelect
                        v-model="form.scope"
                        class="w-44"
                        aria-label="Where"
                    >
                        <option value="">Agency, clients, templates</option>
                        <option value="agency">Agency rates</option>
                        <option value="client">Client rates</option>
                        <option value="template">Templates</option>
                    </NativeSelect>
                </div>
            </CardHeader>
            <CardContent class="overflow-x-auto">
                <p
                    v-if="entries.data.length === 0"
                    class="py-6 text-center text-sm text-muted-foreground"
                >
                    No rate changes recorded yet.
                </p>
                <table v-else class="w-full text-sm">
                    <thead class="text-left text-xs text-muted-foreground">
                        <tr>
                            <th class="py-1.5 font-medium">When</th>
                            <th class="py-1.5 font-medium">By</th>
                            <th class="py-1.5 font-medium">Change</th>
                            <th class="py-1.5 font-medium">For</th>
                            <th class="py-1.5 font-medium">Where</th>
                            <th class="py-1.5 text-right font-medium">
                                Rate / h
                            </th>
                            <th class="py-1.5 text-right font-medium">
                                Cost / h
                            </th>
                            <th class="py-1.5 font-medium">Effective</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="e in entries.data"
                            :key="e.id"
                            class="border-t align-top"
                        >
                            <td class="py-1.5 whitespace-nowrap">
                                {{ formatDateTime(e.at) }}
                            </td>
                            <td class="py-1.5">{{ e.actor }}</td>
                            <td class="py-1.5">
                                {{ verb[e.action]
                                }}<span
                                    v-if="e.via"
                                    class="text-xs text-muted-foreground"
                                >
                                    · from template {{ e.via }}</span
                                >
                                <span
                                    v-if="e.reason"
                                    class="block text-xs text-muted-foreground"
                                    >Reason: {{ e.reason }}</span
                                >
                            </td>
                            <td class="py-1.5">{{ e.for }}</td>
                            <td class="py-1.5 text-muted-foreground">
                                {{ e.where }}
                            </td>
                            <td
                                class="py-1.5 text-right whitespace-nowrap tabular-nums"
                            >
                                <span class="text-muted-foreground"
                                    >{{ e.from ?? '—'
                                    }}<template v-if="e.from_note">
                                        ({{ e.from_note }})</template
                                    ></span
                                >
                                → <strong>{{ e.to ?? '—' }}</strong>
                            </td>
                            <td
                                class="py-1.5 text-right whitespace-nowrap text-muted-foreground tabular-nums"
                            >
                                {{ e.cost_from ?? '—' }} →
                                {{ e.cost_to ?? '—' }}
                            </td>
                            <td class="py-1.5 whitespace-nowrap">
                                {{
                                    e.effective_from
                                        ? formatDate(e.effective_from)
                                        : '—'
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </CardContent>
        </Card>

        <Pagination
            :links="entries.links"
            :from="entries.from"
            :to="entries.to"
            :total="entries.total"
        />
    </div>
</template>
