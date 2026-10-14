<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import { Toaster } from '@/components/ui/sonner';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

// E-87: reminder while the two-factor grace period runs.
const page = usePage();
const twoFactorDue = computed(() => page.props.auth.twoFactorDue ?? null);
</script>

<template>
    <AppShell variant="sidebar">
        <AppSidebar />
        <AppContent variant="sidebar" class="min-w-0 overflow-x-clip">
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <div
                v-if="twoFactorDue"
                class="mx-4 mt-3 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200"
                role="status"
            >
                Your role needs two-factor sign-in. Set it up by
                {{ new Date(twoFactorDue).toLocaleDateString() }}.
                <Link href="/settings/security" class="font-medium underline"
                    >Set up now</Link
                >
            </div>
            <slot />
        </AppContent>
        <Toaster />
    </AppShell>
</template>
