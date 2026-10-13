<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    BookOpen,
    Bug,
    Building2,
    FolderKanban,
    Search,
    Wrench,
} from '@lucide/vue';
import { useDebounceFn, useEventListener } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { search as searchRoute } from '@/routes';
import { index as inbox } from '@/routes/inbox';
import { mine } from '@/routes/tasks';
import { timesheet } from '@/routes/time';

/**
 * Global search and keyboard shortcuts (brutal scenario J3).
 *   /  or Ctrl+K  search      c  new task      ?  shortcut help
 *   g then m / t / i          my tasks / timesheet / inbox
 * Shortcuts are ignored while typing in a field.
 */
type Hit = {
    kind: 'task' | 'project' | 'client';
    key: string;
    title: string;
    sub: string;
    url: string;
    type?: string;
};
type Result = {
    tasks: {
        id: number;
        key: string;
        type: string;
        title: string;
        status: { name: string };
        assignee: string | null;
        workspace: string;
        url: string;
    }[];
    projects: { id: number; name: string; workspace: string; url: string }[];
    clients: { id: number; name: string; key: string | null; url: string }[];
};

const open = ref(false);
const help = ref(false);
const q = ref('');
const loading = ref(false);
const result = ref<Result | null>(null);
const active = ref(0);
let pendingG = 0;

const hits = computed<Hit[]>(() => {
    const r = result.value;
    if (!r) {
        return [];
    }

    return [
        ...r.tasks.map((t) => ({
            kind: 'task' as const,
            key: `t${t.id}`,
            title: `${t.key} · ${t.title}`,
            sub: `${t.workspace} · ${t.status.name}${t.assignee ? ' · ' + t.assignee : ''}`,
            url: t.url,
            type: t.type,
        })),
        ...r.projects.map((p) => ({
            kind: 'project' as const,
            key: `p${p.id}`,
            title: p.name,
            sub: `Project · ${p.workspace}`,
            url: p.url,
        })),
        ...r.clients.map((c) => ({
            kind: 'client' as const,
            key: `c${c.id}`,
            title: c.name,
            sub: `Client${c.key ? ' · ' + c.key : ''}`,
            url: c.url,
        })),
    ];
});

const fetchResults = useDebounceFn(async () => {
    loading.value = true;
    try {
        const res = await fetch(searchRoute.url({ query: { q: q.value } }), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        result.value = res.ok ? ((await res.json()) as Result) : null;
        active.value = 0;
    } finally {
        loading.value = false;
    }
}, 200);

watch(q, () => void fetchResults());

function show(): void {
    q.value = '';
    result.value = null;
    open.value = true;
    void fetchResults();
}

function go(hit: Hit | undefined): void {
    if (!hit) {
        return;
    }
    open.value = false;
    router.visit(hit.url);
}

function onInputKey(e: KeyboardEvent): void {
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        active.value = Math.min(active.value + 1, hits.value.length - 1);
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        active.value = Math.max(active.value - 1, 0);
    } else if (e.key === 'Enter') {
        e.preventDefault();
        go(hits.value[active.value]);
    }
}

function typing(target: EventTarget | null): boolean {
    const el = target as HTMLElement | null;

    return (
        !!el &&
        (el.isContentEditable ||
            ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName))
    );
}

useEventListener(window, 'keydown', (e: KeyboardEvent) => {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        show();

        return;
    }
    if (
        e.ctrlKey ||
        e.metaKey ||
        e.altKey ||
        typing(e.target) ||
        open.value ||
        document.querySelector('[role="dialog"]')
    ) {
        return;
    }

    if (pendingG && Date.now() - pendingG < 1200) {
        pendingG = 0;
        const dest = { m: mine.url(), t: timesheet.url(), i: inbox.url() }[
            e.key
        ];
        if (dest) {
            e.preventDefault();
            router.visit(dest);
        }

        return;
    }

    if (e.key === '/') {
        e.preventDefault();
        show();
    } else if (e.key === 'c') {
        e.preventDefault();
        window.dispatchEvent(new CustomEvent('oe:new-task'));
    } else if (e.key === '?') {
        e.preventDefault();
        help.value = true;
    } else if (e.key === 'g') {
        pendingG = Date.now();
    }
});

const shortcuts: [string, string][] = [
    ['/  or  Ctrl K', 'Search tasks, projects and clients'],
    ['c', 'New task'],
    ['g  then  m', 'My tasks'],
    ['g  then  t', 'My timesheet'],
    ['g  then  i', 'Inbox'],
    ['?', 'This help'],
];
const filters: [string, string][] = [
    ['BRW-24', 'Open a task by its key'],
    ['type:bug', 'Bugs (also story, task, chore)'],
    ['priority:urgent', 'By priority'],
    ['@me', 'Assigned to me'],
    ['in:BRW', 'In one client'],
    ['open', 'Not done'],
];
</script>

<template>
    <Button
        variant="outline"
        size="sm"
        class="hidden gap-2 text-muted-foreground sm:inline-flex"
        aria-label="Search"
        @click="show"
    >
        <Search class="size-4" /> Search
        <kbd class="rounded border px-1 text-[10px]">/</kbd>
    </Button>
    <Button
        variant="ghost"
        size="icon"
        class="sm:hidden"
        aria-label="Search"
        @click="show"
        ><Search class="size-4"
    /></Button>

    <Dialog v-model:open="open">
        <DialogContent class="top-[15%] translate-y-0 gap-2 p-0 sm:max-w-xl">
            <DialogHeader class="sr-only">
                <DialogTitle>Search</DialogTitle>
                <DialogDescription
                    >Search tasks, projects and clients you can
                    open.</DialogDescription
                >
            </DialogHeader>
            <div class="flex items-center gap-2 border-b px-3">
                <Search class="size-4 shrink-0 text-muted-foreground" />
                <input
                    v-model="q"
                    v-focus
                    class="h-12 w-full bg-transparent text-sm outline-none"
                    placeholder="Search or try BRW-24, type:bug @me open"
                    aria-label="Search"
                    role="combobox"
                    aria-controls="global-search-results"
                    :aria-expanded="hits.length > 0"
                    :aria-activedescendant="
                        hits[active] ? `gs-${hits[active].key}` : undefined
                    "
                    @keydown="onInputKey"
                />
            </div>
            <ul
                id="global-search-results"
                role="listbox"
                class="max-h-[60vh] overflow-y-auto px-1 pb-2"
            >
                <li
                    v-if="!loading && result && hits.length === 0"
                    class="px-3 py-6 text-center text-sm text-muted-foreground"
                >
                    Nothing found.
                </li>
                <li
                    v-if="!q && hits.length"
                    class="px-3 pt-1 pb-1 text-xs text-muted-foreground"
                >
                    My open work
                </li>
                <li
                    v-for="(h, i) in hits"
                    :id="`gs-${h.key}`"
                    :key="h.key"
                    role="option"
                    :aria-selected="i === active"
                    class="flex cursor-pointer items-start gap-2 rounded-md px-3 py-2 text-sm"
                    :class="i === active ? 'bg-accent' : ''"
                    @mouseenter="active = i"
                    @click="go(h)"
                >
                    <Bug
                        v-if="h.type === 'bug'"
                        class="mt-0.5 size-4 text-rose-600"
                    />
                    <BookOpen
                        v-else-if="h.type === 'story'"
                        class="mt-0.5 size-4 text-emerald-600"
                    />
                    <Wrench
                        v-else-if="h.type === 'chore'"
                        class="mt-0.5 size-4 text-slate-500"
                    />
                    <FolderKanban
                        v-else-if="h.kind === 'project'"
                        class="mt-0.5 size-4 text-muted-foreground"
                    />
                    <Building2
                        v-else-if="h.kind === 'client'"
                        class="mt-0.5 size-4 text-muted-foreground"
                    />
                    <span v-else class="mt-0.5 size-4" />
                    <span class="min-w-0">
                        <span class="block truncate">{{ h.title }}</span>
                        <span
                            class="block truncate text-xs text-muted-foreground"
                            >{{ h.sub }}</span
                        >
                    </span>
                </li>
            </ul>
            <p class="border-t px-3 py-2 text-xs text-muted-foreground">
                ↑↓ to move · Enter to open · Esc to close ·
                <button
                    type="button"
                    class="underline"
                    @click="((open = false), (help = true))"
                >
                    shortcuts
                </button>
            </p>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="help">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Keyboard shortcuts</DialogTitle>
                <DialogDescription
                    >Work anywhere except while typing in a
                    field.</DialogDescription
                >
            </DialogHeader>
            <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 text-sm">
                <template v-for="[k, label] in shortcuts" :key="k">
                    <dt>
                        <kbd class="rounded border px-1.5 font-mono text-xs">{{
                            k
                        }}</kbd>
                    </dt>
                    <dd>{{ label }}</dd>
                </template>
            </dl>
            <p class="pt-2 text-xs font-medium">In the search box</p>
            <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 text-sm">
                <template v-for="[k, label] in filters" :key="k">
                    <dt>
                        <code class="font-mono text-xs">{{ k }}</code>
                    </dt>
                    <dd>{{ label }}</dd>
                </template>
            </dl>
        </DialogContent>
    </Dialog>
</template>
