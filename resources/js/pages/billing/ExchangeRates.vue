<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import BillingNav from '@/components/billing/BillingNav.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { formatDate, formatDateTime } from '@/lib/format';
import { index as invoicesIndex } from '@/routes/billing/invoices';
import { store } from '@/routes/billing/fx';

/**
 * E-103: PHP exchange rates Finance enters by hand. Invoices lock the rate of their
 * issue date, payments the rate of their payment date (E-104).
 */
const props = defineProps<{
    currencies: string[];
    current: Record<
        string,
        { rate: string; on: string; stale: boolean } | null
    >;
    rates: {
        id: number;
        currency: string;
        rate_on: string;
        rate: string;
        note: string | null;
        by: string | null;
        updated_at: string | null;
    }[];
    today: string;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Billing', href: invoicesIndex() }] },
});

const page = usePage();
const canEdit = computed(() => !!page.props.auth.can['billing.settings']);
const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string | undefined>,
);
const form = useForm({
    currency: props.currencies[0] ?? 'USD',
    rate_on: props.today,
    rate: '',
    note: '',
});
const exists = computed(() =>
    props.rates.some(
        (r) => r.currency === form.currency && r.rate_on === form.rate_on,
    ),
);
</script>

<template>
    <Head title="Exchange rates" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <BillingNav />
        <Heading
            title="Exchange rates"
            description="PHP for one unit of each currency, entered by Finance (for example the BSP reference rate). Invoices keep the rate of their issue date and payments the rate of their payment date, so later changes never alter them."
        />

        <div class="grid gap-3 sm:grid-cols-3">
            <Card v-for="c in currencies" :key="c">
                <CardContent class="pt-6">
                    <p class="text-xs text-muted-foreground">1 {{ c }} today</p>
                    <p
                        v-if="current[c]"
                        class="text-2xl font-semibold tabular-nums"
                    >
                        ₱{{ current[c]!.rate }}
                    </p>
                    <p v-else class="text-sm text-rose-700 dark:text-rose-300">
                        No rate yet
                    </p>
                    <p
                        v-if="current[c]?.stale"
                        class="text-xs text-amber-700 dark:text-amber-300"
                    >
                        Using the rate from {{ formatDate(current[c]!.on) }}
                    </p>
                </CardContent>
            </Card>
        </div>

        <Card v-if="canEdit">
            <CardHeader>
                <CardTitle class="text-base">Add a rate</CardTitle>
                <CardDescription
                    >Days without a rate use the last earlier one. Changing a
                    saved rate needs a reason and is logged.</CardDescription
                >
            </CardHeader>
            <CardContent>
                <form
                    class="flex flex-wrap items-end gap-2 text-sm"
                    @submit.prevent="
                        form.post(store.url(), {
                            preserveScroll: true,
                            onSuccess: () => form.reset('rate', 'note'),
                        })
                    "
                >
                    <label class="grid gap-1 text-xs text-muted-foreground"
                        >Currency
                        <NativeSelect v-model="form.currency" class="h-9 w-24">
                            <option v-for="c in currencies" :key="c" :value="c">
                                {{ c }}
                            </option>
                        </NativeSelect>
                    </label>
                    <label class="grid gap-1 text-xs text-muted-foreground"
                        >Date
                        <Input
                            v-model="form.rate_on"
                            type="date"
                            :max="today"
                            class="h-9 w-40"
                    /></label>
                    <label class="grid gap-1 text-xs text-muted-foreground"
                        >PHP for 1 {{ form.currency }}
                        <Input
                            v-model="form.rate"
                            inputmode="decimal"
                            placeholder="e.g. 56.1234"
                            class="h-9 w-36"
                    /></label>
                    <label
                        class="grid min-w-0 flex-1 gap-1 text-xs text-muted-foreground"
                        >{{
                            exists
                                ? 'Reason for the change (required)'
                                : 'Source (optional)'
                        }}
                        <Input
                            v-model="form.note"
                            class="h-9"
                            :placeholder="
                                exists
                                    ? 'Why the rate changes'
                                    : 'BSP reference rate'
                            "
                    /></label>
                    <Button
                        type="submit"
                        size="sm"
                        :disabled="
                            form.processing ||
                            !form.rate ||
                            (exists && !form.note.trim())
                        "
                        >{{ exists ? 'Change rate' : 'Save rate' }}</Button
                    >
                    <InputError
                        class="w-full"
                        :message="
                            form.errors.rate ??
                            form.errors.rate_on ??
                            errors.billing
                        "
                    />
                </form>
            </CardContent>
        </Card>

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-sm">
                <thead
                    class="bg-muted/50 text-left text-xs text-muted-foreground"
                >
                    <tr>
                        <th class="px-4 py-2 font-medium">Date</th>
                        <th class="px-4 py-2 font-medium">Currency</th>
                        <th class="px-4 py-2 text-right font-medium">
                            PHP for 1
                        </th>
                        <th class="px-4 py-2 font-medium">Source / reason</th>
                        <th class="px-4 py-2 font-medium">By</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-if="rates.length === 0">
                        <td
                            colspan="5"
                            class="px-4 py-6 text-center text-muted-foreground"
                        >
                            No rates yet. Foreign invoices finalized now get no
                            PHP equivalent until a rate exists.
                        </td>
                    </tr>
                    <tr v-for="r in rates" :key="r.id">
                        <td class="px-4 py-2">{{ formatDate(r.rate_on) }}</td>
                        <td class="px-4 py-2">{{ r.currency }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">
                            ₱{{ r.rate }}
                        </td>
                        <td class="px-4 py-2 text-xs text-muted-foreground">
                            {{ r.note ?? '—' }}
                        </td>
                        <td class="px-4 py-2 text-xs text-muted-foreground">
                            {{ r.by ?? '—' }} ·
                            {{ formatDateTime(r.updated_at) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
