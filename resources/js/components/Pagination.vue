<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { PaginationLink } from '@/types/admin';

defineProps<{
    links: PaginationLink[];
    from: number | null;
    to: number | null;
    total: number;
}>();

function clean(label: string): string {
    return label
        .replace('&laquo;', '«')
        .replace('&raquo;', '»')
        .replace(/<[^>]*>/g, '');
}
</script>

<template>
    <nav
        v-if="total > 0"
        class="flex flex-wrap items-center justify-between gap-3 text-sm"
        aria-label="Pagination"
    >
        <p class="text-muted-foreground">
            Showing {{ from }}–{{ to }} of {{ total }}
        </p>
        <div class="flex flex-wrap gap-1">
            <template v-for="(link, i) in links" :key="i">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    preserve-scroll
                    class="rounded-md border px-3 py-1"
                    :class="
                        link.active
                            ? 'border-primary bg-primary text-primary-foreground'
                            : 'hover:bg-accent'
                    "
                    :aria-current="link.active ? 'page' : undefined"
                >
                    {{ clean(link.label) }}
                </Link>
                <span
                    v-else
                    class="rounded-md border px-3 py-1 text-muted-foreground opacity-50"
                >
                    {{ clean(link.label) }}
                </span>
            </template>
        </div>
    </nav>
</template>
