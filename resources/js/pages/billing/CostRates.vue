<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import BillingNav from '@/components/billing/BillingNav.vue';
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
import { formatDate } from '@/lib/format';
import { formatMoney } from '@/lib/money';
import { store } from '@/routes/billing/cost-rates';
import { index as invoicesIndex } from '@/routes/billing/invoices';

/**
 * E-77: what each person costs the agency per hour (PHP), from a date. Finance only;
 * kept apart from the bill rates clients see. Feeds profitability and the people report.
 */
type Person = {
    id: number;
    name: string;
    title: string | null;
    department: string | null;
    active: boolean;
    current_minor: number | null;
    history: {
        id: number;
        cost_minor: number;
        from: string;
        note: string | null;
    }[];
};

const props = defineProps<{
    people: Person[];
    canEdit: boolean;
    today: string;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Billing', href: invoicesIndex() }] },
});

const search = ref('');
const shown = computed(() =>
    props.people.filter((p) =>
        `${p.name} ${p.department ?? ''}`
            .toLowerCase()
            .includes(search.value.toLowerCase()),
    ),
);
const missing = computed(
    () =>
        props.people.filter((p) => p.active && p.current_minor === null).length,
);

const editing = ref<number | null>(null);
const form = useForm({
    user_id: 0,
    cost: '',
    effective_from: props.today,
    note: '',
});

function edit(p: Person): void {
    editing.value = p.id;
    form.clearErrors();
    form.user_id = p.id;
    form.cost =
        p.current_minor !== null ? (p.current_minor / 100).toFixed(2) : '';
    form.effective_from = props.today;
    form.note = '';
}

function save(): void {
    form.post(store.url(), {
        preserveScroll: true,
        onSuccess: () => (editing.value = null),
    });
}
</script>

<template>
    <Head title="Cost rates" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <Heading
            title="Billing"
            description="Numbers come straight from invoices and the time entries behind them."
        />
        <BillingNav />

        <Card>
            <CardHeader>
                <CardTitle class="text-base"
                    >Cost per person (PHP / hour)</CardTitle
                >
                <CardDescription
                    >What an hour of each person costs the agency, from a date.
                    Only Finance sees this. Profitability and the people report
                    use it first, then the cost on the rate card.
                    <template v-if="missing">
                        <strong>{{ missing }}</strong> active
                        {{ missing === 1 ? 'person has' : 'people have' }} no
                        cost rate yet.</template
                    ></CardDescription
                >
            </CardHeader>
            <CardContent class="space-y-3">
                <Input
                    v-model="search"
                    class="max-w-xs"
                    placeholder="Search people"
                    aria-label="Search people"
                />
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs text-muted-foreground">
                            <th class="py-1.5 font-medium">Person</th>
                            <th class="py-1.5 text-right font-medium">
                                Cost / h now
                            </th>
                            <th class="py-1.5 font-medium">History</th>
                            <th class="py-1.5">
                                <span class="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="p in shown" :key="p.id">
                            <tr class="border-t align-top">
                                <td class="py-1.5">
                                    {{ p.name }}
                                    <span
                                        v-if="!p.active"
                                        class="text-xs text-muted-foreground"
                                        >(inactive)</span
                                    >
                                    <span
                                        class="block text-xs text-muted-foreground"
                                        >{{
                                            [p.title, p.department]
                                                .filter(Boolean)
                                                .join(' · ')
                                        }}</span
                                    >
                                </td>
                                <td class="py-1.5 text-right tabular-nums">
                                    {{
                                        p.current_minor !== null
                                            ? formatMoney(
                                                  p.current_minor,
                                                  'PHP',
                                              )
                                            : '—'
                                    }}
                                </td>
                                <td
                                    class="py-1.5 text-xs text-muted-foreground"
                                >
                                    <span
                                        v-for="(h, i) in p.history.slice(0, 3)"
                                        :key="h.id"
                                        ><template v-if="i">, </template
                                        >{{
                                            formatMoney(h.cost_minor, 'PHP')
                                        }}
                                        from {{ formatDate(h.from) }}</span
                                    >
                                </td>
                                <td class="py-1.5 text-right">
                                    <Button
                                        v-if="canEdit && editing !== p.id"
                                        variant="outline"
                                        size="sm"
                                        @click="edit(p)"
                                        >{{
                                            p.current_minor !== null
                                                ? 'Change'
                                                : 'Set'
                                        }}</Button
                                    >
                                </td>
                            </tr>
                            <tr v-if="editing === p.id">
                                <td colspan="4" class="pb-3">
                                    <form
                                        class="grid gap-2 rounded-md border p-3 sm:grid-cols-4"
                                        @submit.prevent="save"
                                    >
                                        <div class="grid gap-1">
                                            <label class="text-xs" for="cost"
                                                >Cost / h (PHP)</label
                                            >
                                            <Input
                                                id="cost"
                                                v-model="form.cost"
                                                inputmode="decimal"
                                            />
                                            <InputError
                                                :message="form.errors.cost"
                                            />
                                        </div>
                                        <div class="grid gap-1">
                                            <label class="text-xs" for="from"
                                                >From</label
                                            >
                                            <Input
                                                id="from"
                                                v-model="form.effective_from"
                                                type="date"
                                            />
                                            <InputError
                                                :message="
                                                    form.errors.effective_from
                                                "
                                            />
                                        </div>
                                        <div class="grid gap-1">
                                            <label class="text-xs" for="note"
                                                >Note (optional)</label
                                            >
                                            <Input
                                                id="note"
                                                v-model="form.note"
                                            />
                                        </div>
                                        <div class="flex items-end gap-2">
                                            <Button
                                                type="submit"
                                                size="sm"
                                                :disabled="form.processing"
                                                >Save</Button
                                            >
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                @click="editing = null"
                                                >Cancel</Button
                                            >
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </CardContent>
        </Card>
    </div>
</template>
