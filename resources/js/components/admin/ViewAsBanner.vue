<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Eye } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/format';
import { stop } from '@/routes/view-as';

/**
 * E-90: always visible while a super admin views the app as someone (read-only).
 */
const page = usePage();
const viewAs = computed(() => page.props.viewAs);

function leave(): void {
    router.post(stop.url());
}
</script>

<template>
    <div
        v-if="viewAs"
        role="status"
        class="sticky top-0 z-40 flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-amber-300 bg-amber-100 px-4 py-2 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100"
    >
        <Eye class="size-4 shrink-0" aria-hidden="true" />
        <span
            ><strong>Viewing as {{ viewAs.name }}</strong> · read-only<template
                v-if="viewAs.expires_at"
            >
                · ends {{ formatDateTime(viewAs.expires_at) }}</template
            ><template v-if="viewAs.reason">
                · {{ viewAs.reason }}</template
            ></span
        >
        <Button size="sm" variant="outline" class="ml-auto" @click="leave"
            >Stop viewing as</Button
        >
    </div>
</template>
