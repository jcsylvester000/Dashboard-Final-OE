<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { edit, update } from '@/routes/notifications';
import type { NotificationKind, NotificationMode } from '@/types/notifications';

const props = defineProps<{
    kinds: { value: NotificationKind; label: string; default: NotificationMode }[];
    modes: Record<NotificationKind, NotificationMode>;
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
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-muted-foreground">
                        <th class="py-2 font-medium">Alert</th>
                        <th v-for="m in modeOptions" :key="m.value" class="py-2 text-center font-medium">
                            {{ m.label }}
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="k in kinds" :key="k.value">
                        <td class="py-2 pr-3">{{ k.label }}</td>
                        <td v-for="m in modeOptions" :key="m.value" class="py-2 text-center">
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

            <Button type="submit" :disabled="form.processing">Save</Button>
        </form>
    </div>
</template>
