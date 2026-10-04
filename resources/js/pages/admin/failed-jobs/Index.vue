<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { RotateCcw, Trash2 } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/format';
import { destroy, index, retry } from '@/routes/admin/failed-jobs';
import type { Paginated } from '@/types/admin';

type FailedJob = { uuid: string; job: string; queue: string; error: string; failed_at: string };

defineProps<{ jobs: Paginated<FailedJob> }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Failed jobs', href: index() }],
    },
});

const opts = { preserveScroll: true };

function retryJobs(ids: string[]): void {
    router.post(retry.url(), { ids }, opts);
}

function discardJobs(ids: string[]): void {
    if (ids.length === 0 && !window.confirm('Discard every failed job?')) {
        return;
    }
    router.post(destroy.url(), { ids }, opts);
}
</script>

<template>
    <Head title="Failed jobs" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                title="Failed jobs"
                description="Background work (alerts, live updates) that failed. Retry after fixing the cause, or discard."
            />
            <div v-if="jobs.total > 0" class="flex gap-2">
                <Button variant="outline" size="sm" @click="retryJobs([])"><RotateCcw class="size-4" /> Retry all</Button>
                <Button variant="outline" size="sm" @click="discardJobs([])"><Trash2 class="size-4" /> Discard all</Button>
            </div>
        </div>

        <p v-if="jobs.data.length === 0" class="py-10 text-center text-sm text-muted-foreground">No failed jobs.</p>

        <div v-else class="overflow-x-auto rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-xs text-muted-foreground">
                    <tr>
                        <th class="px-3 py-2 font-medium">Job</th>
                        <th class="px-3 py-2 font-medium">Error</th>
                        <th class="px-3 py-2 font-medium">Failed</th>
                        <th class="px-3 py-2" />
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="j in jobs.data" :key="j.uuid" class="align-top">
                        <td class="px-3 py-2">
                            <div class="font-medium">{{ j.job }}</div>
                            <div class="text-xs text-muted-foreground">{{ j.queue }}</div>
                        </td>
                        <td class="max-w-md px-3 py-2 font-mono text-xs break-words">{{ j.error }}</td>
                        <td class="px-3 py-2 text-xs whitespace-nowrap">{{ formatDateTime(j.failed_at) }}</td>
                        <td class="px-3 py-2 whitespace-nowrap">
                            <Button variant="ghost" size="sm" @click="retryJobs([j.uuid])">Retry</Button>
                            <Button variant="ghost" size="sm" @click="discardJobs([j.uuid])">Discard</Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :links="jobs.links" :from="jobs.from" :to="jobs.to" :total="jobs.total" />
    </div>
</template>
