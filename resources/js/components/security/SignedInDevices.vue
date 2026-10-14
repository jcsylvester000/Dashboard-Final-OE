<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { confirmDialog } from '@/composables/useConfirm';
import { formatDateTime, timeAgo } from '@/lib/format';

/**
 * E-88: where an account is signed in (end one, or all others) and its last sign-ins,
 * with bursts of failed attempts highlighted. Used on Settings > Security and on the
 * admin's user page.
 */
export type Device = {
    id: number;
    browser: string;
    ip: string | null;
    last_seen_at: string;
    created_at: string;
    current: boolean;
};
export type SignIn = {
    at: string;
    ok: boolean;
    ip: string | null;
    browser: string;
    burst: boolean;
};

const props = defineProps<{
    devices: Device[];
    history: SignIn[];
    revokeUrl: (id: number) => string;
    revokeOthersUrl?: string | null;
    title?: string;
}>();

async function end(d: Device): Promise<void> {
    if (
        await confirmDialog(`Sign out ${d.browser}${d.ip ? ` (${d.ip})` : ''}?`)
    ) {
        router.delete(props.revokeUrl(d.id), { preserveScroll: true });
    }
}
async function endOthers(): Promise<void> {
    if (
        props.revokeOthersUrl &&
        (await confirmDialog('Sign out every other device?'))
    ) {
        router.post(props.revokeOthersUrl, {}, { preserveScroll: true });
    }
}
</script>

<template>
    <div class="space-y-6">
        <div class="space-y-3">
            <Heading
                variant="small"
                :title="title ?? 'Where you\'re signed in'"
                description="Browsers and devices used in the last 30 days. Sign out any you don't recognise."
            />
            <ul class="divide-y rounded-md border text-sm">
                <li
                    v-for="d in devices"
                    :key="d.id"
                    class="flex flex-wrap items-center justify-between gap-2 px-3 py-2"
                >
                    <span>
                        <span class="font-medium">{{ d.browser }}</span>
                        <span
                            v-if="d.current"
                            class="ml-1 rounded bg-emerald-100 px-1.5 text-xs text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200"
                            >this device</span
                        >
                        <span class="block text-xs text-muted-foreground"
                            >{{ d.ip ?? 'Unknown IP' }} · active
                            {{ timeAgo(d.last_seen_at) }} · signed in
                            {{ formatDateTime(d.created_at) }}</span
                        >
                    </span>
                    <Button
                        v-if="!d.current"
                        size="sm"
                        variant="outline"
                        @click="end(d)"
                        >Sign out</Button
                    >
                </li>
                <li
                    v-if="devices.length === 0"
                    class="px-3 py-2 text-muted-foreground"
                >
                    No active sessions.
                </li>
            </ul>
            <Button
                v-if="revokeOthersUrl && devices.some((d) => !d.current)"
                size="sm"
                variant="ghost"
                @click="endOthers"
                >Sign out all other devices</Button
            >
        </div>

        <div class="space-y-3">
            <Heading
                variant="small"
                title="Recent sign-ins"
                description="The last 30 sign-ins and failed attempts. Several failures close together are marked."
            />
            <ul class="divide-y rounded-md border text-sm">
                <li
                    v-for="(h, i) in history"
                    :key="i"
                    class="flex flex-wrap items-center justify-between gap-2 px-3 py-1.5"
                    :class="h.burst ? 'bg-rose-50 dark:bg-rose-950/30' : ''"
                >
                    <span>
                        <span
                            :class="
                                h.ok
                                    ? 'text-emerald-700 dark:text-emerald-300'
                                    : 'text-rose-700 dark:text-rose-300'
                            "
                            >{{ h.ok ? 'Signed in' : 'Failed attempt' }}</span
                        >
                        <span
                            v-if="h.burst"
                            class="ml-1 text-xs font-medium text-rose-700 dark:text-rose-300"
                            >· many failures</span
                        >
                        <span class="text-muted-foreground">
                            · {{ h.browser }} · {{ h.ip ?? 'Unknown IP' }}</span
                        >
                    </span>
                    <span class="text-xs text-muted-foreground">{{
                        formatDateTime(h.at)
                    }}</span>
                </li>
                <li
                    v-if="history.length === 0"
                    class="px-3 py-2 text-muted-foreground"
                >
                    No sign-ins recorded yet.
                </li>
            </ul>
        </div>
    </div>
</template>
