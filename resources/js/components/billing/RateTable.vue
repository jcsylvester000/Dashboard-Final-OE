<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Trash2 } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/lib/format';
import { formatMoney } from '@/lib/money';
import { destroy, store } from '@/routes/billing/rates';
import type { RateRow } from '@/types/billing';

/**
 * Rate cards (agency-wide, or one client's overrides when workspaceId is set).
 * Rates are effective-dated: add a new one instead of editing.
 */
const props = defineProps<{
    rates: RateRow[];
    workspaceId: number | null;
    currency: string;
    departments: { id: number; name: string }[];
    people: { id: number; name: string }[];
}>();

const form = useForm({
    workspace_id: props.workspaceId,
    scope: '',
    rate: '',
    cost: '',
    effective_from: new Date().toISOString().slice(0, 10),
});

function submit(): void {
    const [kind, id] = form.scope.split(':');
    form.transform((data) => ({
        workspace_id: data.workspace_id,
        department_id: kind === 'dept' ? Number(id) : null,
        user_id: kind === 'user' ? Number(id) : null,
        rate: data.rate,
        cost: data.cost,
        effective_from: data.effective_from,
    })).post(store.url(), {
        preserveScroll: true,
        onSuccess: () => form.reset('rate', 'cost'),
    });
}

function remove(id: number): void {
    if (window.confirm('Remove this rate? Existing invoices keep their amounts.')) {
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
                    <th class="py-1.5 text-right font-medium">Rate / h</th>
                    <th class="py-1.5 text-right font-medium">Cost / h</th>
                    <th class="py-1.5 font-medium">From</th>
                    <th />
                </tr>
            </thead>
            <tbody class="divide-y">
                <tr v-for="r in rates" :key="r.id">
                    <td class="py-1.5">{{ r.person ?? r.department ?? 'Default (everyone)' }}</td>
                    <td class="py-1.5 text-right tabular-nums">{{ formatMoney(r.rate_minor, currency) }}</td>
                    <td class="py-1.5 text-right tabular-nums">{{ r.cost_minor !== null ? formatMoney(r.cost_minor, currency) : '—' }}</td>
                    <td class="py-1.5 text-xs">{{ formatDate(r.effective_from) }}</td>
                    <td class="py-1.5 text-right">
                        <Button variant="ghost" size="icon" aria-label="Remove rate" @click="remove(r.id)"><Trash2 class="size-4" /></Button>
                    </td>
                </tr>
            </tbody>
        </table>
        <p v-else class="text-sm text-muted-foreground">No rates yet.</p>

        <form class="grid gap-2 sm:grid-cols-5" @submit.prevent="submit">
            <NativeSelect v-model="form.scope" aria-label="Applies to" class="sm:col-span-2">
                <option value="">Default (everyone)</option>
                <optgroup label="Department">
                    <option v-for="d in departments" :key="`d${d.id}`" :value="`dept:${d.id}`">{{ d.name }}</option>
                </optgroup>
                <optgroup label="Person">
                    <option v-for="p in people" :key="`u${p.id}`" :value="`user:${p.id}`">{{ p.name }}</option>
                </optgroup>
            </NativeSelect>
            <Input v-model="form.rate" inputmode="decimal" placeholder="Rate / hour" aria-label="Rate per hour" />
            <Input v-model="form.cost" inputmode="decimal" placeholder="Internal cost / h" aria-label="Cost per hour" />
            <Input v-model="form.effective_from" type="date" aria-label="Effective from" />
            <div class="sm:col-span-5">
                <InputError :message="form.errors.rate ?? form.errors.cost ?? (form.errors as Record<string, string | undefined>).department_id" />
                <Button size="sm" type="submit" :disabled="form.processing">Add rate</Button>
            </div>
        </form>
    </div>
</template>
