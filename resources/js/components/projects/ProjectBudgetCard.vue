<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatMinutes } from '@/lib/format';
import { formatMoney } from '@/lib/money';
import { budget as budgetRoute } from '@/routes/workspaces/projects';
import type { ProjectBudget } from '@/types/workspace';

/**
 * E-76 / E-110: project budget and burn. Leads see and set hours; Finance also sees and
 * sets the amount (in the client's currency). Bars turn amber at 75 % and red at 90 %.
 */

const props = defineProps<{
    budget: ProjectBudget;
    workspaceSlug: string;
    projectId: number;
}>();

const editing = ref(false);
const saving = ref(false);
const errors = ref<Record<string, string>>({});
const hours = ref('');
const amount = ref('');
const finance = computed(() => props.budget.currency !== undefined);

function open(): void {
    hours.value =
        props.budget.minutes_budget !== null
            ? String(+(props.budget.minutes_budget / 60).toFixed(2))
            : '';
    amount.value =
        props.budget.amount_budget != null
            ? (props.budget.amount_budget / 100).toFixed(2)
            : '';
    errors.value = {};
    editing.value = true;
}

function save(): void {
    const data: Record<string, string | null> = {
        budget_hours: hours.value.trim() || null,
    };
    if (props.budget.can_edit_amount) {
        data.budget_amount = amount.value.trim().replace(/,/g, '') || null;
    }
    if (!props.budget.can_edit_hours) {
        delete data.budget_hours;
    }
    saving.value = true;
    router.put(
        budgetRoute.url({
            workspace: props.workspaceSlug,
            project: props.projectId,
        }),
        data,
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => (editing.value = false),
            onError: (e) => (errors.value = e),
            onFinish: () => (saving.value = false),
        },
    );
}

function barClass(pct: number | null | undefined): string {
    if ((pct ?? 0) >= 90) {
        return 'bg-rose-500';
    }

    return (pct ?? 0) >= 75 ? 'bg-amber-500' : 'bg-emerald-500';
}
</script>

<template>
    <Card>
        <CardHeader class="flex flex-row items-center justify-between gap-2">
            <CardTitle class="text-base">Budget</CardTitle>
            <Button
                v-if="
                    !editing &&
                    (budget.can_edit_hours || budget.can_edit_amount)
                "
                variant="outline"
                size="sm"
                @click="open"
                >{{
                    budget.minutes_budget !== null ||
                    budget.amount_budget != null
                        ? 'Change'
                        : 'Set budget'
                }}</Button
            >
        </CardHeader>
        <CardContent class="space-y-4">
            <form v-if="editing" class="space-y-3" @submit.prevent="save">
                <div v-if="budget.can_edit_hours" class="grid gap-1.5">
                    <Label for="budget-hours">Hours budget</Label>
                    <Input
                        id="budget-hours"
                        v-model="hours"
                        inputmode="decimal"
                        placeholder="e.g. 120"
                    />
                    <p v-if="errors.budget_hours" class="text-sm text-rose-600">
                        {{ errors.budget_hours }}
                    </p>
                </div>
                <div v-if="budget.can_edit_amount" class="grid gap-1.5">
                    <Label for="budget-amount"
                        >Amount budget ({{ budget.currency }}, the client's
                        currency)</Label
                    >
                    <Input
                        id="budget-amount"
                        v-model="amount"
                        inputmode="decimal"
                        placeholder="e.g. 250000.00"
                    />
                    <p
                        v-if="errors.budget_amount"
                        class="text-sm text-rose-600"
                    >
                        {{ errors.budget_amount }}
                    </p>
                </div>
                <p class="text-xs text-muted-foreground">
                    Leave a field empty to remove that budget. Alerts go out at
                    75%, 90% and 100%, once each.
                </p>
                <div class="flex gap-2">
                    <Button type="submit" size="sm" :disabled="saving"
                        >Save budget</Button
                    >
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        @click="editing = false"
                        >Cancel</Button
                    >
                </div>
            </form>

            <template v-else>
                <p
                    v-if="
                        budget.minutes_budget === null &&
                        budget.amount_budget == null
                    "
                    class="text-sm text-muted-foreground"
                >
                    No budget yet. {{ formatMinutes(budget.minutes_used) }}
                    logged so far.
                </p>

                <div v-if="budget.minutes_budget !== null" class="space-y-1.5">
                    <div class="flex items-baseline justify-between text-sm">
                        <span class="font-medium">Hours</span>
                        <span>
                            {{ formatMinutes(budget.minutes_used) }} of
                            {{ formatMinutes(budget.minutes_budget) }}
                            <strong class="ml-1"
                                >{{ budget.hours_pct }}%</strong
                            >
                        </span>
                    </div>
                    <div
                        class="h-2 overflow-hidden rounded-full bg-muted"
                        role="progressbar"
                        aria-label="Hours budget used"
                        :aria-valuenow="budget.hours_pct ?? 0"
                        aria-valuemin="0"
                        aria-valuemax="100"
                    >
                        <div
                            class="h-full rounded-full"
                            :class="barClass(budget.hours_pct)"
                            :style="{
                                width: `${Math.min(100, budget.hours_pct ?? 0)}%`,
                            }"
                        />
                    </div>
                </div>

                <div
                    v-if="finance && budget.amount_budget != null"
                    class="space-y-1.5"
                >
                    <div class="flex items-baseline justify-between text-sm">
                        <span class="font-medium">Amount</span>
                        <span>
                            {{
                                formatMoney(budget.amount_used, budget.currency)
                            }}
                            of
                            {{
                                formatMoney(
                                    budget.amount_budget,
                                    budget.currency,
                                )
                            }}
                            <strong class="ml-1"
                                >{{ budget.amount_pct }}%</strong
                            >
                        </span>
                    </div>
                    <div
                        class="h-2 overflow-hidden rounded-full bg-muted"
                        role="progressbar"
                        aria-label="Amount budget used"
                        :aria-valuenow="budget.amount_pct ?? 0"
                        aria-valuemin="0"
                        aria-valuemax="100"
                    >
                        <div
                            class="h-full rounded-full"
                            :class="barClass(budget.amount_pct)"
                            :style="{
                                width: `${Math.min(100, budget.amount_pct ?? 0)}%`,
                            }"
                        />
                    </div>
                    <p class="text-xs text-muted-foreground">
                        Billable time at the client's rates plus billable
                        expenses.
                        <template v-if="budget.unpriced_minutes">
                            {{ formatMinutes(budget.unpriced_minutes) }} has no
                            rate yet and is not counted.</template
                        >
                    </p>
                </div>
            </template>
        </CardContent>
    </Card>
</template>
