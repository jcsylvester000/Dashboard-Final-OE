<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDateTime } from '@/lib/format';
import {
    edit,
    focus,
    quiet as saveQuiet,
    update,
} from '@/routes/notifications';
import type { NotificationKind, NotificationMode } from '@/types/notifications';

const props = defineProps<{
    kinds: {
        value: NotificationKind;
        label: string;
        default: NotificationMode;
    }[];
    modes: Record<NotificationKind, NotificationMode>;
    // E-58
    quiet: {
        focus_days: number[];
        focus_from: string | null;
        focus_to: string | null;
        after_hours: boolean;
        work_days: number[];
        work_from: string;
        work_to: string;
    };
    focusUntil: string | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Notification settings', href: edit() }],
    },
});

const modeOptions: { value: NotificationMode; label: string }[] = [
    { value: 'realtime', label: 'Real time' },
    { value: 'digest', label: 'Digest' },
    { value: 'off', label: 'Off' },
];

const form = useForm({ modes: { ...props.modes } });

function save(): void {
    form.put(update.url(), { preserveScroll: true });
}

// E-58: quiet mode.
const days = [
    { n: 1, label: 'Mon' },
    { n: 2, label: 'Tue' },
    { n: 3, label: 'Wed' },
    { n: 4, label: 'Thu' },
    { n: 5, label: 'Fri' },
    { n: 6, label: 'Sat' },
    { n: 7, label: 'Sun' },
];
const quietForm = useForm({
    focus_from: props.quiet.focus_from ?? '',
    focus_to: props.quiet.focus_to ?? '',
    focus_days: [...props.quiet.focus_days],
    after_hours: props.quiet.after_hours,
    work_from: props.quiet.work_from,
    work_to: props.quiet.work_to,
    work_days: [...props.quiet.work_days],
});

function toggleDay(list: number[], n: number): void {
    const i = list.indexOf(n);
    if (i >= 0) {
        list.splice(i, 1);
    } else {
        list.push(n);
    }
}

function saveQuietHours(): void {
    quietForm
        .transform((d) => ({
            ...d,
            focus_from: d.focus_from || null,
            focus_to: d.focus_to || null,
        }))
        .put(saveQuiet.url(), { preserveScroll: true });
}

function focusFor(value: string): void {
    router.post(focus.url(), { for: value }, { preserveScroll: true });
}
</script>

<template>
    <Head title="Notification settings" />

    <h1 class="sr-only">Notification settings</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Notifications"
            description="Real time: inbox, bell and a pop-up. Digest: inbox and the dashboard digest only. Off: not recorded. Nothing is ever emailed."
        />

        <form class="space-y-4" @submit.prevent="save">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs text-muted-foreground">
                            <th class="py-2 font-medium">Alert</th>
                            <th
                                v-for="m in modeOptions"
                                :key="m.value"
                                class="py-2 text-center font-medium"
                            >
                                {{ m.label }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="k in kinds" :key="k.value">
                            <td class="py-2 pr-3">{{ k.label }}</td>
                            <td
                                v-for="m in modeOptions"
                                :key="m.value"
                                class="py-2 text-center"
                            >
                                <input
                                    v-model="form.modes[k.value]"
                                    type="radio"
                                    class="size-4 accent-foreground"
                                    :name="`mode-${k.value}`"
                                    :value="m.value"
                                    :aria-label="`${k.label}: ${m.label}`"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Button type="submit" :disabled="form.processing">Save</Button>
        </form>

        <!-- E-58: quiet mode -->
        <section class="space-y-3" aria-labelledby="quiet-heading">
            <Heading
                id="quiet-heading"
                variant="small"
                title="Quiet mode"
                description="While you focus, and (if you choose) outside your working hours, alerts don't pop up. They still arrive and wait in your digest and inbox."
            />
            <div class="flex flex-wrap items-center gap-2 text-sm">
                <span v-if="focusUntil" class="font-medium"
                    >Focusing until {{ formatDateTime(focusUntil) }}</span
                >
                <span v-else class="text-muted-foreground">Focus now:</span>
                <template v-if="!focusUntil">
                    <Button size="sm" variant="outline" @click="focusFor('30m')"
                        >30 min</Button
                    >
                    <Button size="sm" variant="outline" @click="focusFor('1h')"
                        >1 hour</Button
                    >
                    <Button size="sm" variant="outline" @click="focusFor('2h')"
                        >2 hours</Button
                    >
                    <Button
                        size="sm"
                        variant="outline"
                        @click="focusFor('today')"
                        >Rest of today</Button
                    >
                </template>
                <Button
                    v-else
                    size="sm"
                    variant="outline"
                    @click="focusFor('off')"
                    >Stop focusing</Button
                >
            </div>

            <form class="space-y-4 text-sm" @submit.prevent="saveQuietHours">
                <fieldset class="space-y-2">
                    <legend class="font-medium">Daily focus time</legend>
                    <div class="flex flex-wrap items-center gap-2">
                        <Input
                            v-model="quietForm.focus_from"
                            type="time"
                            class="w-32"
                            aria-label="Focus from"
                        />
                        <span>to</span>
                        <Input
                            v-model="quietForm.focus_to"
                            type="time"
                            class="w-32"
                            aria-label="Focus until"
                        />
                        <span class="text-xs text-muted-foreground"
                            >Leave empty for none.</span
                        >
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <label
                            v-for="d in days"
                            :key="`f${d.n}`"
                            class="flex items-center gap-1"
                        >
                            <input
                                type="checkbox"
                                :checked="quietForm.focus_days.includes(d.n)"
                                @change="toggleDay(quietForm.focus_days, d.n)"
                            />
                            {{ d.label }}
                        </label>
                    </div>
                </fieldset>
                <fieldset class="space-y-2">
                    <legend class="font-medium">Working hours</legend>
                    <label class="flex items-center gap-2">
                        <input
                            v-model="quietForm.after_hours"
                            type="checkbox"
                        />
                        Hold alerts outside my working hours
                    </label>
                    <div class="flex flex-wrap items-center gap-2">
                        <Input
                            v-model="quietForm.work_from"
                            type="time"
                            class="w-32"
                            aria-label="Work from"
                        />
                        <span>to</span>
                        <Input
                            v-model="quietForm.work_to"
                            type="time"
                            class="w-32"
                            aria-label="Work until"
                        />
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <label
                            v-for="d in days"
                            :key="`w${d.n}`"
                            class="flex items-center gap-1"
                        >
                            <input
                                type="checkbox"
                                :checked="quietForm.work_days.includes(d.n)"
                                @change="toggleDay(quietForm.work_days, d.n)"
                            />
                            {{ d.label }}
                        </label>
                    </div>
                </fieldset>
                <Button type="submit" :disabled="quietForm.processing"
                    >Save quiet hours</Button
                >
            </form>
        </section>
    </div>
</template>
