<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { AlarmClock, Check, CheckCheck, RotateCcw } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { formatDateTime, timeAgo } from '@/lib/format';
import { done, index, open, read, restore, snooze } from '@/routes/inbox';
import { edit as notificationSettings } from '@/routes/notifications';
import type { Paginated } from '@/types/admin';
import type { InboxItem, NotificationKind } from '@/types/notifications';

type Tab = 'attention' | 'snoozed' | 'done' | 'all';

const props = defineProps<{
    tab: Tab;
    counts: Record<Tab, number>;
    items: Paginated<InboxItem>;
    kinds: Record<NotificationKind, string>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Inbox', href: index() }],
    },
});

const tabs: { key: Tab; label: string }[] = [
    { key: 'attention', label: 'Needs my attention' },
    { key: 'snoozed', label: 'Snoozed' },
    { key: 'done', label: 'Done' },
    { key: 'all', label: 'All' },
];

const kindColor: Record<NotificationKind, string> = {
    assigned: 'bg-sky-500',
    mentioned: 'bg-violet-500',
    handoff: 'bg-amber-500',
    comment: 'bg-slate-400',
    status: 'bg-slate-400',
    due_soon: 'bg-orange-500',
    overdue: 'bg-rose-600',
    escalation: 'bg-rose-700',
    invoice_overdue: 'bg-fuchsia-600',
};

const opts = { preserveScroll: true };

function markRead(ids: string[]): void {
    router.post(read.url(), { ids }, opts);
}

function markAllRead(): void {
    router.post(read.url(), { all: true }, opts);
}

function markDone(ids: string[]): void {
    router.post(done.url(), { ids }, opts);
}

function snoozeItem(id: string, until: '1h' | 'tomorrow' | 'next_week'): void {
    router.post(snooze.url(id), { until }, opts);
}

function restoreItem(id: string): void {
    router.post(restore.url(id), {}, opts);
}

function doneAllShown(): void {
    markDone(props.items.data.filter((i) => !i.done).map((i) => i.id));
}
</script>

<template>
    <Head title="Inbox" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                title="Inbox"
                description="Assignments, mentions, handoffs and due-date alerts. In-app only."
            />
            <div class="flex gap-2">
                <Button variant="outline" size="sm" :disabled="counts.attention === 0" @click="markAllRead">
                    <CheckCheck class="size-4" /> Mark all read
                </Button>
                <Button
                    v-if="tab === 'attention' && items.data.length > 0"
                    variant="outline"
                    size="sm"
                    @click="doneAllShown"
                >
                    <Check class="size-4" /> Done with this page
                </Button>
                <Button variant="ghost" size="sm" as-child>
                    <Link :href="notificationSettings()">Settings</Link>
                </Button>
            </div>
        </div>

        <nav class="flex flex-wrap gap-1 border-b text-sm" aria-label="Inbox filters">
            <Link
                v-for="t in tabs"
                :key="t.key"
                :href="index({ query: { tab: t.key } })"
                class="-mb-px border-b-2 px-3 py-2"
                :class="t.key === tab ? 'border-foreground font-medium' : 'border-transparent text-muted-foreground hover:text-foreground'"
            >
                {{ t.label }}
                <span class="ml-1 text-xs text-muted-foreground">{{ counts[t.key] }}</span>
            </Link>
        </nav>

        <p v-if="items.data.length === 0" class="py-10 text-center text-sm text-muted-foreground">
            {{ tab === 'attention' ? 'Nothing needs your attention. Nice.' : 'Nothing here.' }}
        </p>

        <ul v-else class="divide-y rounded-lg border">
            <li
                v-for="item in items.data"
                :key="item.id"
                class="flex items-start gap-3 p-3 text-sm"
                :class="{ 'bg-muted/40': !item.read }"
            >
                <span class="mt-1.5 size-2 shrink-0 rounded-full" :class="kindColor[item.kind]" />
                <div class="min-w-0 flex-1">
                    <Link :href="open(item.id)" class="block hover:underline">
                        <span :class="item.read ? '' : 'font-semibold'">{{ item.title }}</span>
                    </Link>
                    <p v-if="item.body" class="truncate text-muted-foreground">{{ item.body }}</p>
                    <p class="mt-1 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                        <Badge variant="outline">{{ kinds[item.kind] }}</Badge>
                        <span v-if="item.workspace">{{ item.workspace }}</span>
                        <span :title="formatDateTime(item.created_at)">{{ timeAgo(item.created_at) }}</span>
                        <span v-if="item.quiet">· digest</span>
                        <span v-if="item.snoozed_until">· until {{ formatDateTime(item.snoozed_until) }}</span>
                    </p>
                </div>
                <div class="flex shrink-0 items-center gap-1">
                    <template v-if="item.done || item.snoozed_until">
                        <Button variant="ghost" size="sm" @click="restoreItem(item.id)">
                            <RotateCcw class="size-4" /> Back to inbox
                        </Button>
                    </template>
                    <template v-else>
                        <Button v-if="!item.read" variant="ghost" size="sm" @click="markRead([item.id])">Mark read</Button>
                        <DropdownMenu>
                            <DropdownMenuTrigger as-child>
                                <Button variant="ghost" size="icon" aria-label="Snooze">
                                    <AlarmClock class="size-4" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuItem @select="snoozeItem(item.id, '1h')">For 1 hour</DropdownMenuItem>
                                <DropdownMenuItem @select="snoozeItem(item.id, 'tomorrow')">Until tomorrow 9am</DropdownMenuItem>
                                <DropdownMenuItem @select="snoozeItem(item.id, 'next_week')">Until Monday 9am</DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                        <Button variant="ghost" size="icon" aria-label="Done" @click="markDone([item.id])">
                            <Check class="size-4" />
                        </Button>
                    </template>
                </div>
            </li>
        </ul>

        <Pagination :links="items.links" :from="items.from" :to="items.to" :total="items.total" />
    </div>
</template>
