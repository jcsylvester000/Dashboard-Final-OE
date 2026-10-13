<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import BillingNav from '@/components/billing/BillingNav.vue';
import RateTable from '@/components/billing/RateTable.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    edit as editTemplate,
    store as storeTemplate,
} from '@/routes/billing/rate-templates';
import { index as invoicesIndex } from '@/routes/billing/invoices';
import { history as rateHistory } from '@/routes/billing/rates';
import type { RateRow } from '@/types/billing';

defineProps<{
    rates: RateRow[];
    departments: { id: number; name: string }[];
    people: { id: number; name: string }[];
    templates: {
        id: number;
        name: string;
        description: string | null;
        items: number;
        clients: number;
    }[];
}>();

const newTemplate = useForm({ name: '', description: '', copy_agency: true });

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Billing', href: invoicesIndex() }],
    },
});
</script>

<template>
    <Head title="Rates" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <Heading
            title="Billing"
            description="Agency-wide hourly rates. Client overrides live on each client's billing page."
        />
        <BillingNav />

        <Card>
            <CardHeader>
                <CardTitle class="text-base">Rate templates</CardTitle>
                <CardDescription>
                    Each person's own hourly rate, saved as a reusable set.
                    Apply a template on a client's billing page: the client gets
                    its own copy, which you can then change for that client
                    only.
                </CardDescription>
            </CardHeader>
            <CardContent class="space-y-4">
                <ul v-if="templates.length" class="divide-y text-sm">
                    <li
                        v-for="t in templates"
                        :key="t.id"
                        class="flex items-center justify-between gap-3 py-2"
                    >
                        <div class="min-w-0">
                            <Link
                                :href="editTemplate(t.id)"
                                class="font-medium hover:underline"
                                >{{ t.name }}</Link
                            >
                            <p class="text-xs text-muted-foreground">
                                {{ t.items }} rate(s) · used by
                                {{ t.clients }} client(s)<template
                                    v-if="t.description"
                                >
                                    · {{ t.description }}</template
                                >
                            </p>
                        </div>
                        <Button size="sm" variant="outline" as-child
                            ><Link :href="editTemplate(t.id)"
                                >Edit rates</Link
                            ></Button
                        >
                    </li>
                </ul>
                <p v-else class="text-sm text-muted-foreground">
                    No templates yet.
                </p>
                <form
                    class="flex flex-wrap items-end gap-2 border-t pt-3 text-sm"
                    @submit.prevent="newTemplate.post(storeTemplate.url())"
                >
                    <label class="grid gap-1">
                        <span class="text-xs text-muted-foreground"
                            >New template name</span
                        >
                        <Input
                            v-model="newTemplate.name"
                            class="w-56"
                            placeholder="e.g. Standard 2026"
                            required
                        />
                    </label>
                    <label class="grid gap-1">
                        <span class="text-xs text-muted-foreground"
                            >Description</span
                        >
                        <Input
                            v-model="newTemplate.description"
                            class="w-64"
                            placeholder="optional"
                        />
                    </label>
                    <label class="flex items-center gap-1.5 pb-2 text-xs">
                        <input
                            v-model="newTemplate.copy_agency"
                            type="checkbox"
                            class="size-4"
                        />
                        Start from agency rates
                    </label>
                    <Button
                        type="submit"
                        size="sm"
                        :disabled="newTemplate.processing"
                        >Create template</Button
                    >
                    <InputError
                        class="w-full"
                        :message="newTemplate.errors.name"
                    />
                </form>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <CardTitle class="text-base">Agency rates</CardTitle>
                    <Link
                        :href="rateHistory()"
                        class="text-xs text-muted-foreground hover:underline"
                        >Rate change history →</Link
                    >
                </div>
                <CardDescription>
                    Which rate applies: client + person, client + department,
                    client default, then person, department, agency default. The
                    latest rate effective on the work date wins. Internal cost
                    feeds profitability reports.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <RateTable
                    :rates="rates"
                    :workspace-id="null"
                    currency="PHP"
                    :departments="departments"
                    :people="people"
                />
            </CardContent>
        </Card>
    </div>
</template>
