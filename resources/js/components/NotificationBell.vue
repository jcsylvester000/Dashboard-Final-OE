<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Bell } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted } from 'vue';
import { toast } from 'vue-sonner';
import { getEcho, onConnectionChange } from '@/lib/echo';
import { index as inboxIndex } from '@/routes/inbox';
import type { PushedNotification } from '@/types/notifications';

/**
 * Header bell: unread count, live via Reverb when configured and connected,
 * otherwise refreshed every 60 seconds (also while Reverb is down). In-app only - no email.
 */
const page = usePage();
const unread = computed(() => page.props.notifications?.unread ?? 0);
const userId = computed(() => page.props.auth.user.id);

let poll: ReturnType<typeof setInterval> | undefined;
let channel: string | null = null;

function refresh(): void {
    router.reload({ only: ['notifications'], showProgress: false });
}

function startPolling(): void {
    poll ??= setInterval(() => {
        if (document.visibilityState === 'visible') {
            refresh();
        }
    }, 60_000);
}

function stopPolling(): void {
    if (poll) {
        clearInterval(poll);
        poll = undefined;
    }
}

onMounted(async () => {
    const echo = await getEcho();

    if (!echo) {
        startPolling();

        return;
    }

    channel = `App.Models.User.${userId.value}`;
    echo.private(channel).notification((n: PushedNotification) => {
        toast(n.title, {
            description: n.body ?? undefined,
            action: n.url
                ? { label: 'Open', onClick: () => router.visit(n.url) }
                : undefined,
        });
        refresh();
    });

    // E-125: live while the WebSocket is up; poll every 60 s while it is down.
    let wasPolling = false;
    onConnectionChange(echo, (connected) => {
        if (connected) {
            stopPolling();
            if (wasPolling) {
                refresh(); // catch up on anything missed while offline
            }
            wasPolling = false;
        } else {
            startPolling();
            wasPolling = true;
        }
    });
});

onBeforeUnmount(() => {
    stopPolling();
    const name = channel;
    if (name) {
        void getEcho().then((echo) => echo?.leave(name));
    }
});
</script>

<template>
    <Link
        :href="inboxIndex()"
        class="relative inline-flex size-9 items-center justify-center rounded-md hover:bg-accent"
        :aria-label="unread ? `Inbox, ${unread} unread` : 'Inbox'"
    >
        <Bell class="size-4" />
        <span
            v-if="unread > 0"
            class="absolute -top-0.5 -right-0.5 min-w-4 rounded-full bg-rose-600 px-1 text-center text-[10px] leading-4 font-semibold text-white"
            >{{ unread > 99 ? '99+' : unread }}</span
        >
    </Link>
</template>
