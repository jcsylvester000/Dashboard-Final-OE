<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Trash2 } from '@lucide/vue';
import ReportPackController from '@/actions/App/Http/Controllers/Workspaces/ReportPackController';
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
import { timeAgo } from '@/lib/format';
import type { MemberOption } from '@/types/workspace';

/**
 * E-51: scheduled report packs. Each run makes one branded PDF, saves it to this client's
 * files and alerts the chosen people (no email). "Make one now" checks it before the first run.
 */
export type PackRow = {
    id: number;
    name: string;
    sections: string[];
    frequency: 'weekly' | 'monthly';
    day: number;
    recipient_ids: number[];
    enabled: boolean;
    next_run_on: string;
    last_run_at: string | null;
    runs: number;
};

const props = defineProps<{
    workspaceSlug: string;
    packs: PackRow[];
    sections: Record<string, string>;
    members: MemberOption[];
}>();

const args = { workspace: props.workspaceSlug };
const WEEKDAYS = [
    'Monday',
    'Tuesday',
    'Wednesday',
    'Thursday',
    'Friday',
    'Saturday',
    'Sunday',
];
const form = useForm({
    name: 'Monthly client report',
    sections: ['hours', 'completed', 'open'] as string[],
    frequency: 'monthly' as 'weekly' | 'monthly',
    day: 1,
    recipient_ids: [] as number[],
});
function save(): void {
    form.post(ReportPackController.store.url(args), { preserveScroll: true });
}
function toggle(p: PackRow): void {
    router.put(
        ReportPackController.update.url({ ...args, pack: p.id }),
        { enabled: !p.enabled },
        { preserveScroll: true },
    );
}
function runNow(p: PackRow): void {
    router.post(
        ReportPackController.run.url({ ...args, pack: p.id }),
        {},
        { preserveScroll: true },
    );
}
async function remove(p: PackRow): Promise<void> {
    if (
        await confirmDialog(
            `Delete the report pack "${p.name}"? PDFs already made stay in the files.`,
        )
    ) {
        router.delete(
            ReportPackController.destroy.url({ ...args, pack: p.id }),
            { preserveScroll: true },
        );
    }
}
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle class="text-base">Report packs</CardTitle>
            <CardDescription
                >A PDF made on a schedule, saved to this client's files, with a
                link sent to the people you choose.</CardDescription
            >
        </CardHeader>
        <CardContent class="space-y-4 text-sm">
            <ul v-if="packs.length" class="divide-y rounded border">
                <li
                    v-for="p in packs"
                    :key="p.id"
                    class="flex flex-wrap items-center gap-2 px-3 py-2"
                >
                    <div class="min-w-0 flex-1">
                        <p class="font-medium">{{ p.name }}</p>
                        <p class="text-xs text-muted-foreground">
                            {{
                                p.frequency === 'weekly'
                                    ? `Weekly on ${WEEKDAYS[p.day - 1]}`
                                    : `Monthly on day ${p.day}`
                            }}
                            · next {{ p.next_run_on }} · made
                            {{ p.runs }}×<template v-if="p.last_run_at"
                                >, last {{ timeAgo(p.last_run_at) }}</template
                            >
                        </p>
                    </div>
                    <Button size="sm" variant="secondary" @click="runNow(p)"
                        >Make one now</Button
                    >
                    <Button
                        size="sm"
                        :variant="p.enabled ? 'default' : 'outline'"
                        @click="toggle(p)"
                        >{{ p.enabled ? 'On' : 'Off' }}</Button
                    >
                    <Button
                        size="icon"
                        variant="ghost"
                        :aria-label="`Delete ${p.name}`"
                        @click="remove(p)"
                        ><Trash2 class="size-4"
                    /></Button>
                </li>
            </ul>
            <form class="grid gap-2 rounded border p-3" @submit.prevent="save">
                <Input
                    v-model="form.name"
                    maxlength="120"
                    aria-label="Pack name"
                />
                <fieldset class="flex flex-wrap gap-3 text-xs">
                    <legend class="mb-1 text-xs font-medium">Contents</legend>
                    <label
                        v-for="(label, key) in sections"
                        :key="key"
                        class="flex items-center gap-1"
                    >
                        <input
                            v-model="form.sections"
                            type="checkbox"
                            :value="key"
                            class="size-3.5"
                        />
                        {{ label }}
                    </label>
                </fieldset>
                <div class="flex flex-wrap items-center gap-2">
                    <select
                        v-model="form.frequency"
                        class="h-8 rounded border bg-background px-2"
                        aria-label="How often"
                        @change="form.day = 1"
                    >
                        <option value="monthly">Monthly</option>
                        <option value="weekly">Weekly</option>
                    </select>
                    <select
                        v-if="form.frequency === 'weekly'"
                        v-model.number="form.day"
                        class="h-8 rounded border bg-background px-2"
                        aria-label="Weekday"
                    >
                        <option
                            v-for="(d, i) in WEEKDAYS"
                            :key="d"
                            :value="i + 1"
                        >
                            {{ d }}
                        </option>
                    </select>
                    <label v-else class="flex items-center gap-1 text-xs"
                        >on day
                        <input
                            v-model.number="form.day"
                            type="number"
                            min="1"
                            max="28"
                            class="h-8 w-16 rounded border bg-background px-2"
                            aria-label="Day of month"
                    /></label>
                </div>
                <label class="grid gap-1 text-xs">
                    Send the link to (blank = the client's leads)
                    <select
                        v-model="form.recipient_ids"
                        multiple
                        size="4"
                        class="rounded border bg-background p-1"
                    >
                        <option v-for="m in members" :key="m.id" :value="m.id">
                            {{ m.name }}
                        </option>
                    </select>
                </label>
                <Button
                    type="submit"
                    size="sm"
                    class="w-fit"
                    :disabled="form.processing || !form.sections.length"
                    >Schedule pack</Button
                >
                <InputError
                    :message="
                        form.errors.sections ??
                        form.errors.day ??
                        form.errors.name
                    "
                />
            </form>
        </CardContent>
    </Card>
</template>
