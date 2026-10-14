<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ChevronDown, ChevronRight } from '@lucide/vue';
import { computed, ref } from 'vue';
import BillingNav from '@/components/billing/BillingNav.vue';
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
import { confirmDialog } from '@/composables/useConfirm';
import {
    formatDate,
    formatDateTime,
    formatMinutes,
    localToday,
} from '@/lib/format';
import {
    formatMoney,
    invoiceStatusClass,
    invoiceStatusLabel,
} from '@/lib/money';
import { show as clientShow } from '@/routes/billing/clients';
import {
    credit,
    destroy,
    finalize,
    index,
    pdf,
    recalculate,
    review as requestReview,
    sendBack,
    show,
    update,
    workReport,
    cancel as voidInvoice,
} from '@/routes/billing/invoices';
import { destroy as destroyLine } from '@/routes/billing/invoice-lines';
import { store as storeDiscount } from '@/routes/billing/invoices/discounts';
import { store as storeLine } from '@/routes/billing/invoices/lines';
import { store as storePayment } from '@/routes/billing/invoices/payments';
import { reverse as reversePaymentRoute } from '@/routes/billing/payments';
import type { InvoiceDetail } from '@/types/billing';

const props = defineProps<{
    invoice: InvoiceDetail;
    methods: Record<string, string>;
    groupings: Record<string, string>;
    currencies: string[];
    backdateDays: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Billing', href: index() }],
    },
});

const page = usePage();
const billingError = computed(
    () => (page.props.errors as Record<string, string> | undefined)?.billing,
);

const inv = computed(() => props.invoice);
const isOpen = computed(() =>
    ['finalized', 'partially_paid'].includes(inv.value.status),
);
const canCredit = computed(
    () =>
        inv.value.kind === 'invoice' &&
        ['finalized', 'partially_paid', 'paid'].includes(inv.value.status),
);
const canVoid = computed(
    () =>
        isOpen.value &&
        inv.value.paid_minor === 0 &&
        inv.value.credited_minor === 0,
);
const money = (minor: number | null | undefined) =>
    formatMoney(minor, inv.value.currency);

const expanded = ref<Record<number, boolean>>({});
const opts = { preserveScroll: true };

const notes = useForm({
    notes: props.invoice.notes ?? '',
    service_label: props.invoice.service_label ?? '',
});
const payment = useForm({
    amount: '',
    method: 'bank',
    reference: '',
    received_on: localToday(),
    note: '',
    fee: '',
    withholding: '',
    received_amount: '',
    received_currency: props.invoice.currency,
});

// E-63: Finance's own lines and discounts on a draft.
const lineForm = useForm({
    description: '',
    quantity: '1',
    unit_price: '',
    taxable: true,
    reason: '',
});
const discountForm = useForm({
    kind: 'percent',
    value: '',
    applies_to_item_id: '' as string | number,
    reason: '',
});
const discountable = computed(() =>
    inv.value.items.filter((i) => i.type !== 'discount'),
);
async function removeLine(id: number): Promise<void> {
    if (await confirmDialog('Remove this line from the draft?')) {
        router.delete(destroyLine.url(id), opts);
    }
}

// E-66 / E-67: review before finalize; issue date within the allowed window.
const issueDate = ref(localToday());
const minIssueDate = computed(() => {
    const d = new Date(`${localToday()}T00:00:00`);
    d.setDate(d.getDate() - props.backdateDays);
    return d.toISOString().slice(0, 10);
});
const sendBackForm = useForm({ note: '' });
const showSendBack = ref(false);
async function doFinalize(): Promise<void> {
    if (
        await confirmDialog(
            'Finalize? The invoice gets a number and can no longer be changed.',
        )
    ) {
        router.post(
            finalize.url(inv.value.id),
            { issue_date: issueDate.value },
            opts,
        );
    }
}

// E-68: reverse a payment with a reason.
const reverseFor = ref<number | null>(null);
const reverseForm = useForm({ reason: '' });
const creditForm = useForm({ amount: '', reason: '' });
const voidForm = useForm({ reason: '' });

async function post(url: string, confirmText?: string): Promise<void> {
    if (confirmText && !(await confirmDialog(confirmText))) {
        return;
    }
    router.post(url, {}, opts);
}

function regroup(grouping: string | number | null | undefined): void {
    router.post(
        recalculate.url(inv.value.id),
        { grouping: String(grouping ?? 'department') },
        opts,
    );
}

async function removeDraft(): Promise<void> {
    if (await confirmDialog('Delete this draft? Its time stays unbilled.')) {
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
                    {{ inv.kind === 'credit_note' ? 'Credit note' : 'Invoice' }}
                    {{ inv.number }}
                    <span
                        class="rounded-full px-2 py-0.5 text-xs font-medium"
                        :class="invoiceStatusClass[inv.status]"
                        >{{
                            invoiceStatusLabel[inv.status] ?? inv.status
                        }}</span
                    >
                </h1>
                <p class="text-sm text-muted-foreground">
                    <Link
                        v-if="inv.workspace"
                        :href="clientShow(inv.workspace.slug)"
                        class="underline underline-offset-4"
                        >{{ inv.workspace.name }}</Link
                    >
                    <template v-if="inv.project">
                        · only {{ inv.project.name }}</template
                    >
                    · {{ formatDate(inv.period_start) }} –
                    {{ formatDate(inv.period_end) }}
                    <template v-if="inv.issue_date">
                        · issued {{ formatDate(inv.issue_date) }} · due
                        {{ formatDate(inv.due_date) }}</template
                    >
                </p>
                <p v-if="inv.credited_invoice_id" class="text-sm">
                    Credits
                    <Link
                        :href="show(inv.credited_invoice_id)"
                        class="underline underline-offset-4"
                        >invoice #{{ inv.credited_invoice_id }}</Link
                    >
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button variant="outline" size="sm" as-child>
                    <a :href="pdf.url(inv.id)">{{
                        inv.status === 'draft' ? 'Preview PDF' : 'Download PDF'
                    }}</a>
                </Button>
                <Button
                    v-if="inv.kind === 'invoice'"
                    variant="outline"
                    size="sm"
                    as-child
                >
                    <a :href="workReport.url(inv.id)">Work report PDF</a>
                </Button>
            </div>
            <div v-if="inv.status === 'draft'" class="flex flex-wrap gap-2">
                <label
                    class="flex items-center gap-1.5 text-xs text-muted-foreground"
                >
                    Lines by
                    <NativeSelect
                        :model-value="inv.line_grouping"
                        class="h-8 w-44 text-xs"
                        aria-label="Group hour lines by"
                        @update:model-value="regroup"
                    >
                        <option
                            v-for="(label, key) in groupings"
                            :key="key"
                            :value="key"
                        >
                            {{ label }}
                        </option>
                    </NativeSelect>
                </label>
                <Button
                    variant="outline"
                    size="sm"
                    @click="post(recalculate.url(inv.id))"
                    >Recalculate</Button
                >
                <Button
                    v-if="inv.review.required && !inv.review.requested_at"
                    size="sm"
                    :disabled="(inv.missing_rates?.length ?? 0) > 0"
                    @click="post(requestReview.url(inv.id))"
                    >Send for review</Button
                >
                <Button
                    v-if="
                        inv.review.requested_at &&
                        !inv.review.mine &&
                        inv.review.required
                    "
                    variant="outline"
                    size="sm"
                    @click="showSendBack = !showSendBack"
                    >Send back</Button
                >
                <template v-if="inv.review.can_finalize">
                    <label
                        class="flex items-center gap-1.5 text-xs text-muted-foreground"
                    >
                        Issue date
                        <Input
                            v-model="issueDate"
                            type="date"
                            class="h-8 w-36 text-xs"
                            :min="minIssueDate"
                            :max="localToday()"
                            aria-label="Issue date"
                        />
                    </label>
                    <Button
                        size="sm"
                        :disabled="(inv.missing_rates?.length ?? 0) > 0"
                        :title="
                            (inv.missing_rates?.length ?? 0) > 0
                                ? 'Add the missing rates first'
                                : ''
                        "
                        @click="doFinalize"
                        >Finalize</Button
                    >
                </template>
                <Button variant="ghost" size="sm" @click="removeDraft"
                    >Delete draft</Button
                >
            </div>
        </div>

        <div
            v-if="inv.status === 'draft' && inv.review.required"
            class="rounded-md border bg-muted/40 p-3 text-sm"
            role="status"
        >
            <template v-if="inv.review.requested_at">
                <p>
                    <span class="font-medium">Waiting for review.</span>
                    Sent by {{ inv.review.requested_by }}
                    {{ formatDateTime(inv.review.requested_at) }}.
                    <template v-if="inv.review.mine"
                        >Someone else in Finance finalizes it. Any change to the
                        draft cancels the request.</template
                    >
                </p>
            </template>
            <p v-else>
                This invoice needs a second person in Finance to check it. Press
                <span class="font-medium">Send for review</span> when the draft
                is ready.
            </p>
            <p
                v-if="inv.review.note"
                class="mt-1 text-amber-800 dark:text-amber-200"
            >
                Sent back: {{ inv.review.note }}
            </p>
            <form
                v-if="showSendBack"
                class="mt-2 flex flex-wrap gap-2"
                @submit.prevent="
                    sendBackForm.post(sendBack.url(inv.id), {
                        ...opts,
                        onSuccess: () => {
                            sendBackForm.reset();
                            showSendBack = false;
                        },
                    })
                "
            >
                <Input
                    v-model="sendBackForm.note"
                    class="min-w-0 flex-1"
                    placeholder="What needs fixing?"
                    aria-label="Send-back note"
                />
                <Button
                    size="sm"
                    type="submit"
                    :disabled="!sendBackForm.note.trim()"
                    >Send back</Button
                >
            </form>
        </div>

        <div
            v-if="inv.missing_rates?.length"
            class="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200"
            role="alert"
        >
            <p class="font-medium">
                Some hours have no {{ inv.currency }} rate yet, so they are not
                on this draft.
            </p>
            <ul class="mt-1 list-disc pl-5">
                <li v-for="m in inv.missing_rates" :key="`${m.user_id}`">
                    {{ m.name }}: {{ formatMinutes(m.minutes) }} ({{
                        formatDate(m.first)
                    }}<template v-if="m.last !== m.first">
                        – {{ formatDate(m.last) }}</template
                    >)
                </li>
            </ul>
            <p class="mt-2">
                Add {{ inv.currency }} rates for them
                <Link
                    v-if="inv.workspace"
                    :href="clientShow(inv.workspace.slug)"
                    class="underline"
                    >on the client's billing page</Link
                >
                (or as agency rates), then press Recalculate. Finalize stays off
                until every hour is priced.
            </p>
        </div>

        <p
            v-if="billingError"
            class="rounded-md border border-rose-300 bg-rose-50 p-3 text-sm text-rose-800 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-200"
        >
            {{ billingError }}
        </p>

        <div class="grid gap-4 lg:grid-cols-3">
            <Card class="lg:col-span-2">
                <CardHeader>
                    <CardTitle class="text-base">Lines</CardTitle>
                    <CardDescription
                        >Open a line to see the time entries, expenses or
                        projects it bills.</CardDescription
                    >
                </CardHeader>
                <CardContent class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-left text-xs text-muted-foreground">
                            <tr>
                                <th class="py-2 font-medium">Description</th>
                                <th class="py-2 text-right font-medium">Qty</th>
                                <th class="py-2 text-right font-medium">
                                    Rate
                                </th>
                                <th class="py-2 text-right font-medium">
                                    Amount
                                </th>
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
                                            @click="
                                                expanded[line.id] =
                                                    !expanded[line.id]
                                            "
                                        >
                                            <component
                                                :is="
                                                    expanded[line.id]
                                                        ? ChevronDown
                                                        : ChevronRight
                                                "
                                                class="size-3.5"
                                            />
                                            {{ line.description }}
                                        </button>
                                        <span v-else>{{
                                            line.description
                                        }}</span>
                                        <span
                                            v-if="!line.taxable"
                                            class="ml-1 rounded bg-muted px-1 text-xs text-muted-foreground"
                                            >no tax</span
                                        >
                                        <span
                                            v-if="
                                                line.reason &&
                                                line.type !== 'discount'
                                            "
                                            class="ml-1 text-xs text-muted-foreground"
                                            >· {{ line.reason }}</span
                                        >
                                        <button
                                            v-if="
                                                line.manual &&
                                                inv.status === 'draft'
                                            "
                                            type="button"
                                            class="ml-2 text-xs text-rose-600 hover:underline"
                                            :aria-label="`Remove ${line.description}`"
                                            @click="removeLine(line.id)"
                                        >
                                            Remove
                                        </button>
                                        <span
                                            v-if="line.sources.length"
                                            class="ml-1 text-xs text-muted-foreground"
                                            >({{ line.sources.length }} source{{
                                                line.sources.length === 1
                                                    ? ''
                                                    : 's'
                                            }})</span
                                        >
                                    </td>
                                    <td class="py-2 text-right tabular-nums">
                                        <template
                                            v-if="line.type !== 'discount'"
                                            >{{
                                                line.minutes !== null
                                                    ? `${line.quantity} h`
                                                    : line.quantity
                                            }}</template
                                        >
                                    </td>
                                    <td class="py-2 text-right tabular-nums">
                                        <template
                                            v-if="line.type !== 'discount'"
                                            >{{
                                                money(line.unit_minor)
                                            }}</template
                                        >
                                    </td>
                                    <td class="py-2 text-right tabular-nums">
                                        {{ money(line.amount_minor) }}
                                    </td>
                                </tr>
                                <tr v-if="expanded[line.id]">
                                    <td colspan="4" class="pb-3">
                                        <ul
                                            class="ml-5 divide-y rounded border bg-muted/30 text-xs"
                                        >
                                            <li
                                                v-for="s in line.sources"
                                                :key="s.id"
                                                class="flex flex-wrap items-center justify-between gap-2 px-2 py-1.5"
                                            >
                                                <span class="min-w-0">
                                                    <span
                                                        v-if="s.date"
                                                        class="text-muted-foreground"
                                                        >{{
                                                            formatDate(s.date)
                                                        }}
                                                        ·
                                                    </span>
                                                    <span
                                                        v-if="s.person"
                                                        class="font-medium"
                                                        >{{ s.person }} ·
                                                    </span>
                                                    <Link
                                                        v-if="
                                                            s.task && s.task_url
                                                        "
                                                        :href="s.task_url"
                                                        class="underline underline-offset-4"
                                                        >{{ s.task }}</Link
                                                    >
                                                    <span v-else-if="s.task">{{
                                                        s.task
                                                    }}</span>
                                                    <span
                                                        v-if="s.note"
                                                        class="text-muted-foreground"
                                                    >
                                                        · {{ s.note }}</span
                                                    >
                                                </span>
                                                <span
                                                    class="shrink-0 tabular-nums"
                                                >
                                                    <template
                                                        v-if="
                                                            s.minutes !== null
                                                        "
                                                        >{{
                                                            formatMinutes(
                                                                s.minutes,
                                                            )
                                                        }}</template
                                                    >
                                                    <template
                                                        v-if="s.amount_minor"
                                                    >
                                                        ·
                                                        {{
                                                            money(
                                                                s.amount_minor,
                                                            )
                                                        }}</template
                                                    >
                                                    <template
                                                        v-else-if="
                                                            s.minutes !== null
                                                        "
                                                    >
                                                        · covered</template
                                                    >
                                                </span>
                                            </li>
                                        </ul>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                        <tfoot class="border-t text-sm">
                            <tr>
                                <td
                                    colspan="3"
                                    class="py-1 text-right text-muted-foreground"
                                >
                                    Subtotal
                                </td>
                                <td class="py-1 text-right tabular-nums">
                                    {{ money(inv.subtotal_minor) }}
                                </td>
                            </tr>
                            <tr>
                                <td
                                    colspan="3"
                                    class="py-1 text-right text-muted-foreground"
                                >
                                    Tax ({{
                                        (inv.tax_rate_bp / 100).toFixed(2)
                                    }}%)
                                </td>
                                <td class="py-1 text-right tabular-nums">
                                    {{ money(inv.tax_minor) }}
                                </td>
                            </tr>
                            <tr class="font-semibold">
                                <td colspan="3" class="py-1 text-right">
                                    Total
                                </td>
                                <td class="py-1 text-right tabular-nums">
                                    {{ money(inv.total_minor) }}
                                </td>
                            </tr>
                            <tr v-if="inv.paid_minor || inv.credited_minor">
                                <td
                                    colspan="3"
                                    class="py-1 text-right text-muted-foreground"
                                >
                                    Paid / credited
                                </td>
                                <td class="py-1 text-right tabular-nums">
                                    -{{
                                        money(
                                            inv.paid_minor + inv.credited_minor,
                                        )
                                    }}
                                </td>
                            </tr>
                            <tr v-if="isOpen" class="font-semibold">
                                <td colspan="3" class="py-1 text-right">
                                    Balance due
                                </td>
                                <td class="py-1 text-right tabular-nums">
                                    {{ money(inv.balance_minor) }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                    <p
                        v-if="
                            inv.items.length === 0 && inv.missing_rates?.length
                        "
                        class="py-6 text-center text-sm text-muted-foreground"
                    >
                        No lines yet: the hours above need a rate first.
                    </p>
                    <p
                        v-else-if="inv.items.length === 0"
                        class="py-6 text-center text-sm text-muted-foreground"
                    >
                        Nothing to bill: no approved billable time, retainer,
                        fixed fees or expenses in this period.
                    </p>
                </CardContent>
            </Card>

            <div class="flex flex-col gap-4">
                <Card>
                    <CardHeader
                        ><CardTitle class="text-base"
                            >Bill to</CardTitle
                        ></CardHeader
                    >
                    <CardContent class="space-y-0.5 text-sm">
                        <template v-if="inv.bill_to">
                            <p class="font-medium">{{ inv.bill_to.name }}</p>
                            <p
                                v-if="inv.bill_to.address"
                                class="whitespace-pre-line text-muted-foreground"
                            >
                                {{ inv.bill_to.address }}
                            </p>
                            <p
                                v-if="inv.bill_to.tax_id"
                                class="text-muted-foreground"
                            >
                                TIN {{ inv.bill_to.tax_id }}
                            </p>
                            <p
                                v-if="inv.bill_to.contact"
                                class="text-muted-foreground"
                            >
                                Attn: {{ inv.bill_to.contact }}
                            </p>
                        </template>
                        <p v-else class="text-muted-foreground">
                            Set when finalized, from the client's billing
                            profile.
                        </p>
                    </CardContent>
                </Card>

                <Card v-if="inv.status === 'draft'">
                    <CardHeader
                        ><CardTitle class="text-base"
                            >Label and notes</CardTitle
                        ></CardHeader
                    >
                    <CardContent>
                        <form
                            class="space-y-2"
                            @submit.prevent="
                                notes.put(update.url(inv.id), opts)
                            "
                        >
                            <Input
                                v-model="notes.service_label"
                                maxlength="120"
                                placeholder="Service period label, e.g. October 2026 retainer"
                                aria-label="Service period label"
                            />
                            <textarea
                                v-model="notes.notes"
                                rows="3"
                                class="w-full rounded-md border bg-transparent p-2 text-sm"
                                aria-label="Notes"
                            />
                            <Button
                                size="sm"
                                type="submit"
                                :disabled="notes.processing"
                                >Save</Button
                            >
                        </form>
                    </CardContent>
                </Card>

                <Card v-if="inv.status === 'draft'">
                    <CardHeader>
                        <CardTitle class="text-base"
                            >Adjust the draft</CardTitle
                        >
                        <CardDescription
                            >Lines and discounts you add stay when the draft is
                            recalculated.</CardDescription
                        >
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <form
                            class="grid gap-2 text-sm"
                            @submit.prevent="
                                lineForm.post(storeLine.url(inv.id), {
                                    ...opts,
                                    onSuccess: () => lineForm.reset(),
                                })
                            "
                        >
                            <p class="font-medium">Add a line</p>
                            <Input
                                v-model="lineForm.description"
                                placeholder="Description, e.g. Rush fee"
                                aria-label="Line description"
                            />
                            <InputError
                                :message="lineForm.errors.description"
                            />
                            <div class="flex gap-2">
                                <Input
                                    v-model="lineForm.quantity"
                                    inputmode="decimal"
                                    class="w-20"
                                    aria-label="Quantity"
                                />
                                <Input
                                    v-model="lineForm.unit_price"
                                    inputmode="decimal"
                                    placeholder="Unit price (minus for a credit)"
                                    aria-label="Unit price"
                                />
                            </div>
                            <InputError
                                :message="
                                    lineForm.errors.quantity ??
                                    lineForm.errors.unit_price
                                "
                            />
                            <label class="flex items-center gap-2 text-xs">
                                <input
                                    v-model="lineForm.taxable"
                                    type="checkbox"
                                    class="size-4"
                                />
                                Taxable
                            </label>
                            <Input
                                v-model="lineForm.reason"
                                placeholder="Reason (optional)"
                                aria-label="Line reason"
                            />
                            <Button
                                size="sm"
                                variant="outline"
                                type="submit"
                                :disabled="lineForm.processing"
                                >Add line</Button
                            >
                        </form>
                        <form
                            class="grid gap-2 border-t pt-3 text-sm"
                            @submit.prevent="
                                discountForm.post(storeDiscount.url(inv.id), {
                                    ...opts,
                                    onSuccess: () => discountForm.reset(),
                                })
                            "
                        >
                            <p class="font-medium">Add a discount</p>
                            <div class="flex gap-2">
                                <NativeSelect
                                    v-model="discountForm.kind"
                                    class="w-28"
                                    aria-label="Discount type"
                                >
                                    <option value="percent">%</option>
                                    <option value="amount">
                                        {{ inv.currency }}
                                    </option>
                                </NativeSelect>
                                <Input
                                    v-model="discountForm.value"
                                    inputmode="decimal"
                                    :placeholder="
                                        discountForm.kind === 'percent'
                                            ? 'e.g. 10'
                                            : 'e.g. 5000.00'
                                    "
                                    aria-label="Discount value"
                                />
                            </div>
                            <NativeSelect
                                v-model="discountForm.applies_to_item_id"
                                aria-label="Discount applies to"
                            >
                                <option value="">Whole invoice</option>
                                <option
                                    v-for="l in discountable"
                                    :key="l.id"
                                    :value="l.id"
                                >
                                    {{ l.description }}
                                </option>
                            </NativeSelect>
                            <Input
                                v-model="discountForm.reason"
                                placeholder="Reason, e.g. Loyalty discount"
                                aria-label="Discount reason"
                            />
                            <InputError
                                :message="
                                    discountForm.errors.value ??
                                    discountForm.errors.reason
                                "
                            />
                            <Button
                                size="sm"
                                variant="outline"
                                type="submit"
                                :disabled="discountForm.processing"
                                >Add discount</Button
                            >
                        </form>
                        <p
                            v-if="inv.client_credit_minor > 0"
                            class="border-t pt-3 text-xs text-muted-foreground"
                        >
                            This client has
                            {{ money(inv.client_credit_minor) }} credit. It is
                            applied automatically when the invoice is finalized.
                        </p>
                    </CardContent>
                </Card>

                <Card v-if="isOpen && inv.kind === 'invoice'">
                    <CardHeader>
                        <CardTitle class="text-base"
                            >Record a payment</CardTitle
                        >
                        <CardDescription
                            >Balance
                            {{ money(inv.balance_minor) }}</CardDescription
                        >
                    </CardHeader>
                    <CardContent>
                        <form
                            class="grid gap-2 text-sm"
                            @submit.prevent="
                                payment.post(storePayment.url(inv.id), {
                                    ...opts,
                                    onSuccess: () => payment.reset(),
                                })
                            "
                        >
                            <Input
                                v-model="payment.amount"
                                inputmode="decimal"
                                placeholder="Amount e.g. 12500.00"
                                aria-label="Amount"
                            />
                            <InputError :message="payment.errors.amount" />
                            <NativeSelect
                                v-model="payment.method"
                                aria-label="Method"
                            >
                                <option
                                    v-for="(label, key) in methods"
                                    :key="key"
                                    :value="key"
                                >
                                    {{ label }}
                                </option>
                            </NativeSelect>
                            <Input
                                v-model="payment.reference"
                                placeholder="Reference no."
                                aria-label="Reference"
                            />
                            <Input
                                v-model="payment.received_on"
                                type="date"
                                aria-label="Received on"
                            />
                            <InputError :message="payment.errors.received_on" />
                            <details class="rounded border px-2 py-1">
                                <summary class="cursor-pointer text-xs">
                                    Bank fees, tax withheld, amount received
                                </summary>
                                <div class="mt-2 grid gap-2">
                                    <p class="text-xs text-muted-foreground">
                                        The amount above is what the client paid
                                        toward the invoice. Fees and tax
                                        withheld are part of it.
                                    </p>
                                    <Input
                                        v-model="payment.fee"
                                        inputmode="decimal"
                                        placeholder="Bank / transfer fees"
                                        aria-label="Fees"
                                    />
                                    <Input
                                        v-model="payment.withholding"
                                        inputmode="decimal"
                                        placeholder="Tax withheld (BIR 2307)"
                                        aria-label="Tax withheld"
                                    />
                                    <div class="flex gap-2">
                                        <Input
                                            v-model="payment.received_amount"
                                            inputmode="decimal"
                                            placeholder="Amount that reached the bank"
                                            aria-label="Amount received"
                                        />
                                        <NativeSelect
                                            v-model="payment.received_currency"
                                            class="w-24"
                                            aria-label="Received currency"
                                        >
                                            <option
                                                v-for="c in currencies"
                                                :key="c"
                                                :value="c"
                                            >
                                                {{ c }}
                                            </option>
                                        </NativeSelect>
                                    </div>
                                </div>
                            </details>
                            <p class="text-xs text-muted-foreground">
                                Paid more than the balance? The extra is kept as
                                client credit for the next invoice.
                            </p>
                            <Button
                                size="sm"
                                type="submit"
                                :disabled="payment.processing"
                                >Record payment</Button
                            >
                        </form>
                    </CardContent>
                </Card>

                <Card v-if="canCredit">
                    <CardHeader>
                        <CardTitle class="text-base">Credit note</CardTitle>
                        <CardDescription
                            >Corrections are made with a credit note; the
                            invoice itself never changes.</CardDescription
                        >
                    </CardHeader>
                    <CardContent>
                        <form
                            class="grid gap-2 text-sm"
                            @submit.prevent="
                                creditForm.post(credit.url(inv.id))
                            "
                        >
                            <Input
                                v-model="creditForm.amount"
                                inputmode="decimal"
                                placeholder="Amount before tax"
                                aria-label="Credit amount"
                            />
                            <InputError :message="creditForm.errors.amount" />
                            <Input
                                v-model="creditForm.reason"
                                placeholder="Reason"
                                aria-label="Reason"
                            />
                            <InputError :message="creditForm.errors.reason" />
                            <Button
                                size="sm"
                                variant="outline"
                                type="submit"
                                :disabled="creditForm.processing"
                                >Issue credit note</Button
                            >
                        </form>
                    </CardContent>
                </Card>

                <Card v-if="canVoid">
                    <CardHeader>
                        <CardTitle class="text-base">Void</CardTitle>
                        <CardDescription
                            >Cancels this invoice; its time becomes billable
                            again.</CardDescription
                        >
                    </CardHeader>
                    <CardContent>
                        <form
                            class="grid gap-2 text-sm"
                            @submit.prevent="
                                voidForm.post(voidInvoice.url(inv.id), opts)
                            "
                        >
                            <Input
                                v-model="voidForm.reason"
                                placeholder="Reason"
                                aria-label="Void reason"
                            />
                            <InputError :message="voidForm.errors.reason" />
                            <Button
                                size="sm"
                                variant="destructive"
                                type="submit"
                                :disabled="voidForm.processing"
                                >Void invoice</Button
                            >
                        </form>
                    </CardContent>
                </Card>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <Card>
                <CardHeader
                    ><CardTitle class="text-base"
                        >Payments &amp; credit notes</CardTitle
                    ></CardHeader
                >
                <CardContent class="text-sm">
                    <p
                        v-if="
                            inv.payments.length === 0 &&
                            inv.credit_notes.length === 0
                        "
                        class="text-muted-foreground"
                    >
                        None yet.
                    </p>
                    <ul class="divide-y">
                        <li
                            v-for="p in inv.payments"
                            :key="`p${p.id}`"
                            class="py-1.5"
                        >
                            <div class="flex justify-between gap-2">
                                <span
                                    :class="
                                        p.reversed_at
                                            ? 'line-through opacity-60'
                                            : ''
                                    "
                                    >{{ formatDate(p.received_on) }} ·
                                    {{ p.method
                                    }}<span
                                        v-if="p.reference"
                                        class="text-muted-foreground"
                                    >
                                        · {{ p.reference }}</span
                                    ></span
                                >
                                <span class="flex items-center gap-2">
                                    <span
                                        class="tabular-nums"
                                        :class="
                                            p.reversed_at
                                                ? 'line-through opacity-60'
                                                : ''
                                        "
                                        >{{ money(p.amount_minor) }}</span
                                    >
                                    <button
                                        v-if="
                                            !p.reversed_at &&
                                            inv.status !== 'void'
                                        "
                                        type="button"
                                        class="text-xs text-rose-600 hover:underline"
                                        @click="
                                            reverseFor =
                                                reverseFor === p.id
                                                    ? null
                                                    : p.id
                                        "
                                    >
                                        Reverse
                                    </button>
                                </span>
                            </div>
                            <p
                                v-if="
                                    p.fee_minor ||
                                    p.withholding_minor ||
                                    p.received_minor !== null ||
                                    p.credit_minor
                                "
                                class="text-xs text-muted-foreground"
                            >
                                <template v-if="p.fee_minor"
                                    >Fees {{ money(p.fee_minor) }} ·
                                </template>
                                <template v-if="p.withholding_minor"
                                    >Tax withheld
                                    {{ money(p.withholding_minor) }} ·
                                </template>
                                <template v-if="p.received_minor !== null"
                                    >Received
                                    {{
                                        formatMoney(
                                            p.received_minor,
                                            p.received_currency ?? inv.currency,
                                        )
                                    }}
                                    ·
                                </template>
                                <template v-if="p.credit_minor"
                                    >{{ money(p.credit_minor) }} kept as client
                                    credit</template
                                >
                            </p>
                            <p
                                v-if="p.reversed_at"
                                class="text-xs text-rose-700 dark:text-rose-300"
                            >
                                Reversed by {{ p.reversed_by }}:
                                {{ p.reversal_reason }}
                            </p>
                            <form
                                v-if="reverseFor === p.id"
                                class="mt-1 flex gap-2"
                                @submit.prevent="
                                    reverseForm.post(
                                        reversePaymentRoute.url(p.id),
                                        {
                                            ...opts,
                                            onSuccess: () => {
                                                reverseForm.reset();
                                                reverseFor = null;
                                            },
                                        },
                                    )
                                "
                            >
                                <Input
                                    v-model="reverseForm.reason"
                                    class="h-8 min-w-0 flex-1 text-xs"
                                    placeholder="Why? e.g. Check bounced"
                                    aria-label="Reversal reason"
                                />
                                <Button
                                    size="sm"
                                    variant="destructive"
                                    type="submit"
                                    :disabled="!reverseForm.reason.trim()"
                                    >Reverse</Button
                                >
                            </form>
                        </li>
                        <li
                            v-for="c in inv.credit_notes"
                            :key="`c${c.id}`"
                            class="flex justify-between gap-2 py-1.5"
                        >
                            <Link
                                :href="show(c.id)"
                                class="underline underline-offset-4"
                                >Credit note {{ c.number }}</Link
                            >
                            <span class="tabular-nums">{{
                                money(c.total_minor)
                            }}</span>
                        </li>
                    </ul>
                </CardContent>
            </Card>
            <Card>
                <CardHeader
                    ><CardTitle class="text-base"
                        >History</CardTitle
                    ></CardHeader
                >
                <CardContent>
                    <ul class="divide-y text-sm">
                        <li
                            v-for="h in inv.history"
                            :key="h.id"
                            class="flex justify-between gap-3 py-1.5"
                        >
                            <span>
                                <span class="font-medium">{{
                                    invoiceStatusLabel[h.to] ?? h.to
                                }}</span>
                                <span
                                    v-if="h.note"
                                    class="text-muted-foreground"
                                >
                                    · {{ h.note }}</span
                                >
                                <span class="text-muted-foreground">
                                    · {{ h.by ?? 'System' }}</span
                                >
                            </span>
                            <span
                                class="shrink-0 text-xs text-muted-foreground"
                                >{{ formatDateTime(h.at) }}</span
                            >
                        </li>
                    </ul>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
