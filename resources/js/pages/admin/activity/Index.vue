<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';
import NativeSelect from '@/components/NativeSelect.vue';
import Pagination from '@/components/Pagination.vue';
import { formatDateTime } from '@/lib/format';
import { index } from '@/routes/admin/activity';
import type { Paginated } from '@/types/admin';

type LogRow = {
    id: number;
    action: string;
    actor: string | null;
    subject: string | null;
    properties: Record<string, unknown> | null;
    ip: string | null;
    at: string;
};

const props = defineProps<{
    logs: Paginated<LogRow>;
    filters: { action?: string; actor?: number | string };
    actions: string[];
    actors: { id: number; name: string }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Activity log', href: index() }],
    },
});

const form = reactive({
    action: props.filters.action ?? '',
    actor: props.filters.actor ? String(props.filters.actor) : '',
});

watch(form, () => {
    const query = Object.fromEntries(
        Object.entries(form).filter(([, v]) => v !== ''),
    );
    router.get(index.url({ query }), {}, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
});

function details(props: Record<string, unknown> | null): string {
    if (!props) {
        return '';
    }

    return Object.entries(props)
        .map(([k, v]) => `${k}: ${typeof v === 'object' ? JSON.stringify(v) : String(v)}`)
        .join(' · ');
}
</script>

<template>
    <Head title="Activity log" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Activity log</h1>
            <p class="text-sm text-muted-foreground">
                Append-only record of sign-ins and admin actions.
            </p>
        </div>

        <div class="grid gap-2 sm:grid-cols-2 lg:max-w-xl">
            <NativeSelect v-model="form.action" aria-label="Action">
                <option value="">All actions</option>
                <option v-for="a in actions" :key="a" :value="a">{{ a }}</option>
            </NativeSelect>
            <NativeSelect v-model="form.actor" aria-label="Who">
                <option value="">Anyone</option>
                <option v-for="u in actors" :key="u.id" :value="String(u.id)">
                    {{ u.name }}
                </option>
            </NativeSelect>
        </div>

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-left text-sm">
                <thead class="bg-muted/50 text-xs text-muted-foreground uppercase">
                    <tr>
                        <th class="px-4 py-2 font-medium">When</th>
                        <th class="px-4 py-2 font-medium">Who</th>
                        <th class="px-4 py-2 font-medium">Action</th>
                        <th class="px-4 py-2 font-medium">On</th>
                        <th class="px-4 py-2 font-medium">Details</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-if="logs.data.length === 0">
                        <td
                            colspan="5"
                            class="px-4 py-8 text-center text-muted-foreground"
                        >
                            Nothing logged yet.
                        </td>
                    </tr>
                    <tr v-for="log in logs.data" :key="log.id">
                        <td class="px-4 py-2 whitespace-nowrap text-muted-foreground">
                            {{ formatDateTime(log.at) }}
                        </td>
                        <td class="px-4 py-2">{{ log.actor ?? 'System' }}</td>
                        <td class="px-4 py-2 font-mono text-xs">
                            {{ log.action }}
                        </td>
                        <td class="px-4 py-2">{{ log.subject ?? '—' }}</td>
                        <td
                            class="max-w-md truncate px-4 py-2 text-xs text-muted-foreground"
                            :title="details(log.properties)"
                        >
                            {{ details(log.properties) }}
                            <span v-if="log.ip"> · {{ log.ip }}</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination
            :links="logs.links"
            :from="logs.from"
            :to="logs.to"
            :total="logs.total"
        />
    </div>
</template>
