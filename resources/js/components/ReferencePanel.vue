<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ArrowDownLeft, ArrowUpRight, Link2, X } from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import { ref, watch } from 'vue';
import RecordLinkController from '@/actions/App/Http/Controllers/Workspaces/RecordLinkController';
import { Input } from '@/components/ui/input';
import { search } from '@/routes/references';
import type { LinkRow, ReferenceItem } from '@/types/workspace';

/**
 * Cross-references for a record: what it points to, what points back to it,
 * and a search box to add a new reference (any workspace you can see).
 */
const props = defineProps<{
    sourceType: 'workspace' | 'project';
    sourceId: number;
    links: LinkRow[];
    canEdit: boolean;
}>();

const term = ref('');
const results = ref<ReferenceItem[]>([]);
const loading = ref(false);

const runSearch = useDebounceFn(async () => {
    if (term.value.trim().length < 2) {
        results.value = [];
        return;
    }

    loading.value = true;
    try {
        const response = await fetch(search.url({ query: { q: term.value } }), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        const json = (await response.json()) as { results: ReferenceItem[] };
        results.value = json.results.filter(
            (r) => !(r.type === props.sourceType && r.record_id === props.sourceId),
        );
    } catch {
        results.value = [];
    } finally {
        loading.value = false;
    }
}, 250);

watch(term, runSearch);

function add(item: ReferenceItem): void {
    router.post(
        RecordLinkController.store.url(),
        {
            source_type: props.sourceType,
            source_id: props.sourceId,
            target_type: item.type,
            target_id: item.record_id,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                term.value = '';
                results.value = [];
            },
        },
    );
}

function remove(link: LinkRow): void {
    router.delete(RecordLinkController.destroy.url(link.id), {
        preserveScroll: true,
    });
}
</script>

<template>
    <div class="space-y-3">
        <p v-if="links.length === 0" class="text-sm text-muted-foreground">
            No references yet.
        </p>
        <ul v-else class="divide-y text-sm">
            <li
                v-for="link in links"
                :key="`${link.direction}-${link.id}`"
                class="flex items-center justify-between gap-2 py-2"
            >
                <span class="flex min-w-0 items-center gap-2">
                    <ArrowUpRight
                        v-if="link.direction === 'outgoing'"
                        class="size-4 shrink-0 text-muted-foreground"
                        aria-label="References"
                    />
                    <ArrowDownLeft
                        v-else
                        class="size-4 shrink-0 text-muted-foreground"
                        aria-label="Referenced by"
                    />
                    <Link :href="link.url" class="truncate font-medium hover:underline">
                        {{ link.label }}
                    </Link>
                    <span class="truncate text-xs text-muted-foreground">{{
                        link.context
                    }}</span>
                </span>
                <button
                    v-if="canEdit && link.direction === 'outgoing'"
                    type="button"
                    class="rounded p-1 text-muted-foreground hover:bg-accent"
                    :aria-label="`Remove reference to ${link.label}`"
                    @click="remove(link)"
                >
                    <X class="size-4" />
                </button>
            </li>
        </ul>

        <div v-if="canEdit" class="relative">
            <div class="relative">
                <Link2
                    class="pointer-events-none absolute top-2.5 left-2.5 size-4 text-muted-foreground"
                />
                <Input
                    v-model="term"
                    class="pl-8"
                    placeholder="Link a project or workspace…"
                    aria-label="Search records to reference"
                />
            </div>
            <ul
                v-if="results.length || loading"
                class="absolute z-20 mt-1 w-full overflow-hidden rounded-md border bg-popover text-sm shadow-md"
            >
                <li v-if="loading" class="px-3 py-2 text-muted-foreground">
                    Searching…
                </li>
                <li
                    v-for="r in results"
                    :key="`${r.type}-${r.record_id}`"
                    class="cursor-pointer px-3 py-2 hover:bg-accent"
                    @click="add(r)"
                >
                    <div class="font-medium">{{ r.label }}</div>
                    <div class="text-xs text-muted-foreground">{{ r.context }}</div>
                </li>
            </ul>
        </div>
    </div>
</template>
