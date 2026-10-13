<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Pencil, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { confirmDialog } from '@/composables/useConfirm';
import { formatDate, localToday } from '@/lib/format';
import { formatMoney } from '@/lib/money';
import { destroy, store, update } from '@/routes/billing/rates';
import type { RateRow } from '@/types/billing';

/**
 * Rate cards (agency-wide, or one client's overrides when workspaceId is set).
 * Rates are effective-dated: add a new one for a real change; Edit corrects a
 * mistake in place (E-65, reason required). Agency rates carry a currency (E-100);
 * a client's rates are always in its billing currency.
 */
const props = defineProps<{
    rates: RateRow[];
    workspaceId: number | null;
    currency: string;
    departments: { id: number; name: string }[];
    people: { id: number; name: string }[];
    currencies?: string[];
}>();

const form = useForm({
    workspace_id: props.workspaceId,
    scope: '',
    rate: '',
    cost: '',
    effective_from: localToday(),
    currency: props.currency,
});

// E-65: correct one card in place.
const editingId = ref<number | null>(null);
const editForm = useForm({
    rate: '',
    cost: '',
    effective_from: '',
    reason: '',
});
function startEdit(r: RateRow): void {
    editingId.value = r.id;
    editForm.defaults({
        rate: (r.rate_minor / 100).toFixed(2),
        cost: r.cost_minor !== null ? (r.cost_minor / 100).toFixed(2) : '',
        effective_from: r.effective_from,
        reason: '',
    });
    editForm.reset();
    editForm.clearErrors();
}
function saveEdit(id: number): void {
    editForm.put(update.url(id), {
        preserveScroll: true,
        onSuccess: () => (editingId.value = null),
    });
}

function submit(): void {
    const [kind, id] = form.scope.split(':');
    form.transform((data) => ({
        workspace_id: data.workspace_id,
        department_id: kind === 'dept' ? Number(id) : null,
        user_id: kind === 'user' ? Number(id) : null,
        rate: data.rate,
        cost: data.cost,
        effective_from: data.effective_from,
        currency: props.workspaceId === null ? data.currency : null,
    })).post(store.url(), {
        preserveScroll: true,
        onSuccess: () => form.reset('rate', 'cost'),
    });
}

async function remove(id: number): Promise<void> {
    if (
        await confirmDialog(
            'Remove this rate? Existing invoices keep their amounts.',
        )
    ) {
        router.delete(destroy.url(id), { preserveScroll: true });
    }
}
</script>

<template>
    <div class="space-y-3">
        <table v-if="rates.length" class="w-full text-sm">
            <thead class="text-left text-xs text-muted-foreground">
                <tr>
                    <th class="py-1.5 font-medium">Applies to</th>
                    <th
                        class="py-1.5 pl-3 text-right font-medium whitespace-nowrap"
                    >
                        Rate / h
                    </th>
                    <th
                        class="py-1.5 pl-3 text-right font-medium whitespace-nowrap"
                    >
                        Cost / h
                    </th>
                    <th class="py-1.5 pl-3 font-medium">From</th>
                    <th />
                </tr>
            </thead>
            <tbody class="divide-y">
                <template v-for="r in rates" :key="r.id">
                    <tr v-if="editingId === r.id" class="bg-muted/30">
                        <td colspan="5" class="py-2">
                            <form
                                class="grid gap-2 sm:grid-cols-5"
                                @submit.prevent="saveEdit(r.id)"
                            >
                                <span
                                    class="self-center text-xs text-muted-foreground sm:col-span-5"
                                >
                                    Correct
                                    {{
                                        r.person ??
                                        r.department ??
                                        'Default (everyone)'
                                    }}
                                    ({{ r.currency ?? currency }}). For a real
                                    price change, add a new rate instead.
                                </span>
                                <Input
                                    v-model="editForm.rate"
                                    inputmode="decimal"
                                    aria-label="Rate per hour"
                                />
                                <Input
                                    v-model="editForm.cost"
                                    inputmode="decimal"
                                    placeholder="Cost / h"
                                    aria-label="Cost per hour"
                                />
                                <Input
                                    v-model="editForm.effective_from"
                                    type="date"
                                    aria-label="Effective from"
                                />
                                <Input
                                    v-model="editForm.reason"
                                    class="sm:col-span-2"
                                    maxlength="255"
                                    placeholder="Reason (required)"
                                    aria-label="Reason for the correction"
                                    required
                                />
                                <div class="flex gap-2 sm:col-span-5">
                                    <Button
                                        size="sm"
                                        type="submit"
                                        :disabled="editForm.processing"
                                        >Save correction</Button
                                    >
                                    <Button
                                        size="sm"
                                        type="button"
                                        variant="ghost"
                                        @click="editingId = null"
                                        >Cancel</Button
                                    >
                                    <InputError
                                        :message="
                                            editForm.errors.rate ??
                                            editForm.errors.reason ??
                                            editForm.errors.effective_from
                                        "
                                    />
                                </div>
                            </form>
                        </td>
                    </tr>
                    <tr v-else>
                        <td class="py-1.5">
                            {{
                                r.person ?? r.department ?? 'Default (everyone)'
                            }}
                            <span
                                v-if="
                                    workspaceId !== null &&
                                    r.currency &&
                                    r.currency !== currency
                                "
                                class="ml-1 rounded bg-muted px-1 text-[10px] text-muted-foreground"
                                :title="`This client bills in ${currency}; ${r.currency} rates are kept for history only`"
                                >{{ r.currency }} · not used</span
                            >
                        </td>
                        <td
                            class="py-1.5 pl-3 text-right whitespace-nowrap tabular-nums"
                        >
                            {{
                                formatMoney(
                                    r.rate_minor,
                                    r.currency ?? currency,
                                )
                            }}
                        </td>
                        <td
                            class="py-1.5 pl-3 text-right whitespace-nowrap tabular-nums"
                        >
                            {{
                                r.cost_minor !== null
                                    ? formatMoney(
                                          r.cost_minor,
                                          r.currency ?? currency,
                                      )
                                    : '—'
                            }}
                        </td>
                        <td class="py-1.5 pl-3 text-xs whitespace-nowrap">
                            {{ formatDate(r.effective_from) }}
                        </td>
                        <td class="py-1.5 text-right whitespace-nowrap">
                            <Button
                                variant="ghost"
                                size="icon"
                                aria-label="Correct rate"
                                @click="startEdit(r)"
                                ><Pencil class="size-4"
                            /></Button>
                            <Button
                                variant="ghost"
                                size="icon"
                                aria-label="Remove rate"
                                @click="remove(r.id)"
                                ><Trash2 class="size-4"
                            /></Button>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
        <p v-else class="text-sm text-muted-foreground">No rates yet.</p>

        <form class="grid gap-2 sm:grid-cols-5" @submit.prevent="submit">
            <NativeSelect
                v-model="form.scope"
                aria-label="Applies to"
                class="sm:col-span-2"
            >
                <option value="">Default (everyone)</option>
                <optgroup label="Department">
                    <option
                        v-for="d in departments"
                        :key="`d${d.id}`"
                        :value="`dept:${d.id}`"
                    >
                        {{ d.name }}
                    </option>
                </optgroup>
                <optgroup label="Person">
                    <option
                        v-for="p in people"
                        :key="`u${p.id}`"
                        :value="`user:${p.id}`"
                    >
                        {{ p.name }}
                    </option>
                </optgroup>
            </NativeSelect>
            <Input
                v-model="form.rate"
                inputmode="decimal"
                placeholder="Rate / hour"
                aria-label="Rate per hour"
            />
            <Input
                v-model="form.cost"
                inputmode="decimal"
                placeholder="Internal cost / h"
                aria-label="Cost per hour"
            />
            <Input
                v-model="form.effective_from"
                type="date"
                aria-label="Effective from"
            />
            <NativeSelect
                v-if="workspaceId === null && (currencies?.length ?? 0) > 1"
                v-model="form.currency"
                aria-label="Currency"
            >
                <option v-for="c in currencies" :key="c" :value="c">
                    {{ c }}
                </option>
            </NativeSelect>
            <div class="sm:col-span-5">
                <InputError
                    :message="
                        form.errors.rate ??
                        form.errors.cost ??
                        (form.errors as Record<string, string | undefined>)
                            .department_id
                    "
                />
                <Button size="sm" type="submit" :disabled="form.processing"
                    >Add rate</Button
                >
            </div>
        </form>
    </div>
</template>
