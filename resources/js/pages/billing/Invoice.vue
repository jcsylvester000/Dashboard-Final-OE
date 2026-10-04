<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ChevronDown, ChevronRight } from '@lucide/vue';
import { computed, ref } from 'vue';
import BillingNav from '@/components/billing/BillingNav.vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { formatDate, formatDateTime, formatMinutes } from '@/lib/format';
import { formatMoney, invoiceStatusClass, invoiceStatusLabel } from '@/lib/money';
import { show as clientShow } from '@/routes/billing/clients';
import {
    credit,
    destroy,
    finalize,
    index,
    recalculate,
    show,
    update,
    cancel as voidInvoice,
} from '@/routes/billing/invoices';
import { store as storePayment } from '@/routes/billing/invoices/payments';
import type { InvoiceDetail } from '@/types/billing';

const props = defineProps<{
    invoice: InvoiceDetail;
    methods: Record<string, string>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Billing', href: index() }],
    },
});

const page = usePage();
const billingError = computed(() => (page.props.errors as Record<string, string> | undefined)?.billing);

const inv = computed(() => props.invoice);
const isOpen = computed(() => ['finalized', 'partially_paid'].includes(inv.value.status));
const canCredit = computed(() => inv.value.kind === 'invoice' && ['finalized', 'partially_paid', 'paid'].includes(inv.value.status));
const canVoid = computed(() => isOpen.value && inv.value.paid_minor === 0 && inv.value.credited_minor === 0);
const money = (minor: number | null | undefined) => formatMoney(minor, inv.value.currency);

const expanded = ref<Record<number, boolean>>({});
const opts = { preserveScroll: true };

const notes = useForm({ notes: props.invoice.notes ?? '' });
const payment = useForm({
    amount: '',
    method: 'bank',
    reference: '',
    received_on: new Date().toISOString().slice(0, 10),
    note: '',
});
const creditForm = useForm({ amount: '', reason: '' });
const voidForm = useForm({ reason: '' });

function post(url: string, confirmText?: string): void {
    if (confirmText && !window.confirm(confirmText)) {
        return;
    }
    router.post(url, {}, opts);
}

function removeDraft(): void {
    if (window.confirm('Delete this draft? Its time stays unbilled.')) {
        router.delete(destroy.url(inv.value.id));
    }
}
</script>

<template>
    <Head :title="inv.number" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <BillingNav />

        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="flex items-center gap-2 text-xl font-semibold">
                    {{ inv.kind === 'credit_note' ? 'Credit note' : 'Invoice' }} {{ inv.number }}
                    <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="invoiceStatusClass[inv.status]">{{
                        invoiceStatusLabel[inv.status] ?? inv.status
                    }}</span>
                </h1>
                <p class="text-sm text-muted-foreground">
                    <Link v-if="inv.workspace" :href="clientShow(inv.workspace.slug)" class="underline underline-offset-4">{{ inv.workspace.name }}</Link>
                    · {{ formatDate(inv.period_start) }} – {{ formatDate(inv.period_end) }}
                    <template v-if="inv.issue_date"> · issued {{ formatDate(inv.issue_date) }} · due {{ formatDate(inv.due_date) }}</template>
                </p>
                <p v-if="inv.credited_invoice_id" class="text-sm">
                    Credits <Link :href="show(inv.credited_invoice_id)" class="underline underline-offset-4">invoice #{{ inv.credited_invoice_id }}</Link>
                </p>
            </div>
            <div v-if="inv.status === 'draft'" class="flex flex-wrap gap-2">
                <Button variant="outline" size="sm" @click="post(recalculate.url(inv.id))">Recalculate</Button>
                <Button size="sm" @click="post(finalize.url(inv.id), 'Finalize? The invoice gets a number and can no longer be changed.')">Finalize</Button>
                <Button variant="ghost" size="sm" @click="removeDraft">Delete draft</Button>
            </div>
        </div>

        <p v-if="billingError" class="rounded-md border border-rose-300 bg-rose-50 p-3 text-sm text-rose-800 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-200">
            {{ billingError }}
        </p>

        <div class="grid gap-4 lg:grid-cols-3">
            <Card class="lg:col-span-2">
                <CardHeader>
                    <CardTitle class="text-base">Lines</CardTitle>
                    <CardDescription>Open a line to see the time entries, expenses or projects it bills.</CardDescription>
                </CardHeader>
                <CardContent class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-left text-xs text-muted-foreground">
                            <tr>
                                <th class="py-2 font-medium">Description</th>
                                <th class="py-2 text-right font-medium">Qty</th>
                                <th class="py-2 text-right font-medium">Rate</th>
                                <th class="py-2 text-right font-medium">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="line in inv.items" :key="line.id">
                                <tr class="border-t">
                                    <td class="py-2">
                                        <button
                                            v-if="line.sources.length"
                                            type="button"
                                            class="inline-flex items-center gap-1 text-left hover:underline"
                                            :aria-expanded="!!expanded[line.id]"
                                            @click="expanded[line.id] = !expanded[line.id]"
                                        >
                                            <component :is="expanded[line.id] ? ChevronDown : ChevronRight" class="size-3.5" />
                                            {{ line.description }}
                                        </button>
                                        <span v-else>{{ line.description }}</span>
                                        <span class="ml-1 text-xs text-muted-foreground">({{ line.sources.length }} source{{ line.sources.length === 1 ? '' : 's' }})</span>
                                    </td>
                                    <td class="py-2 text-right tabular-nums">{{ line.minutes !== null ? `${line.quantity} h` : line.quantity }}</td>
                                    <td class="py-2 text-right tabular-nums">{{ money(line.unit_minor) }}</td>
                                    <td class="py-2 text-right tabular-nums">{{ money(line.amount_minor) }}</td>
                                </tr>
                                <tr v-if="expanded[line.id]">
                                    <td colspan="4" class="pb-3">
                                        <ul class="ml-5 divide-y rounded border bg-muted/30 text-xs">
                                            <li v-for="s in line.sources" :key="s.id" class="flex flex-wrap items-center justify-between gap-2 px-2 py-1.5">
                                                <span class="min-w-0">
                                                    <span v-if="s.date" class="text-muted-foreground">{{ formatDate(s.date) }} · </span>
                                                    <span v-if="s.person" class="font-medium">{{ s.person }} · </span>
                                                    <Link v-if="s.task && s.task_url" :href="s.task_url" class="underline underline-offset-4">{{ s.task }}</Link>
                                                    <span v-else-if="s.task">{{ s.task }}</span>
                                                    <span v-if="s.note" class="text-muted-foreground"> · {{ s.note }}</span>
                                                </span>
                                                <span class="shrink-0 tabular-nums">
                                                    <template v-if="s.minutes !== null">{{ formatMinutes(s.minutes) }}</template>
                                                    <template v-if="s.amount_minor"> · {{ money(s.amount_minor) }}</template>
                                                    <template v-else-if="s.minutes !== null"> · covered</template>
                                                </span>
                                            </li>
                                        </ul>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                        <tfoot class="border-t text-sm">
                            <tr>
                                <td colspan="3" class="py-1 text-right text-muted-foreground">Subtotal</td>
                                <td class="py-1 text-right tabular-nums">{{ money(inv.subtotal_minor) }}</td>
                            </tr>
                            <tr>
                                <td colspan="3" class="py-1 text-right text-muted-foreground">Tax ({{ (inv.tax_rate_bp / 100).toFixed(2) }}%)</td>
                                <td class="py-1 text-right tabular-nums">{{ money(inv.tax_minor) }}</td>
                            </tr>
                            <tr class="font-semibold">
                                <td colspan="3" class="py-1 text-right">Total</td>
                                <td class="py-1 text-right tabular-nums">{{ money(inv.total_minor) }}</td>
                            </tr>
                            <tr v-if="inv.paid_minor || inv.credited_minor">
                                <td colspan="3" class="py-1 text-right text-muted-foreground">Paid / credited</td>
                                <td class="py-1 text-right tabular-nums">-{{ money(inv.paid_minor + inv.credited_minor) }}</td>
                            </tr>
                            <tr v-if="isOpen" class="font-semibold">
                                <td colspan="3" class="py-1 text-right">Balance due</td>
                                <td class="py-1 text-right tabular-nums">{{ money(inv.balance_minor) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                    <p v-if="inv.items.length === 0" class="py-6 text-center text-sm text-muted-foreground">
                        Nothing to bill: no approved billable time, retainer, fixed fees or expenses in this period.
                    </p>
                </CardContent>
            </Card>

            <div class="flex flex-col gap-4">
                <Card>
                    <CardHeader><CardTitle class="text-base">Bill to</CardTitle></CardHeader>
                    <CardContent class="space-y-0.5 text-sm">
                        <template v-if="inv.bill_to">
                            <p class="font-medium">{{ inv.bill_to.name }}</p>
                            <p v-if="inv.bill_to.address" class="whitespace-pre-line text-muted-foreground">{{ inv.bill_to.address }}</p>
                            <p v-if="inv.bill_to.tax_id" class="text-muted-foreground">TIN {{ inv.bill_to.tax_id }}</p>
                            <p v-if="inv.bill_to.contact" class="text-muted-foreground">Attn: {{ inv.bill_to.contact }}</p>
                        </template>
                        <p v-else class="text-muted-foreground">Set when finalized, from the client's billing profile.</p>
                    </CardContent>
                </Card>

                <Card v-if="inv.status === 'draft'">
                    <CardHeader><CardTitle class="text-base">Notes on the invoice</CardTitle></CardHeader>
                    <CardContent>
                        <form class="space-y-2" @submit.prevent="notes.put(update.url(inv.id), opts)">
                            <textarea v-model="notes.notes" rows="3" class="w-full rounded-md border bg-transparent p-2 text-sm" />
                            <Button size="sm" type="submit" :disabled="notes.processing">Save notes</Button>
                        </form>
                    </CardContent>
                </Card>

                <Card v-if="isOpen && inv.kind === 'invoice'">
                    <CardHeader>
                        <CardTitle class="text-base">Record a payment</CardTitle>
                        <CardDescription>Balance {{ money(inv.balance_minor) }}</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form class="grid gap-2 text-sm" @submit.prevent="payment.post(storePayment.url(inv.id), { ...opts, onSuccess: () => payment.reset() })">
                            <Input v-model="payment.amount" inputmode="decimal" placeholder="Amount e.g. 12500.00" aria-label="Amount" />
                            <InputError :message="payment.errors.amount" />
                            <NativeSelect v-model="payment.method" aria-label="Method">
                                <option v-for="(label, key) in methods" :key="key" :value="key">{{ label }}</option>
                            </NativeSelect>
                            <Input v-model="payment.reference" placeholder="Reference no." aria-label="Reference" />
                            <Input v-model="payment.received_on" type="date" aria-label="Received on" />
                            <InputError :message="payment.errors.received_on" />
                            <Button size="sm" type="submit" :disabled="payment.processing">Record payment</Button>
                        </form>
                    </CardContent>
                </Card>

                <Card v-if="canCredit">
                    <CardHeader>
                        <CardTitle class="text-base">Credit note</CardTitle>
                        <CardDescription>Corrections are made with a credit note; the invoice itself never changes.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form class="grid gap-2 text-sm" @submit.prevent="creditForm.post(credit.url(inv.id))">
                            <Input v-model="creditForm.amount" inputmode="decimal" placeholder="Amount before tax" aria-label="Credit amount" />
                            <InputError :message="creditForm.errors.amount" />
                            <Input v-model="creditForm.reason" placeholder="Reason" aria-label="Reason" />
                            <InputError :message="creditForm.errors.reason" />
                            <Button size="sm" variant="outline" type="submit" :disabled="creditForm.processing">Issue credit note</Button>
                        </form>
                    </CardContent>
                </Card>

                <Card v-if="canVoid">
                    <CardHeader>
                        <CardTitle class="text-base">Void</CardTitle>
                        <CardDescription>Cancels this invoice; its time becomes billable again.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form class="grid gap-2 text-sm" @submit.prevent="voidForm.post(voidInvoice.url(inv.id), opts)">
                            <Input v-model="voidForm.reason" placeholder="Reason" aria-label="Void reason" />
                            <InputError :message="voidForm.errors.reason" />
                            <Button size="sm" variant="destructive" type="submit" :disabled="voidForm.processing">Void invoice</Button>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <Card>
                <CardHeader><CardTitle class="text-base">Payments &amp; credit notes</CardTitle></CardHeader>
                <CardContent class="text-sm">
                    <p v-if="inv.payments.length === 0 && inv.credit_notes.length === 0" class="text-muted-foreground">None yet.</p>
                    <ul class="divide-y">
                        <li v-for="p in inv.payments" :key="`p${p.id}`" class="flex justify-between gap-2 py-1.5">
                            <span>{{ formatDate(p.received_on) }} · {{ p.method }}<span v-if="p.reference" class="text-muted-foreground"> · {{ p.reference }}</span></span>
                            <span class="tabular-nums">{{ money(p.amount_minor) }}</span>
                        </li>
                        <li v-for="c in inv.credit_notes" :key="`c${c.id}`" class="flex justify-between gap-2 py-1.5">
                            <Link :href="show(c.id)" class="underline underline-offset-4">Credit note {{ c.number }}</Link>
                            <span class="tabular-nums">{{ money(c.total_minor) }}</span>
                        </li>
                    </ul>
                </CardContent>
            </Card>
            <Card>
                <CardHeader><CardTitle class="text-base">History</CardTitle></CardHeader>
                <CardContent>
                    <ul class="divide-y text-sm">
                        <li v-for="h in inv.history" :key="h.id" class="flex justify-between gap-3 py-1.5">
                            <span>
                                <span class="font-medium">{{ invoiceStatusLabel[h.to] ?? h.to }}</span>
                                <span v-if="h.note" class="text-muted-foreground"> · {{ h.note }}</span>
                                <span class="text-muted-foreground"> · {{ h.by ?? 'System' }}</span>
                            </span>
                            <span class="shrink-0 text-xs text-muted-foreground">{{ formatDateTime(h.at) }}</span>
                        </li>
                    </ul>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
