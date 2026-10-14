<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/format';
import { health } from '@/routes/admin';

/**
 * E-96: is everything running? Admins are alerted (System notices) when a check turns red.
 */
defineProps<{
    checks: {
        key: string;
        label: string;
        status: 'ok' | 'warn' | 'fail';
        value: string;
        detail: string | null;
    }[];
    checkedAt: string;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'System health', href: health() }] },
});

const pill: Record<string, string> = {
    ok: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200',
    warn: 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-200',
    fail: 'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-200',
};
const word: Record<string, string> = {
    ok: 'OK',
    warn: 'Check',
    fail: 'Problem',
};
</script>

<template>
    <Head title="System health" />

    <div class="flex max-w-3xl flex-1 flex-col gap-4 p-4">
        <div class="flex flex-wrap items-end justify-between gap-2">
            <Heading
                title="System health"
                :description="`Checked ${formatDateTime(checkedAt)}. Admins get a notice when a check turns red.`"
            />
            <Button
                size="sm"
                variant="outline"
                @click="router.reload({ only: ['checks', 'checkedAt'] })"
                >Check again</Button
            >
        </div>

        <ul class="divide-y rounded-lg border">
            <li
                v-for="c in checks"
                :key="c.key"
                class="flex flex-wrap items-start justify-between gap-3 px-4 py-3"
            >
                <div class="min-w-0">
                    <p class="font-medium">{{ c.label }}</p>
                    <p v-if="c.detail" class="text-xs text-muted-foreground">
                        {{ c.detail }}
                    </p>
                </div>
                <div class="flex items-center gap-2 text-sm">
                    <span class="tabular-nums">{{ c.value }}</span>
                    <span
                        class="rounded-full px-2 py-0.5 text-xs font-medium"
                        :class="pill[c.status]"
                        >{{ word[c.status] }}</span
                    >
                </div>
            </li>
        </ul>
    </div>
</template>
