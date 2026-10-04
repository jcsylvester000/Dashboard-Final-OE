<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Trash2 } from '@lucide/vue';
import { computed, reactive } from 'vue';
import BillingNav from '@/components/billing/BillingNav.vue';
import RateTable from '@/components/billing/RateTable.vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDate } from '@/lib/format';
import { formatMoney, invoiceStatusLabel } from '@/lib/money';
import { profile as profileRoute } from '@/routes/billing/clients';
import { store as storeExpense } from '@/routes/billing/clients/expenses';
import { store as storePeriod } from '@/routes/billing/clients/periods';
import { destroy as destroyExpense } from '@/routes/billing/expenses';
import { index as invoicesIndex, show as invoiceShow } from '@/routes/billing/invoices';
import { invoice as invoicePeriod, lock, unlock } from '@/routes/billing/periods';
import { fixedFee } from '@/routes/billing/projects';
import type { RateRow } from '@/types/billing';

type Period = {
    id: number;
    starts_on: string;
    ends_on: string;
    status: 'open' | 'locked' | 'invoiced';
    invoices: { id: number; number: string; status: string; total_minor: number; currency: string }[];
};

const props = defineProps<{
    workspace: { id: number; name: string; slug: string; color: string };
    profile: {
        configured: boolean;
        currency: string;
        cycle: string;
        retainer: string;
        included_hours: string;
        overage_rule: string;
        terms_days: number;
        tax_rate: string;
        invoice_series: string;
        bill_to: Record<string, string | null>;
        payment_instructions: string | null;
    };
    periods: Period[];
    rates: RateRow[];
    expenses: { id: number; description: string; amount_minor: number; incurred_on: string; is_billable: boolean; project: string | null; billed: boolean }[];
    projects: { id: number; name: string; status: string; fixed_fee: string; billed: boolean }[];
    departments: { id: number; name: string }[];
    members: { id: number; name: string }[];
    options: { currencies: string[]; cycles: Record<string, string>; overageRules: Record<string, string> };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Billing', href: invoicesIndex() }],
    },
});

const page = usePage();
const billingError = computed(() => (page.props.errors as Record<string, string> | undefined)?.billing);
const opts = { preserveScroll: true };

const form = useForm({
    currency: props.profile.currency,
    cycle: props.profile.cycle,
    retainer: props.profile.retainer,
    included_hours: props.profile.included_hours,
    overage_rule: props.profile.overage_rule,
    terms_days: props.profile.terms_days,
    tax_rate: props.profile.tax_rate,
    invoice_series: props.profile.invoice_series,
    bill_to: {
        name: props.profile.bill_to.name ?? '',
        address: props.profile.bill_to.address ?? '',
        tax_id: props.profile.bill_to.tax_id ?? '',
        contact: props.profile.bill_to.contact ?? '',
    },
    payment_instructions: props.profile.payment_instructions ?? '',
});

const periodForm = useForm({ starts_on: '', ends_on: '' });
const expenseForm = useForm({ description: '', amount: '', incurred_on: new Date().toISOString().slice(0, 10), is_billable: true });
const fees = reactive<Record<string, string>>(Object.fromEntries(props.projects.map((p) => [String(p.id), p.fixed_fee])));

function post(url: string, confirmText?: string): void {
    if (confirmText && !window.confirm(confirmText)) {
        return;
    }
    router.post(url, {}, opts);
}

function saveFee(projectId: number): void {
    router.put(fixedFee.url(projectId), { fixed_fee: fees[String(projectId)] ?? '' }, opts);
}

const statusText: Record<Period['status'], string> = { open: 'Open', locked: 'Locked', invoiced: 'Invoiced' };
</script>

<template>
    <Head :title="`${workspace.name} - Billing`" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <BillingNav />
        <h1 class="text-xl font-semibold">{{ workspace.name }}</h1>

        <p v-if="billingError" class="rounded-md border border-rose-300 bg-rose-50 p-3 text-sm text-rose-800 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-200">
            {{ billingError }}
        </p>

        <Card>
            <CardHeader>
                <CardTitle class="text-base">Billing periods</CardTitle>
                <CardDescription>
                    Lock a period to freeze its time (nobody can add or change time dated inside it), then create the invoice.
                    Only approved, billable time is invoiced.
                </CardDescription>
            </CardHeader>
            <CardContent class="space-y-3">
                <ul v-if="periods.length" class="divide-y text-sm">
                    <li v-for="p in periods" :key="p.id" class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <span>
                            <span class="font-medium">{{ formatDate(p.starts_on) }} – {{ formatDate(p.ends_on) }}</span>
                            <span class="ml-2 text-xs text-muted-foreground">{{ statusText[p.status] }}</span>
                            <span v-for="i in p.invoices" :key="i.id" class="ml-2 text-xs">
                                <Link :href="invoiceShow(i.id)" class="underline underline-offset-4">{{ i.number }}</Link>
                                ({{ invoiceStatusLabel[i.status] ?? i.status }}, {{ formatMoney(i.total_minor, i.currency) }})
                            </span>
                        </span>
                        <span class="flex gap-1">
                            <Button v-if="p.status === 'open'" size="sm" variant="outline" @click="post(lock.url(p.id), 'Lock this period? Time inside it can no longer change.')">Lock</Button>
                            <Button v-if="p.status === 'locked'" size="sm" variant="ghost" @click="post(unlock.url(p.id), 'Unlock? Any draft invoice for this period is deleted.')">Unlock</Button>
                            <Button v-if="p.status !== 'invoiced'" size="sm" @click="post(invoicePeriod.url(p.id))">
                                {{ p.invoices.some((i) => i.status === 'draft') ? 'Open draft' : 'Create invoice' }}
                            </Button>
                        </span>
                    </li>
                </ul>
                <p v-else class="text-sm text-muted-foreground">No periods yet.</p>

                <form class="flex flex-wrap items-end gap-2 text-sm" @submit.prevent="periodForm.post(storePeriod.url(workspace.slug), { ...opts, onSuccess: () => periodForm.reset() })">
                    <Button type="button" size="sm" variant="outline" @click="post(storePeriod.url(workspace.slug))">Add next period</Button>
                    <span class="text-xs text-muted-foreground">or custom:</span>
                    <Input v-model="periodForm.starts_on" type="date" class="w-40" aria-label="Starts on" />
                    <Input v-model="periodForm.ends_on" type="date" class="w-40" aria-label="Ends on" />
                    <Button type="submit" size="sm" variant="ghost" :disabled="!periodForm.starts_on || !periodForm.ends_on">Add</Button>
                    <InputError :message="periodForm.errors.ends_on" />
                </form>
            </CardContent>
        </Card>

        <div class="grid gap-4 lg:grid-cols-2">
            <Card>
                <CardHeader>
                    <CardTitle class="text-base">Billing profile</CardTitle>
                    <CardDescription>{{ profile.configured ? 'Saved' : 'Not set up yet - defaults shown' }}</CardDescription>
                </CardHeader>
                <CardContent>
                    <form class="grid gap-3 text-sm sm:grid-cols-2" @submit.prevent="form.put(profileRoute.url(workspace.slug), opts)">
                        <div class="grid gap-1">
                            <Label>Currency</Label>
                            <NativeSelect v-model="form.currency">
                                <option v-for="c in options.currencies" :key="c" :value="c">{{ c }}</option>
                            </NativeSelect>
                        </div>
                        <div class="grid gap-1">
                            <Label>Billing cycle</Label>
                            <NativeSelect v-model="form.cycle">
                                <option v-for="(label, key) in options.cycles" :key="key" :value="key">{{ label }}</option>
                            </NativeSelect>
                        </div>
                        <div class="grid gap-1">
                            <Label>Monthly retainer</Label>
                            <Input v-model="form.retainer" inputmode="decimal" />
                            <InputError :message="form.errors.retainer" />
                        </div>
                        <div class="grid gap-1">
                            <Label>Hours included in retainer</Label>
                            <Input v-model="form.included_hours" inputmode="decimal" />
                            <InputError :message="form.errors.included_hours" />
                        </div>
                        <div class="grid gap-1 sm:col-span-2">
                            <Label>Hours over the retainer</Label>
                            <NativeSelect v-model="form.overage_rule">
                                <option v-for="(label, key) in options.overageRules" :key="key" :value="key">{{ label }}</option>
                            </NativeSelect>
                        </div>
                        <div class="grid gap-1">
                            <Label>Payment terms (days)</Label>
                            <Input v-model="form.terms_days" type="number" min="0" max="180" />
                        </div>
                        <div class="grid gap-1">
                            <Label>Tax / VAT %</Label>
                            <Input v-model="form.tax_rate" inputmode="decimal" />
                            <InputError :message="form.errors.tax_rate" />
                        </div>
                        <div class="grid gap-1">
                            <Label>Invoice series</Label>
                            <Input v-model="form.invoice_series" />
                            <InputError :message="form.errors.invoice_series" />
                        </div>
                        <div class="grid gap-1">
                            <Label>Bill to (name)</Label>
                            <Input v-model="form.bill_to.name" />
                            <InputError :message="form.errors['bill_to.name']" />
                        </div>
                        <div class="grid gap-1 sm:col-span-2">
                            <Label>Address</Label>
                            <textarea v-model="form.bill_to.address" rows="2" class="rounded-md border bg-transparent p-2" />
                        </div>
                        <div class="grid gap-1">
                            <Label>TIN / tax ID</Label>
                            <Input v-model="form.bill_to.tax_id" />
                        </div>
                        <div class="grid gap-1">
                            <Label>Attention</Label>
                            <Input v-model="form.bill_to.contact" />
                        </div>
                        <div class="grid gap-1 sm:col-span-2">
                            <Label>Payment instructions (on the PDF)</Label>
                            <textarea v-model="form.payment_instructions" rows="3" class="rounded-md border bg-transparent p-2" placeholder="Bank, account name/number, GCash / Maya number..." />
                        </div>
                        <div class="sm:col-span-2">
                            <Button type="submit" size="sm" :disabled="form.processing">Save profile</Button>
                        </div>
                    </form>
                </CardContent>
            </Card>

            <div class="flex flex-col gap-4">
                <Card>
                    <CardHeader>
                        <CardTitle class="text-base">Rates for this client</CardTitle>
                        <CardDescription>Override the agency rates for this client only.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <RateTable :rates="rates" :workspace-id="workspace.id" :currency="form.currency" :departments="departments" :people="members" />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle class="text-base">Fixed fees</CardTitle>
                        <CardDescription>Billed once, on the first invoice after the project is completed or due.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <p v-if="projects.length === 0" class="text-sm text-muted-foreground">No projects.</p>
                        <ul v-else class="divide-y text-sm">
                            <li v-for="p in projects" :key="p.id" class="flex items-center justify-between gap-2 py-1.5">
                                <span class="min-w-0 truncate">{{ p.name }} <span class="text-xs text-muted-foreground">· {{ p.status }}</span></span>
                                <span v-if="p.billed" class="text-xs text-muted-foreground">Billed {{ p.fixed_fee }}</span>
                                <span v-else class="flex items-center gap-1">
                                    <Input v-model="fees[String(p.id)]" inputmode="decimal" placeholder="No fixed fee" class="h-8 w-32" :aria-label="`Fixed fee for ${p.name}`" />
                                    <Button size="sm" variant="ghost" @click="saveFee(p.id)">Save</Button>
                                </span>
                            </li>
                        </ul>
                    </CardContent>
                </Card>
            </div>
        </div>

        <Card>
            <CardHeader>
                <CardTitle class="text-base">Expenses</CardTitle>
                <CardDescription>Billable expenses go on the next invoice up to their date.</CardDescription>
            </CardHeader>
            <CardContent class="space-y-3">
                <form class="grid gap-2 text-sm sm:grid-cols-5" @submit.prevent="expenseForm.post(storeExpense.url(workspace.slug), { ...opts, onSuccess: () => expenseForm.reset('description', 'amount') })">
                    <Input v-model="expenseForm.description" placeholder="Description" class="sm:col-span-2" aria-label="Description" />
                    <Input v-model="expenseForm.amount" inputmode="decimal" placeholder="Amount" aria-label="Amount" />
                    <Input v-model="expenseForm.incurred_on" type="date" aria-label="Date" />
                    <Button size="sm" type="submit" :disabled="expenseForm.processing">Add expense</Button>
                    <InputError class="sm:col-span-5" :message="expenseForm.errors.description ?? expenseForm.errors.amount" />
                </form>
                <ul v-if="expenses.length" class="divide-y text-sm">
                    <li v-for="e in expenses" :key="e.id" class="flex items-center justify-between gap-2 py-1.5">
                        <span>{{ formatDate(e.incurred_on) }} · {{ e.description }}<span v-if="!e.is_billable" class="text-xs text-muted-foreground"> · not billable</span></span>
                        <span class="flex items-center gap-2">
                            <span class="tabular-nums">{{ formatMoney(e.amount_minor, form.currency) }}</span>
                            <span v-if="e.billed" class="text-xs text-muted-foreground">billed</span>
                            <Button v-else variant="ghost" size="icon" aria-label="Remove expense" @click="router.delete(destroyExpense.url(e.id), opts)"><Trash2 class="size-4" /></Button>
                        </span>
                    </li>
                </ul>
            </CardContent>
        </Card>
    </div>
</template>
