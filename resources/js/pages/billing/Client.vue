<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Trash2 } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import BillingNav from '@/components/billing/BillingNav.vue';
import RateTable from '@/components/billing/RateTable.vue';
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
import { formatDate, localToday } from '@/lib/format';
import { formatMoney, invoiceStatusLabel } from '@/lib/money';
import {
    profile as profileRoute,
    rateTemplate as applyTemplateRoute,
} from '@/routes/billing/clients';
import { store as storeRate } from '@/routes/billing/rates';
import { store as storeExpense } from '@/routes/billing/clients/expenses';
import { store as storePeriod } from '@/routes/billing/clients/periods';
import { destroy as destroyExpense } from '@/routes/billing/expenses';
import {
    index as invoicesIndex,
    show as invoiceShow,
} from '@/routes/billing/invoices';
import {
    invoice as invoicePeriod,
    lock,
    unlock,
} from '@/routes/billing/periods';
import { fixedFee } from '@/routes/billing/projects';
import type { RateRow } from '@/types/billing';

type Period = {
    id: number;
    starts_on: string;
    ends_on: string;
    status: 'open' | 'locked' | 'invoiced';
    invoices: {
        id: number;
        number: string;
        status: string;
        total_minor: number;
        currency: string;
        project: string | null;
    }[];
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
    expenses: {
        id: number;
        description: string;
        amount_minor: number;
        incurred_on: string;
        is_billable: boolean;
        project: string | null;
        billed: boolean;
    }[];
    projects: {
        id: number;
        name: string;
        status: string;
        fixed_fee: string;
        billed: boolean;
    }[];
    teamRates: TeamRate[];
    templates: { id: number; name: string }[];
    appliedTemplate: { name: string | null; applied_at: string | null } | null;
    departments: { id: number; name: string }[];
    members: { id: number; name: string }[];
    options: {
        currencies: string[];
        cycles: Record<string, string>;
        overageRules: Record<string, string>;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Billing', href: invoicesIndex() }],
    },
});

type TeamRate = {
    id: number;
    name: string;
    title: string | null;
    department: string | null;
    rate_minor: number | null;
    cost_minor: number | null;
    source: string;
    effective_from: string | null;
};

const sourceLabel: Record<string, string> = {
    'client-person': 'Client rate (person)',
    'client-department': 'Client rate (department)',
    'client-default': 'Client default',
    'agency-person': 'Agency rate (person)',
    'agency-department': 'Agency rate (department)',
    'agency-default': 'Agency default',
    none: 'No rate',
};

const applyForm = useForm({
    rate_template_id: '',
    effective_from: localToday(),
});
const personForm = useForm({
    workspace_id: 0,
    user_id: 0,
    rate: '',
    cost: '',
    effective_from: localToday(),
});
const editingPerson = ref<number | null>(null);

async function applyTemplate(): Promise<void> {
    if (
        await confirmDialog(
            "Copy this template into the client's own rates from the chosen date? Earlier work keeps its old rates.",
        )
    ) {
        applyForm.post(applyTemplateRoute.url(props.workspace.slug), {
            preserveScroll: true,
        });
    }
}

function editPerson(p: TeamRate): void {
    editingPerson.value = p.id;
    personForm.rate =
        p.rate_minor !== null ? (p.rate_minor / 100).toFixed(2) : '';
    personForm.cost =
        p.cost_minor !== null ? (p.cost_minor / 100).toFixed(2) : '';
}

function savePerson(p: TeamRate): void {
    personForm.workspace_id = props.workspace.id;
    personForm.user_id = p.id;
    personForm.post(storeRate.url(), {
        preserveScroll: true,
        onSuccess: () => (editingPerson.value = null),
    });
}

const page = usePage();
const billingError = computed(
    () => (page.props.errors as Record<string, string> | undefined)?.billing,
);
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
const expenseForm = useForm({
    description: '',
    amount: '',
    incurred_on: localToday(),
    is_billable: true,
});
const fees = reactive<Record<string, string>>(
    Object.fromEntries(props.projects.map((p) => [String(p.id), p.fixed_fee])),
);

// S3-14: invoice the whole client, or one project / campaign on its own.
const invoiceScope = ref<Record<number, string>>({});

function createInvoice(p: Period): void {
    const projectId = invoiceScope.value[p.id] ?? '';
    router.post(
        invoicePeriod.url(p.id),
        { project_id: projectId ? Number(projectId) : null },
        opts,
    );
}

function scopeHasDraft(p: Period): boolean {
    const projectId = invoiceScope.value[p.id] ?? '';
    const name =
        props.projects.find((x) => String(x.id) === projectId)?.name ?? null;

    return p.invoices.some(
        (i) => i.status === 'draft' && (i.project ?? null) === name,
    );
}

async function post(url: string, confirmText?: string): Promise<void> {
    if (confirmText && !(await confirmDialog(confirmText))) {
        return;
    }
    router.post(url, {}, opts);
}

function saveFee(projectId: number): void {
    router.put(
        fixedFee.url(projectId),
        { fixed_fee: fees[String(projectId)] ?? '' },
        opts,
    );
}

// Rates are ready when everyone on the client has a rate (from any source).
const ratesReady = computed(
    () =>
        props.teamRates.length > 0 &&
        props.teamRates.every((r) => r.rate_minor !== null),
);

const statusText: Record<Period['status'], string> = {
    open: 'Open',
    locked: 'Locked',
    invoiced: 'Invoiced',
};
</script>

<template>
    <Head :title="`${workspace.name} - Billing`" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <BillingNav />
        <h1 class="text-xl font-semibold">{{ workspace.name }}</h1>
        <!-- S3-11: set up in order - profile, rates, then periods and invoices. -->
        <ol class="flex flex-wrap gap-2 text-xs" aria-label="Billing setup">
            <li
                class="rounded-full border px-2.5 py-1"
                :class="
                    profile.configured
                        ? 'border-emerald-300 bg-emerald-50 text-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200'
                        : ''
                "
            >
                1 · Profile {{ profile.configured ? '✓' : '' }}
            </li>
            <li
                class="rounded-full border px-2.5 py-1"
                :class="
                    ratesReady
                        ? 'border-emerald-300 bg-emerald-50 text-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200'
                        : ''
                "
            >
                2 · Rates {{ ratesReady ? '✓' : '' }}
            </li>
            <li
                class="rounded-full border px-2.5 py-1"
                :class="
                    periods.length
                        ? 'border-emerald-300 bg-emerald-50 text-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200'
                        : ''
                "
            >
                3 · Periods &amp; invoices {{ periods.length ? '✓' : '' }}
            </li>
        </ol>

        <p
            v-if="billingError"
            class="rounded-md border border-rose-300 bg-rose-50 p-3 text-sm text-rose-800 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-200"
        >
            {{ billingError }}
        </p>

        <div class="grid gap-4 lg:grid-cols-2">
            <Card>
                <CardHeader>
                    <CardTitle class="text-base">Billing profile</CardTitle>
                    <CardDescription>{{
                        profile.configured
                            ? 'Saved'
                            : 'Not set up yet - defaults shown'
                    }}</CardDescription>
                </CardHeader>
                <CardContent>
                    <form
                        class="grid gap-3 text-sm sm:grid-cols-2"
                        @submit.prevent="
                            form.put(profileRoute.url(workspace.slug), opts)
                        "
                    >
                        <div class="grid gap-1">
                            <Label>Currency</Label>
                            <NativeSelect v-model="form.currency">
                                <option
                                    v-for="c in options.currencies"
                                    :key="c"
                                    :value="c"
                                >
                                    {{ c }}
                                </option>
                            </NativeSelect>
                        </div>
                        <div class="grid gap-1">
                            <Label>Billing cycle</Label>
                            <NativeSelect v-model="form.cycle">
                                <option
                                    v-for="(label, key) in options.cycles"
                                    :key="key"
                                    :value="key"
                                >
                                    {{ label }}
                                </option>
                            </NativeSelect>
                        </div>
                        <div class="grid gap-1">
                            <Label>Monthly retainer</Label>
                            <Input
                                v-model="form.retainer"
                                inputmode="decimal"
                            />
                            <InputError :message="form.errors.retainer" />
                        </div>
                        <div class="grid gap-1">
                            <Label>Hours included in retainer</Label>
                            <Input
                                v-model="form.included_hours"
                                inputmode="decimal"
                            />
                            <InputError :message="form.errors.included_hours" />
                        </div>
                        <div class="grid gap-1 sm:col-span-2">
                            <Label>Hours over the retainer</Label>
                            <NativeSelect v-model="form.overage_rule">
                                <option
                                    v-for="(label, key) in options.overageRules"
                                    :key="key"
                                    :value="key"
                                >
                                    {{ label }}
                                </option>
                            </NativeSelect>
                        </div>
                        <div class="grid gap-1">
                            <Label>Payment terms (days)</Label>
                            <Input
                                v-model="form.terms_days"
                                type="number"
                                min="0"
                                max="180"
                            />
                        </div>
                        <div class="grid gap-1">
                            <Label>Tax / VAT %</Label>
                            <Input
                                v-model="form.tax_rate"
                                inputmode="decimal"
                            />
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
                            <InputError
                                :message="form.errors['bill_to.name']"
                            />
                        </div>
                        <div class="grid gap-1 sm:col-span-2">
                            <Label>Address</Label>
                            <textarea
                                v-model="form.bill_to.address"
                                rows="2"
                                class="rounded-md border bg-transparent p-2"
                            />
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
                            <textarea
                                v-model="form.payment_instructions"
                                rows="3"
                                class="rounded-md border bg-transparent p-2"
                                placeholder="Bank, account name/number, GCash / Maya number..."
                            />
                        </div>
                        <div class="sm:col-span-2">
                            <Button
                                type="submit"
                                size="sm"
                                :disabled="form.processing"
                                >Save profile</Button
                            >
                        </div>
                    </form>
                </CardContent>
            </Card>

            <div class="flex flex-col gap-4">
                <Card>
                    <CardHeader>
                        <CardTitle class="text-base"
                            >Team rates for this client</CardTitle
                        >
                        <CardDescription>
                            Each person's rate for {{ workspace.name }} today
                            and where it comes from. Changes here apply to this
                            client only.
                            <template v-if="appliedTemplate?.name">
                                Based on template "{{
                                    appliedTemplate.name
                                }}"<template v-if="appliedTemplate.applied_at">
                                    (applied
                                    {{
                                        formatDate(appliedTemplate.applied_at)
                                    }})</template
                                >.</template
                            >
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <form
                            v-if="templates.length"
                            class="flex flex-wrap items-end gap-2 text-sm"
                            @submit.prevent="applyTemplate"
                        >
                            <label class="grid gap-1">
                                <span class="text-xs text-muted-foreground"
                                    >Apply a rate template</span
                                >
                                <NativeSelect
                                    v-model="applyForm.rate_template_id"
                                    class="w-52"
                                    required
                                    aria-label="Rate template"
                                >
                                    <option value="" disabled>Choose…</option>
                                    <option
                                        v-for="t in templates"
                                        :key="t.id"
                                        :value="String(t.id)"
                                    >
                                        {{ t.name }}
                                    </option>
                                </NativeSelect>
                            </label>
                            <label class="grid gap-1">
                                <span class="text-xs text-muted-foreground"
                                    >From</span
                                >
                                <Input
                                    v-model="applyForm.effective_from"
                                    type="date"
                                    class="w-40"
                                />
                            </label>
                            <Button
                                type="submit"
                                size="sm"
                                variant="outline"
                                :disabled="
                                    !applyForm.rate_template_id ||
                                    applyForm.processing
                                "
                                >Apply</Button
                            >
                        </form>
                        <p v-else class="text-xs text-muted-foreground">
                            Create a rate template under Billing → Rates to set
                            everyone's rates at once.
                        </p>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead
                                    class="text-left text-xs text-muted-foreground"
                                >
                                    <tr>
                                        <th class="py-1.5 font-medium">
                                            Person
                                        </th>
                                        <th
                                            class="py-1.5 pl-3 text-right font-medium whitespace-nowrap"
                                        >
                                            Rate / h
                                        </th>
                                        <th class="py-1.5 pl-3 font-medium">
                                            Source
                                        </th>
                                        <th />
                                    </tr>
                                </thead>
                                <tbody class="divide-y">
                                    <tr v-for="p in teamRates" :key="p.id">
                                        <td class="py-1.5">
                                            <div class="font-medium">
                                                {{ p.name }}
                                            </div>
                                            <div
                                                class="text-xs text-muted-foreground"
                                            >
                                                {{
                                                    [p.title, p.department]
                                                        .filter(Boolean)
                                                        .join(' · ')
                                                }}
                                            </div>
                                        </td>
                                        <td
                                            class="py-1.5 pl-3 text-right whitespace-nowrap tabular-nums"
                                        >
                                            <template
                                                v-if="editingPerson === p.id"
                                            >
                                                <div
                                                    class="flex justify-end gap-1"
                                                >
                                                    <Input
                                                        v-model="
                                                            personForm.rate
                                                        "
                                                        inputmode="decimal"
                                                        class="h-8 w-24"
                                                        placeholder="Rate"
                                                        :aria-label="`Rate for ${p.name}`"
                                                    />
                                                    <Input
                                                        v-model="
                                                            personForm.cost
                                                        "
                                                        inputmode="decimal"
                                                        class="h-8 w-24"
                                                        placeholder="Cost"
                                                        :aria-label="`Cost for ${p.name}`"
                                                    />
                                                    <Input
                                                        v-model="
                                                            personForm.effective_from
                                                        "
                                                        type="date"
                                                        class="h-8 w-36"
                                                        aria-label="From"
                                                    />
                                                </div>
                                            </template>
                                            <template v-else>{{
                                                p.rate_minor !== null
                                                    ? formatMoney(
                                                          p.rate_minor,
                                                          form.currency,
                                                      )
                                                    : '—'
                                            }}</template>
                                        </td>
                                        <td class="py-1.5 pl-3 text-xs">
                                            <span
                                                :class="
                                                    p.source.startsWith(
                                                        'client',
                                                    )
                                                        ? 'font-medium text-emerald-700'
                                                        : p.source === 'none'
                                                          ? 'font-medium text-rose-600'
                                                          : 'text-muted-foreground'
                                                "
                                                >{{
                                                    sourceLabel[p.source] ??
                                                    p.source
                                                }}</span
                                            >
                                        </td>
                                        <td
                                            class="py-1.5 pl-2 text-right whitespace-nowrap"
                                        >
                                            <template
                                                v-if="editingPerson === p.id"
                                            >
                                                <Button
                                                    size="sm"
                                                    class="h-8"
                                                    :disabled="
                                                        personForm.processing ||
                                                        !personForm.rate
                                                    "
                                                    @click="savePerson(p)"
                                                    >Save</Button
                                                >
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    class="h-8"
                                                    @click="
                                                        editingPerson = null
                                                    "
                                                    >Cancel</Button
                                                >
                                            </template>
                                            <Button
                                                v-else
                                                size="sm"
                                                variant="ghost"
                                                class="h-8"
                                                @click="editPerson(p)"
                                                >Set rate</Button
                                            >
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <InputError
                            :message="
                                personForm.errors.rate ??
                                personForm.errors.effective_from ??
                                applyForm.errors.rate_template_id
                            "
                        />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle class="text-base"
                            >Rate history for this client</CardTitle
                        >
                        <CardDescription
                            >Every client-specific rate, newest first. Add
                            department or default overrides
                            here.</CardDescription
                        >
                    </CardHeader>
                    <CardContent>
                        <RateTable
                            :rates="rates"
                            :workspace-id="workspace.id"
                            :currency="form.currency"
                            :departments="departments"
                            :people="members"
                        />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle class="text-base">Fixed fees</CardTitle>
                        <CardDescription
                            >Billed once, on the first invoice after the project
                            is completed or due.</CardDescription
                        >
                    </CardHeader>
                    <CardContent>
                        <p
                            v-if="projects.length === 0"
                            class="text-sm text-muted-foreground"
                        >
                            No projects.
                        </p>
                        <ul v-else class="divide-y text-sm">
                            <li
                                v-for="p in projects"
                                :key="p.id"
                                class="flex items-center justify-between gap-2 py-1.5"
                            >
                                <span class="min-w-0 truncate"
                                    >{{ p.name }}
                                    <span class="text-xs text-muted-foreground"
                                        >· {{ p.status }}</span
                                    ></span
                                >
                                <span
                                    v-if="p.billed"
                                    class="text-xs text-muted-foreground"
                                    >Billed {{ p.fixed_fee }}</span
                                >
                                <span v-else class="flex items-center gap-1">
                                    <Input
                                        v-model="fees[String(p.id)]"
                                        inputmode="decimal"
                                        placeholder="No fixed fee"
                                        class="h-8 w-32"
                                        :aria-label="`Fixed fee for ${p.name}`"
                                    />
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        @click="saveFee(p.id)"
                                        >Save</Button
                                    >
                                </span>
                            </li>
                        </ul>
                    </CardContent>
                </Card>
            </div>
        </div>

        <Card>
            <CardHeader>
                <CardTitle class="text-base">Billing periods</CardTitle>
                <CardDescription>
                    Lock a period to freeze its time (nobody can add or change
                    time dated inside it), then create the invoice. Only
                    approved, billable time is invoiced.
                </CardDescription>
            </CardHeader>
            <CardContent class="space-y-3">
                <ul v-if="periods.length" class="divide-y text-sm">
                    <li
                        v-for="p in periods"
                        :key="p.id"
                        class="flex flex-wrap items-center justify-between gap-2 py-2"
                    >
                        <span>
                            <span class="font-medium"
                                >{{ formatDate(p.starts_on) }} –
                                {{ formatDate(p.ends_on) }}</span
                            >
                            <span class="ml-2 text-xs text-muted-foreground">{{
                                statusText[p.status]
                            }}</span>
                            <span
                                v-for="i in p.invoices"
                                :key="i.id"
                                class="ml-2 text-xs"
                            >
                                <Link
                                    :href="invoiceShow(i.id)"
                                    class="underline underline-offset-4"
                                    >{{ i.number }}</Link
                                >
                                <template v-if="i.project">
                                    · {{ i.project }}</template
                                >
                                ({{ invoiceStatusLabel[i.status] ?? i.status }},
                                {{ formatMoney(i.total_minor, i.currency) }})
                            </span>
                        </span>
                        <span class="flex gap-1">
                            <Button
                                v-if="p.status === 'open'"
                                size="sm"
                                variant="outline"
                                @click="
                                    post(
                                        lock.url(p.id),
                                        'Lock this period? Time inside it can no longer change.',
                                    )
                                "
                                >Lock</Button
                            >
                            <Button
                                v-if="p.status === 'locked'"
                                size="sm"
                                variant="ghost"
                                @click="
                                    post(
                                        unlock.url(p.id),
                                        'Unlock? Any draft invoice for this period is deleted.',
                                    )
                                "
                                >Unlock</Button
                            >
                            <template v-if="p.status !== 'invoiced'">
                                <NativeSelect
                                    v-if="projects.length"
                                    v-model="invoiceScope[p.id]"
                                    class="h-8 w-52 text-xs"
                                    :aria-label="`What to invoice for ${p.starts_on}`"
                                >
                                    <option value="">Whole client</option>
                                    <option
                                        v-for="pr in projects"
                                        :key="pr.id"
                                        :value="String(pr.id)"
                                    >
                                        Only: {{ pr.name }}
                                    </option>
                                </NativeSelect>
                                <Button size="sm" @click="createInvoice(p)">
                                    {{
                                        scopeHasDraft(p)
                                            ? 'Open draft'
                                            : 'Create invoice'
                                    }}
                                </Button>
                            </template>
                        </span>
                    </li>
                </ul>
                <p v-else class="text-sm text-muted-foreground">
                    No periods yet.
                </p>

                <form
                    class="flex flex-wrap items-end gap-2 text-sm"
                    @submit.prevent="
                        periodForm.post(storePeriod.url(workspace.slug), {
                            ...opts,
                            onSuccess: () => periodForm.reset(),
                        })
                    "
                >
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        @click="post(storePeriod.url(workspace.slug))"
                        >Add next period</Button
                    >
                    <span class="text-xs text-muted-foreground"
                        >or custom:</span
                    >
                    <Input
                        v-model="periodForm.starts_on"
                        type="date"
                        class="w-40"
                        aria-label="Starts on"
                    />
                    <Input
                        v-model="periodForm.ends_on"
                        type="date"
                        class="w-40"
                        aria-label="Ends on"
                    />
                    <Button
                        type="submit"
                        size="sm"
                        variant="ghost"
                        :disabled="!periodForm.starts_on || !periodForm.ends_on"
                        >Add</Button
                    >
                    <InputError :message="periodForm.errors.ends_on" />
                </form>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle class="text-base">Expenses</CardTitle>
                <CardDescription
                    >Billable expenses go on the next invoice up to their
                    date.</CardDescription
                >
            </CardHeader>
            <CardContent class="space-y-3">
                <form
                    class="grid gap-2 text-sm sm:grid-cols-5"
                    @submit.prevent="
                        expenseForm.post(storeExpense.url(workspace.slug), {
                            ...opts,
                            onSuccess: () =>
                                expenseForm.reset('description', 'amount'),
                        })
                    "
                >
                    <Input
                        v-model="expenseForm.description"
                        placeholder="Description"
                        class="sm:col-span-2"
                        aria-label="Description"
                    />
                    <Input
                        v-model="expenseForm.amount"
                        inputmode="decimal"
                        placeholder="Amount"
                        aria-label="Amount"
                    />
                    <Input
                        v-model="expenseForm.incurred_on"
                        type="date"
                        aria-label="Date"
                    />
                    <Button
                        size="sm"
                        type="submit"
                        :disabled="expenseForm.processing"
                        >Add expense</Button
                    >
                    <InputError
                        class="sm:col-span-5"
                        :message="
                            expenseForm.errors.description ??
                            expenseForm.errors.amount
                        "
                    />
                </form>
                <ul v-if="expenses.length" class="divide-y text-sm">
                    <li
                        v-for="e in expenses"
                        :key="e.id"
                        class="flex items-center justify-between gap-2 py-1.5"
                    >
                        <span
                            >{{ formatDate(e.incurred_on) }} · {{ e.description
                            }}<span
                                v-if="!e.is_billable"
                                class="text-xs text-muted-foreground"
                            >
                                · not billable</span
                            ></span
                        >
                        <span class="flex items-center gap-2">
                            <span class="tabular-nums">{{
                                formatMoney(e.amount_minor, form.currency)
                            }}</span>
                            <span
                                v-if="e.billed"
                                class="text-xs text-muted-foreground"
                                >billed</span
                            >
                            <Button
                                v-else
                                variant="ghost"
                                size="icon"
                                aria-label="Remove expense"
                                @click="
                                    router.delete(
                                        destroyExpense.url(e.id),
                                        opts,
                                    )
                                "
                                ><Trash2 class="size-4"
                            /></Button>
                        </span>
                    </li>
                </ul>
            </CardContent>
        </Card>
    </div>
</template>
