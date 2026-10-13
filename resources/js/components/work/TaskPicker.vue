<script setup lang="ts">
import { useDebounceFn } from '@vueuse/core';
import { ref, watch } from 'vue';
import { search } from '@/routes';

/**
 * Searchable task picker for one client (S1-10): type a title or key (BRW-24),
 * pick from the matches. Replaces the 200-option select shipped with every task page (B-21).
 */
const props = defineProps<{
    workspaceId: number;
    excludeId?: number;
    placeholder?: string;
}>();
const emit = defineEmits<{ pick: [id: number] }>();

type Hit = { id: number; key: string; title: string; status: { name: string } };

const q = ref('');
const open = ref(false);
const hits = ref<Hit[]>([]);
const active = ref(0);
const loading = ref(false);

const load = useDebounceFn(async () => {
    loading.value = true;
    try {
        const query: Record<string, string | number> = {
            q: q.value,
            workspace: props.workspaceId,
        };
        if (props.excludeId) {
            query.exclude = props.excludeId;
        }
        const res = await fetch(search.url({ query }), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        hits.value = res.ok
            ? ((await res.json()) as { tasks: Hit[] }).tasks
            : [];
        active.value = 0;
    } finally {
        loading.value = false;
    }
}, 200);

watch(q, () => {
    open.value = true;
    void load();
});

function focus(): void {
    open.value = true;
    void load();
}

function choose(h: Hit | undefined): void {
    if (!h) {
        return;
    }
    emit('pick', h.id);
    q.value = '';
    open.value = false;
}

function onKey(e: KeyboardEvent): void {
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        active.value = Math.min(active.value + 1, hits.value.length - 1);
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        active.value = Math.max(active.value - 1, 0);
    } else if (e.key === 'Enter') {
        e.preventDefault();
        choose(hits.value[active.value]);
    } else if (e.key === 'Escape') {
        open.value = false;
    }
}
</script>

<template>
    <div
        class="relative"
        @focusout="
            (e: FocusEvent) =>
                !(e.currentTarget as HTMLElement).contains(
                    e.relatedTarget as Node,
                ) && (open = false)
        "
    >
        <input
            v-model="q"
            type="text"
            role="combobox"
            :aria-expanded="open && hits.length > 0"
            aria-autocomplete="list"
            :aria-label="placeholder ?? 'Search tasks'"
            :placeholder="placeholder ?? 'Search tasks or type a key'"
            class="h-9 w-full rounded-md border bg-background px-3 text-sm"
            @focus="focus"
            @keydown="onKey"
        />
        <ul
            v-if="open"
            role="listbox"
            class="absolute z-20 mt-1 max-h-64 w-full overflow-y-auto rounded-md border bg-popover p-1 text-sm shadow-md"
        >
            <li
                v-if="loading && !hits.length"
                class="px-2 py-1.5 text-muted-foreground"
            >
                Searching…
            </li>
            <li
                v-else-if="!hits.length"
                class="px-2 py-1.5 text-muted-foreground"
            >
                No open tasks match.
            </li>
            <li
                v-for="(h, i) in hits"
                :key="h.id"
                role="option"
                tabindex="-1"
                :aria-selected="i === active"
                class="cursor-pointer rounded px-2 py-1.5"
                :class="i === active ? 'bg-accent' : ''"
                @mouseenter="active = i"
                @mousedown.prevent="choose(h)"
            >
                <span class="font-mono text-xs text-muted-foreground">{{
                    h.key
                }}</span>
                {{ h.title }}
                <span class="text-xs text-muted-foreground"
                    >· {{ h.status.name }}</span
                >
            </li>
        </ul>
    </div>
</template>
