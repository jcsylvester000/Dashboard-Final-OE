<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { Trash2 } from '@lucide/vue';
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
import { Label } from '@/components/ui/label';
import { confirmDialog } from '@/composables/useConfirm';
import { formatDate, formatMinutes, localToday } from '@/lib/format';
import { formatMoney, invoiceStatusLabel } from '@/lib/money';
import { store as storeDeposit } from '@/routes/billing/clients/deposits';
import { store as storeBlock } from '@/routes/billing/clients/prepaid';
import { show as invoiceShow } from '@/routes/billing/invoices';
import { destroy as destroyBlock } from '@/routes/billing/prepaid';
import type { PrepaidRow } from '@/types/billing';

/**
 * E-74: hour balances (rolled-over retainer hours and prepaid blocks) and deposits.
 * Hours beyond the retainer draw on these before anything is billed at rates.
 */
const props = defineProps<{
    slug: string;
    currency: string;
    rows: PrepaidRow[];
    methods: Record<string, string>;
}>();

const opts = { preserveScroll: true };

const blockForm = useForm({
    hours: '',
    amount: '',
    starts_on: localToday(),
    expires_on: '',
    note: '',
    create_invoice: true,
});

const depositForm = useForm({
    amount: '',
    received_on: localToday(),
    method: 'bank',
    reference: '',
});

function addBlock(): void {
    blockForm
        .transform((d) => ({ ...d, expires_on: d.expires_on || null }))
        .post(storeBlock.url(props.slug), {
            ...opts,
            onSuccess: () =>
                blockForm.reset('hours', 'amount', 'expires_on', 'note'),
        });
}

function addDeposit(): void {
    depositForm.post(storeDeposit.url(props.slug), {
        ...opts,
        onSuccess: () => depositForm.reset('amount', 'reference'),
    });
}

async function remove(row: PrepaidRow): Promise<void> {
    if (
        await confirmDialog(
            'Remove this prepaid block? Its draft invoice is deleted too.',
            { danger: true },
        )
    ) {
        router.delete(destroyBlock.url(row.id), opts);
    }
}
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle class="text-base"
                >Prepaid hours &amp; deposits</CardTitle
            >
            <CardDescription
                >Hours beyond the retainer use rolled-over hours first, then
                prepaid blocks, before anything is billed at rates. A block
                never goes below zero. Deposits pay the next invoices
                automatically.</CardDescription
            >
        </CardHeader>
        <CardContent class="space-y-4 text-sm">
            <p v-if="rows.length === 0" class="text-muted-foreground">
                No rolled-over hours or prepaid blocks yet.
            </p>
            <table v-else class="w-full">
                <thead>
                    <tr class="text-left text-xs text-muted-foreground">
                        <th class="py-1.5 font-medium">Balance</th>
                        <th class="py-1.5 text-right font-medium">Left</th>
                        <th class="py-1.5 font-medium">Valid</th>
                        <th class="py-1.5 font-medium">Invoice</th>
                        <th class="py-1.5">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="r in rows"
                        :key="r.id"
                        class="border-t"
                        :class="r.expired ? 'text-muted-foreground' : ''"
                    >
                        <td class="py-1.5">
                            {{ r.label }}
                            <span class="block text-xs text-muted-foreground"
                                >{{ formatMinutes(r.minutes) }} total ·
                                {{ formatMinutes(r.used_minutes) }}
                                used<template v-if="r.amount_minor">
                                    ·
                                    {{
                                        formatMoney(
                                            r.amount_minor,
                                            r.currency ?? currency,
                                        )
                                    }}</template
                                ></span
                            >
                        </td>
                        <td class="py-1.5 text-right font-medium tabular-nums">
                            {{ formatMinutes(r.left_minutes) }}
                        </td>
                        <td class="py-1.5 text-xs">
                            {{ formatDate(r.starts_on) }} –
                            {{
                                r.expires_on
                                    ? formatDate(r.expires_on)
                                    : 'no end'
                            }}
                            <span v-if="r.expired" class="text-rose-600">
                                (expired)</span
                            >
                        </td>
                        <td class="py-1.5 text-xs">
                            <Link
                                v-if="r.invoice"
                                :href="invoiceShow.url(r.invoice.id)"
                                class="underline"
                                >{{ r.invoice.number }} ·
                                {{
                                    invoiceStatusLabel[r.invoice.status] ??
                                    r.invoice.status
                                }}</Link
                            >
                            <span v-else>—</span>
                        </td>
                        <td class="py-1.5 text-right">
                            <Button
                                v-if="r.can_delete && r.kind === 'block'"
                                variant="ghost"
                                size="icon"
                                :aria-label="`Remove ${r.label}`"
                                @click="remove(r)"
                                ><Trash2 class="size-4"
                            /></Button>
                        </td>
                    </tr>
                </tbody>
            </table>

            <form
                class="grid gap-2 rounded-md border p-3 sm:grid-cols-6"
                @submit.prevent="addBlock"
            >
                <p class="font-medium sm:col-span-6">Add a prepaid block</p>
                <div class="grid gap-1 sm:col-span-1">
                    <Label for="pp-hours">Hours</Label>
                    <Input
                        id="pp-hours"
                        v-model="blockForm.hours"
                        inputmode="decimal"
                    />
                    <InputError :message="blockForm.errors.hours" />
                </div>
                <div class="grid gap-1 sm:col-span-2">
                    <Label for="pp-amount">Price ({{ currency }})</Label>
                    <Input
                        id="pp-amount"
                        v-model="blockForm.amount"
                        inputmode="decimal"
                    />
                    <InputError :message="blockForm.errors.amount" />
                </div>
                <div class="grid gap-1 sm:col-span-1">
                    <Label for="pp-from">From</Label>
                    <Input
                        id="pp-from"
                        v-model="blockForm.starts_on"
                        type="date"
                    />
                </div>
                <div class="grid gap-1 sm:col-span-2">
                    <Label for="pp-to">Use by (optional)</Label>
                    <Input
                        id="pp-to"
                        v-model="blockForm.expires_on"
                        type="date"
                    />
                    <InputError :message="blockForm.errors.expires_on" />
                </div>
                <div class="grid gap-1 sm:col-span-4">
                    <Label for="pp-note">Name (optional)</Label>
                    <Input
                        id="pp-note"
                        v-model="blockForm.note"
                        placeholder="e.g. Q1 support hours"
                    />
                </div>
                <label class="flex items-center gap-2 self-end sm:col-span-2">
                    <input v-model="blockForm.create_invoice" type="checkbox" />
                    Create its draft invoice
                </label>
                <div class="sm:col-span-6">
                    <Button
                        type="submit"
                        size="sm"
                        :disabled="blockForm.processing"
                        >Add block</Button
                    >
                </div>
            </form>

            <form
                class="grid gap-2 rounded-md border p-3 sm:grid-cols-6"
                @submit.prevent="addDeposit"
            >
                <p class="font-medium sm:col-span-6">Record a deposit</p>
                <div class="grid gap-1 sm:col-span-2">
                    <Label for="dep-amount">Amount ({{ currency }})</Label>
                    <Input
                        id="dep-amount"
                        v-model="depositForm.amount"
                        inputmode="decimal"
                    />
                    <InputError :message="depositForm.errors.amount" />
                </div>
                <div class="grid gap-1 sm:col-span-2">
                    <Label for="dep-on">Received</Label>
                    <Input
                        id="dep-on"
                        v-model="depositForm.received_on"
                        type="date"
                    />
                    <InputError :message="depositForm.errors.received_on" />
                </div>
                <div class="grid gap-1 sm:col-span-2">
                    <Label for="dep-method">Method</Label>
                    <NativeSelect id="dep-method" v-model="depositForm.method">
                        <option
                            v-for="(label, key) in methods"
                            :key="key"
                            :value="key"
                        >
                            {{ label }}
                        </option>
                    </NativeSelect>
                </div>
                <div class="grid gap-1 sm:col-span-4">
                    <Label for="dep-ref">Reference (optional)</Label>
                    <Input id="dep-ref" v-model="depositForm.reference" />
                </div>
                <div class="self-end sm:col-span-2">
                    <Button
                        type="submit"
                        size="sm"
                        :disabled="depositForm.processing"
                        >Record deposit</Button
                    >
                </div>
            </form>
        </CardContent>
    </Card>
</template>
