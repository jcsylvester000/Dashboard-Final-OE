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
    bulk as importRoute,
    store,
} from '@/routes/admin/holidays';

/**
 * Holiday calendar (B-14). Regular and special non-working days reduce capacity.
 */
const props = defineProps<{
    year: number;
    holidays: { id: number; date: string; name: string; kind: string }[];
    kinds: Record<string, string>;
    importResult: {
        preview: boolean;
        created: number;
        rows: {
            line: number;
            date: string;
            name: string;
            kind: string | null;
            status: 'new' | 'duplicate' | 'error';
            error: string | null;
        }[];
    } | null;
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
// E-95: paste a year's proclamation list, preview, then add.
const importForm = useForm({ text: '', preview: true });
function runImport(preview: boolean): void {
    importForm.preview = preview;
    importForm.post(importRoute.url(), {
        preserveScroll: true,
        onSuccess: () => {
            if (!preview) {
                importForm.reset();
            }
        },
    });
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

        <Card>
            <CardHeader>
                <CardTitle class="text-base">Import a year's list</CardTitle>
                <CardDescription
                    >Paste the proclamation, one holiday per line: date, name,
                    kind (regular, special or special working). Dates already in
                    the calendar are skipped.</CardDescription
                >
            </CardHeader>
            <CardContent class="space-y-3 text-sm">
                <textarea
                    v-model="importForm.text"
                    rows="6"
                    class="w-full rounded-md border bg-transparent p-2 font-mono text-xs"
                    placeholder="2027-01-01, New Year's Day, regular&#10;2027-02-25, EDSA People Power Revolution Anniversary, special working"
                    aria-label="Holiday list"
                />
                <InputError :message="importForm.errors.text" />
                <div class="flex gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="
                            importForm.processing || !importForm.text.trim()
                        "
                        @click="runImport(true)"
                        >Preview</Button
                    >
                    <Button
                        size="sm"
                        :disabled="
                            importForm.processing || !importForm.text.trim()
                        "
                        @click="runImport(false)"
                        >Add holidays</Button
                    >
                </div>
                <div v-if="importResult" class="rounded-md border">
                    <p class="border-b px-3 py-2 text-xs text-muted-foreground">
                        <template v-if="importResult.preview"
                            >Preview:
                            {{
                                importResult.rows.filter(
                                    (r) => r.status === 'new',
                                ).length
                            }}
                            to add,
                        </template>
                        <template v-else
                            >Added {{ importResult.created }},
                        </template>
                        {{
                            importResult.rows.filter(
                                (r) => r.status === 'duplicate',
                            ).length
                        }}
                        already there,
                        {{
                            importResult.rows.filter(
                                (r) => r.status === 'error',
                            ).length
                        }}
                        with problems.
                    </p>
                    <ul class="max-h-64 divide-y overflow-y-auto text-xs">
                        <li
                            v-for="r in importResult.rows"
                            :key="r.line"
                            class="flex justify-between gap-2 px-3 py-1"
                            :class="{
                                'text-muted-foreground':
                                    r.status === 'duplicate',
                                'text-rose-700 dark:text-rose-300':
                                    r.status === 'error',
                            }"
                        >
                            <span
                                >{{ r.date }} · {{ r.name
                                }}<template v-if="r.kind">
                                    · {{ kinds[r.kind] ?? r.kind }}</template
                                ></span
                            >
                            <span>{{
                                r.status === 'error'
                                    ? `Line ${r.line}: ${r.error}`
                                    : r.status === 'duplicate'
                                      ? 'already there'
                                      : 'new'
                            }}</span>
                        </li>
                    </ul>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
