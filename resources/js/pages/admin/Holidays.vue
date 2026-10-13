<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Trash2 } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
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
import { formatDate } from '@/lib/format';
import {
    destroy,
    index as holidaysIndex,
    store,
} from '@/routes/admin/holidays';

/**
 * Holiday calendar (B-14). Regular and special non-working days reduce capacity.
 */
const props = defineProps<{
    year: number;
    holidays: { id: number; date: string; name: string; kind: string }[];
    kinds: Record<string, string>;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Holidays', href: holidaysIndex() }] },
});

const form = useForm({ date: '', name: '', kind: 'special' });
function add(): void {
    form.post(store.url(), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
async function remove(id: number, name: string): Promise<void> {
    if (await confirmDialog(`Remove ${name}?`)) {
        router.delete(destroy.url(id), { preserveScroll: true });
    }
}
const goYear = (y: number) =>
    router.get(
        holidaysIndex.url({ query: { year: y } }),
        {},
        { preserveScroll: true },
    );
</script>

<template>
    <Head title="Holidays" />

    <div class="flex max-w-3xl flex-1 flex-col gap-4 p-4">
        <Heading
            title="Holidays"
            description="Philippine holidays per Proclamation No. 1006 (2026) are loaded. Add proclaimed extras (e.g. Eid'l Fitr, Eid'l Adha) or local holidays."
        />

        <Card>
            <CardHeader>
                <div class="flex items-center justify-between">
                    <CardTitle class="text-base"
                        >{{ year }} · {{ holidays.length }} days</CardTitle
                    >
                    <span class="flex gap-1">
                        <Button
                            size="sm"
                            variant="ghost"
                            @click="goYear(year - 1)"
                            >← {{ year - 1 }}</Button
                        >
                        <Button
                            size="sm"
                            variant="ghost"
                            @click="goYear(year + 1)"
                            >{{ year + 1 }} →</Button
                        >
                    </span>
                </div>
                <CardDescription
                    >Special working days are listed for reference and don't
                    reduce capacity.</CardDescription
                >
            </CardHeader>
            <CardContent class="space-y-4">
                <ul class="divide-y text-sm">
                    <li
                        v-for="h in props.holidays"
                        :key="h.id"
                        class="flex items-center justify-between gap-2 py-1.5"
                    >
                        <span
                            ><span
                                class="inline-block w-28 text-muted-foreground"
                                >{{ formatDate(h.date) }}</span
                            >{{ h.name }}</span
                        >
                        <span
                            class="flex items-center gap-2 text-xs text-muted-foreground"
                        >
                            {{ kinds[h.kind] ?? h.kind }}
                            <Button
                                size="icon"
                                variant="ghost"
                                class="size-7"
                                :aria-label="`Remove ${h.name}`"
                                @click="remove(h.id, h.name)"
                                ><Trash2 class="size-3.5"
                            /></Button>
                        </span>
                    </li>
                    <li
                        v-if="holidays.length === 0"
                        class="py-3 text-muted-foreground"
                    >
                        No holidays on file for {{ year }}.
                    </li>
                </ul>
                <form
                    class="flex flex-wrap items-end gap-2 border-t pt-3 text-sm"
                    @submit.prevent="add"
                >
                    <label class="grid gap-1"
                        ><span class="text-xs text-muted-foreground">Date</span
                        ><Input v-model="form.date" type="date" required
                    /></label>
                    <label class="grid flex-1 gap-1"
                        ><span class="text-xs text-muted-foreground">Name</span
                        ><Input
                            v-model="form.name"
                            required
                            maxlength="120"
                            placeholder="e.g. Eid'l Fitr"
                    /></label>
                    <label class="grid gap-1">
                        <span class="text-xs text-muted-foreground">Kind</span>
                        <NativeSelect v-model="form.kind">
                            <option
                                v-for="(label, key) in kinds"
                                :key="key"
                                :value="key"
                            >
                                {{ label }}
                            </option>
                        </NativeSelect>
                    </label>
                    <Button type="submit" :disabled="form.processing"
                        >Add</Button
                    >
                    <InputError
                        :message="form.errors.date ?? form.errors.name"
                        class="w-full"
                    />
                </form>
            </CardContent>
        </Card>
    </div>
</template>
