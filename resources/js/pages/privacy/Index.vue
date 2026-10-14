<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { formatDateTime } from '@/lib/format';
import { acknowledge, index } from '@/routes/privacy';

/**
 * E-99: the staff privacy notice, who to contact (DPO), and how long the app keeps each
 * kind of data.
 */
const props = defineProps<{
    notice: {
        version: number;
        title: string;
        body: string;
        published_at: string | null;
        dpo_name: string | null;
        dpo_email: string | null;
        dpo_phone: string | null;
    };
    acknowledgedAt: string | null;
    retention: { data: string; examples: string; kept: string; how: string }[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Privacy', href: index() }] },
});

function accept(): void {
    router.post(
        acknowledge.url(),
        { version: props.notice.version },
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head title="Privacy" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <Heading
            title="Privacy"
            description="How the agency handles your personal data in this app, and who to ask."
        />

        <Card>
            <CardHeader>
                <CardTitle class="text-base">{{ notice.title }}</CardTitle>
                <CardDescription>
                    <template v-if="notice.version">
                        Version {{ notice.version }}
                        <template v-if="notice.published_at">
                            · published
                            {{ formatDateTime(notice.published_at) }}</template
                        >
                        ·
                        <span
                            v-if="acknowledgedAt"
                            class="text-emerald-700 dark:text-emerald-300"
                            >you acknowledged it
                            {{ formatDateTime(acknowledgedAt) }}</span
                        >
                        <span v-else class="text-amber-700"
                            >not acknowledged yet</span
                        >
                    </template>
                    <template v-else
                        >No notice has been published yet.</template
                    >
                </CardDescription>
            </CardHeader>
            <CardContent v-if="notice.version" class="space-y-3">
                <div class="text-sm whitespace-pre-line">{{ notice.body }}</div>
                <Button v-if="!acknowledgedAt" size="sm" @click="accept"
                    >I have read and understood this notice</Button
                >
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle class="text-base">Data Protection Officer</CardTitle>
                <CardDescription
                    >Questions, requests to see or correct your data, or a
                    concern about how it is handled.</CardDescription
                >
            </CardHeader>
            <CardContent class="text-sm">
                <p
                    v-if="
                        notice.dpo_name || notice.dpo_email || notice.dpo_phone
                    "
                >
                    {{ notice.dpo_name }}
                    <a
                        v-if="notice.dpo_email"
                        :href="`mailto:${notice.dpo_email}`"
                        class="underline"
                        >{{ notice.dpo_email }}</a
                    >
                    <span v-if="notice.dpo_phone">
                        · {{ notice.dpo_phone }}</span
                    >
                </p>
                <p v-else class="text-muted-foreground">
                    Not set yet. Ask an admin.
                </p>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle class="text-base">How long data is kept</CardTitle>
                <CardDescription
                    >What this app keeps and for how long, as it is set up
                    today.</CardDescription
                >
            </CardHeader>
            <CardContent class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs text-muted-foreground">
                            <th class="py-1.5 font-medium">Data</th>
                            <th class="py-1.5 font-medium">Kept</th>
                            <th class="py-1.5 font-medium">How</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="r in retention"
                            :key="r.data"
                            class="border-t align-top"
                        >
                            <td class="py-1.5">
                                <span class="font-medium">{{ r.data }}</span>
                                <span
                                    class="block text-xs text-muted-foreground"
                                    >{{ r.examples }}</span
                                >
                            </td>
                            <td class="py-1.5">{{ r.kept }}</td>
                            <td class="py-1.5 text-xs text-muted-foreground">
                                {{ r.how }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </CardContent>
        </Card>
    </div>
</template>
