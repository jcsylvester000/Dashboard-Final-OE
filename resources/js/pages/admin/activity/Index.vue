<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, reactive, watch } from 'vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import Pagination from '@/components/Pagination.vue';
import { formatDateTime } from '@/lib/format';
import { csv as exportRoute, index } from '@/routes/admin/activity';
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
    filters: {
        action?: string;
        actor?: number | string;
        subject?: string;
        from?: string;
        to?: string;
    };
    actions: string[];
    actors: { id: number; name: string }[];
    subjects: { value: string; label: string }[];
    retentionMonths: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Activity log', href: index() }],
    },
});

const form = reactive({
    action: props.filters.action ?? '',
    actor: props.filters.actor ? String(props.filters.actor) : '',
    subject: props.filters.subject ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});
const query = computed(() =>
    Object.fromEntries(Object.entries(form).filter(([, v]) => v !== '')),
);
// E-91: the export uses exactly the filters on screen.
const exportUrl = computed(() => exportRoute.url({ query: query.value }));

watch(form, () => {
    router.get(
        index.url({ query: query.value }),
        {},
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
});

const show = (v: unknown): string =>
    v === null || v === undefined || v === ''
        ? '—'
        : typeof v === 'object'
          ? JSON.stringify(v)
          : String(v);

/** E-91: "before"/"after" (or "from"/"to") pairs shown as field: old → new. */
function changes(
    p: Record<string, unknown> | null,
): { key: string; from: string; to: string }[] {
    const before = (p?.before ?? p?.from) as
        | Record<string, unknown>
        | undefined;
    const after = (p?.after ?? p?.to) as Record<string, unknown> | undefined;
    if (
        !before ||
        !after ||
        typeof before !== 'object' ||
        typeof after !== 'object'
    ) {
        return [];
    }
    return Object.keys({ ...before, ...after })
        .filter((k) => show(before[k]) !== show(after[k]))
        .map((k) => ({ key: k, from: show(before[k]), to: show(after[k]) }));
}

function details(props: Record<string, unknown> | null): string {
    if (!props) {
        return '';
    }

    return Object.entries(props)
        .map(
            ([k, v]) =>
                `${k}: ${typeof v === 'object' ? JSON.stringify(v) : String(v)}`,
        )
        .join(' · ');
}
</script>

<template>
    <Head title="Activity log" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Activity log</h1>
            <p class="text-sm text-muted-foreground">
                Append-only record of sign-ins and admin actions. Rows older
                than {{ retentionMonths }} months move to monthly archive files
                (money records are kept 10 years).
            </p>
        </div>

        <div class="grid gap-2 sm:grid-cols-3 lg:grid-cols-6">
            <NativeSelect v-model="form.action" aria-label="Action">
                <option value="">All actions</option>
                <option v-for="a in actions" :key="a" :value="a">
                    {{ a }}
                </option>
            </NativeSelect>
            <NativeSelect v-model="form.actor" aria-label="Who">
                <option value="">Anyone</option>
                <option v-for="u in actors" :key="u.id" :value="String(u.id)">
                    {{ u.name }}
                </option>
            </NativeSelect>
            <NativeSelect v-model="form.subject" aria-label="On">
                <option value="">Any record</option>
                <option v-for="t in subjects" :key="t.value" :value="t.value">
                    {{ t.label }}
                </option>
            </NativeSelect>
            <Input v-model="form.from" type="date" aria-label="From" />
            <Input v-model="form.to" type="date" aria-label="To" />
            <Button variant="outline" as-child>
                <a :href="exportUrl">Export CSV</a>
            </Button>
        </div>

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-left text-sm">
                <thead
                    class="bg-muted/50 text-xs text-muted-foreground uppercase"
                >
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
                        <td
                            class="px-4 py-2 whitespace-nowrap text-muted-foreground"
                        >
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
                            <template v-if="changes(log.properties).length">
                                <span
                                    v-for="c in changes(log.properties)"
                                    :key="c.key"
                                    class="mr-2 inline-block"
                                    >{{ c.key }}:
                                    <span class="line-through">{{
                                        c.from
                                    }}</span>
                                    → {{ c.to }}</span
                                >
                            </template>
                            <template v-else>{{
                                details(log.properties)
                            }}</template>
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
