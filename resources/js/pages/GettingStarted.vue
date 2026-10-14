<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import GettingStartedCard from '@/components/dashboard/GettingStartedCard.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { reopen, show } from '@/routes/getting-started';
import type { GettingStartedStep } from '@/types/admin';

/**
 * E-59: Help > Getting started. Shows the checklist and can put it back on the dashboard.
 */
const props = defineProps<{ steps: GettingStartedStep[]; hidden: boolean }>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Getting started', href: show() }] },
});

const done = props.steps.filter((s) => s.done).length;
</script>

<template>
    <Head title="Getting started" />

    <div class="flex max-w-2xl flex-1 flex-col gap-4 p-4">
        <Heading
            title="Getting started"
            description="The first things to try in your role. Full guides: ask your admin for the role guide."
        />
        <GettingStartedCard :steps="steps" :done="done" :total="steps.length" />
        <div v-if="hidden">
            <Button variant="outline" @click="router.post(reopen.url())"
                >Show it on my dashboard again</Button
            >
        </div>
    </div>
</template>
