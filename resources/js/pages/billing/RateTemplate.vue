<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
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
import { confirmDialog } from '@/composables/useConfirm';
import { index as invoicesIndex } from '@/routes/billing/invoices';
import { destroy, update } from '@/routes/billing/rate-templates';
import { index as ratesIndex } from '@/routes/billing/rates';

type Row = { id: number; name: string; rate: string; cost: string };

const props = defineProps<{
    template: { id: number; name: string; description: string | null };
    people: (Row & { title: string | null; department: string | null })[];
    departments: Row[];
    default: { rate: string; cost: string };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Billing', href: invoicesIndex() }],
    },
});

const form = useForm({
    name: props.template.name,
    description: props.template.description ?? '',
    people: props.people.map((p) => ({ id: p.id, rate: p.rate, cost: p.cost })),
    departments: props.departments.map((d) => ({
        id: d.id,
        rate: d.rate,
        cost: d.cost,
    })),
    default: { ...props.default },
});

// People grouped by department so a team's rates sit together.
const groups = computed(() => {
    const map = new Map<string, number[]>();
    props.people.forEach((p, i) => {
        const key = p.department ?? 'No department';
        map.set(key, [...(map.get(key) ?? []), i]);
    });

    // Departments first, people without a department (admin, finance) last.
    return [...map.entries()].sort(([a], [b]) =>
        a === 'No department'
            ? 1
            : b === 'No department'
              ? -1
              : a.localeCompare(b),
    );
});
const filled = computed(() => form.people.filter((p) => p.rate !== '').length);

function save(): void {
    form.put(update.url(props.template.id), { preserveScroll: true });
}

async function remove(): Promise<void> {
    if (
        await confirmDialog(
            `Delete the template "${props.template.name}"? Clients keep the rates they already got from it.`,
        )
    ) {
        router.delete(destroy.url(props.template.id));
    }
}
</script>

<template>
    <Head :title="`Rate template · ${template.name}`" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <Heading
            title="Billing"
            description="A rate template is a reusable set of per-person rates. Apply it to a client from the client's billing page."
        />
        <BillingNav />

        <form class="space-y-4" @submit.prevent="save">
            <Card>
                <CardHeader>
                    <div
                        class="flex flex-wrap items-center justify-between gap-2"
                    >
                        <CardTitle class="text-base">Rate template</CardTitle>
                        <Link
                            :href="ratesIndex()"
                            class="text-xs text-muted-foreground hover:underline"
                            >← All rates and templates</Link
                        >
                    </div>
                    <CardDescription>
                        Hourly rate and internal cost per person. Leave a person
                        blank to fall back to their department row, then the
                        default row.
                    </CardDescription>
                </CardHeader>
                <CardContent class="grid gap-3 sm:grid-cols-2">
                    <label class="grid gap-1 text-sm">
                        <span class="text-xs text-muted-foreground">Name</span>
                        <Input v-model="form.name" required />
                        <InputError :message="form.errors.name" />
                    </label>
                    <label class="grid gap-1 text-sm">
                        <span class="text-xs text-muted-foreground"
                            >Description</span
                        >
                        <Input
                            v-model="form.description"
                            placeholder="e.g. Standard client rates 2026"
                        />
                    </label>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle class="text-base"
                        >People
                        <span class="text-sm font-normal text-muted-foreground"
                            >· {{ filled }} of {{ people.length }} with a
                            rate</span
                        ></CardTitle
                    >
                </CardHeader>
                <CardContent class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-left text-xs text-muted-foreground">
                            <tr>
                                <th class="py-1.5 font-medium">Person</th>
                                <th class="py-1.5 pl-3 font-medium">
                                    Rate / h
                                </th>
                                <th class="py-1.5 pl-3 font-medium">
                                    Internal cost / h
                                </th>
                            </tr>
                        </thead>
                        <template v-for="[dept, indexes] in groups" :key="dept">
                            <tbody>
                                <tr>
                                    <td
                                        colspan="3"
                                        class="pt-3 pb-1 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                                    >
                                        {{ dept }}
                                    </td>
                                </tr>
                                <tr
                                    v-for="i in indexes"
                                    :key="people[i].id"
                                    class="border-t"
                                >
                                    <td class="py-1.5">
                                        <div class="font-medium">
                                            {{ people[i].name }}
                                        </div>
                                        <div
                                            v-if="people[i].title"
                                            class="text-xs text-muted-foreground"
                                        >
                                            {{ people[i].title }}
                                        </div>
                                    </td>
                                    <td class="py-1.5 pl-3">
                                        <Input
                                            v-model="form.people[i].rate"
                                            inputmode="decimal"
                                            class="h-8 w-32"
                                            :aria-label="`Rate for ${people[i].name}`"
                                            placeholder="—"
                                        />
                                    </td>
                                    <td class="py-1.5 pl-3">
                                        <Input
                                            v-model="form.people[i].cost"
                                            inputmode="decimal"
                                            class="h-8 w-32"
                                            :aria-label="`Cost for ${people[i].name}`"
                                            placeholder="—"
                                        />
                                    </td>
                                </tr>
                            </tbody>
                        </template>
                    </table>
                    <InputError
                        :message="
                            Object.entries(form.errors).find(([k]) =>
                                k.startsWith('people'),
                            )?.[1]
                        "
                    />
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle class="text-base">Fallbacks</CardTitle>
                    <CardDescription
                        >Used for anyone without a personal rate in this
                        template.</CardDescription
                    >
                </CardHeader>
                <CardContent class="space-y-2 text-sm">
                    <div
                        v-for="(d, i) in departments"
                        :key="d.id"
                        class="flex flex-wrap items-center gap-3"
                    >
                        <span class="w-40">{{ d.name }}</span>
                        <Input
                            v-model="form.departments[i].rate"
                            inputmode="decimal"
                            class="h-8 w-32"
                            :aria-label="`Rate for ${d.name}`"
                            placeholder="Rate / h"
                        />
                        <Input
                            v-model="form.departments[i].cost"
                            inputmode="decimal"
                            class="h-8 w-32"
                            :aria-label="`Cost for ${d.name}`"
                            placeholder="Cost / h"
                        />
                    </div>
                    <div
                        class="flex flex-wrap items-center gap-3 border-t pt-2"
                    >
                        <span class="w-40 font-medium"
                            >Default (everyone else)</span
                        >
                        <Input
                            v-model="form.default.rate"
                            inputmode="decimal"
                            class="h-8 w-32"
                            aria-label="Default rate"
                            placeholder="Rate / h"
                        />
                        <Input
                            v-model="form.default.cost"
                            inputmode="decimal"
                            class="h-8 w-32"
                            aria-label="Default cost"
                            placeholder="Cost / h"
                        />
                    </div>
                </CardContent>
            </Card>

            <div class="flex gap-2">
                <Button type="submit" :disabled="form.processing"
                    >Save template</Button
                >
                <Button type="button" variant="ghost" @click="remove"
                    >Delete template</Button
                >
            </div>
        </form>
    </div>
</template>
