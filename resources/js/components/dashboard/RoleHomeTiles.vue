<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { RoleTile } from '@/types/admin';

/**
 * E-98: admin and Finance tiles. Each number is exactly what the linked page shows.
 */
defineProps<{ title: string; tiles: RoleTile[] }>();

const tone: Record<string, string> = {
    ok: 'border-l-emerald-500',
    warn: 'border-l-amber-500',
    bad: 'border-l-rose-500',
};
</script>

<template>
    <section v-if="tiles.length" :aria-label="title" class="space-y-2">
        <h2 class="text-sm font-medium text-muted-foreground">{{ title }}</h2>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <Link
                v-for="t in tiles"
                :key="t.key"
                :href="t.href"
                class="rounded-lg border border-l-4 bg-card p-3 transition hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                :class="tone[t.tone] ?? ''"
            >
                <p class="text-xs text-muted-foreground">{{ t.label }}</p>
                <p class="text-xl font-semibold tabular-nums">{{ t.value }}</p>
                <p class="text-xs text-muted-foreground">{{ t.hint }}</p>
            </Link>
        </div>
    </section>
</template>
