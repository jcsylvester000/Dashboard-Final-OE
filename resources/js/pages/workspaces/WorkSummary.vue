<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Download } from '@lucide/vue';
import { computed, reactive } from 'vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import WorkspaceHeader from '@/components/WorkspaceHeader.vue';
import { formatMinutes } from '@/lib/format';
import { index as workspacesIndex } from '@/routes/workspaces';
import { csv, index, pdf } from '@/routes/workspaces/work-summary';
import type { WorkspaceHeader as Header } from '@/types/workspace';

type Person = { name: string; minutes: number };
type TaskRow = {
    id: number | null;
    title: string;
    department: string;
    status: string | null;
    minutes: number;
    people: Person[];
};
type SectionRow = { title: string; status: string | null; minutes: number; [key: string]: string | number | null };

const props = defineProps<{
    workspace: Header;
    filters: { from: string; to: string; department: string | null };
    summary: {
        total_minutes: number;
        tasks: TaskRow[];
        departments: { name: string; minutes: number; people: Person[] }[];
        marketing: SectionRow[];
        seo: SectionRow[];
        completed_without_time: { title: string; department: string; completed_on: string | null }[];
    };
    departments: { slug: string; name: string }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Workspaces', href: workspacesIndex() }],
    },
});

const form = reactive({ from: props.filters.from, to: props.filters.to, department: props.filters.department ?? '' });

const query = computed(() => {
    const q: Record<string, string> = { from: form.from, to: form.to };
    if (form.department) {
        q.department = form.department;
    }

    return q;
});

function apply(): void {
    router.get(index.url(props.workspace.slug, { query: query.value }), {}, { preserveState: true, preserveScroll: true });
}
</script>

<template>
    <Head :title="`${workspace.name} - Work summary`" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <WorkspaceHeader :workspace="workspace" />

        <form class="flex flex-wrap items-end gap-2 text-sm" @submit.prevent="apply">
            <label class="grid gap-1"><span class="text-xs text-muted-foreground">From</span><Input v-model="form.from" type="date" class="w-40" /></label>
            <label class="grid gap-1"><span class="text-xs text-muted-foreground">To</span><Input v-model="form.to" type="date" class="w-40" /></label>
            <label class="grid gap-1">
                <span class="text-xs text-muted-foreground">Department</span>
                <NativeSelect v-model="form.department" class="w-44">
                    <option value="">All departments</option>
                    <option v-for="d in departments" :key="d.slug" :value="d.slug">{{ d.name }}</option>
                </NativeSelect>
            </label>
            <Button size="sm" type="submit">Apply</Button>
            <span class="ml-auto flex gap-2">
                <Button size="sm" variant="outline" as-child>
                    <a :href="csv.url(workspace.slug, { query })"><Download class="size-4" /> CSV</a>
                </Button>
                <Button size="sm" variant="outline" as-child>
                    <a :href="pdf.url(workspace.slug, { query })"><Download class="size-4" /> PDF</a>
                </Button>
            </span>
        </form>

        <div class="grid gap-4 lg:grid-cols-3">
            <Card>
                <CardHeader>
                    <CardDescription>Time logged</CardDescription>
                    <CardTitle class="text-2xl">{{ formatMinutes(summary.total_minutes) }}</CardTitle>
                </CardHeader>
                <CardContent>
                    <ul class="space-y-2 text-sm">
                        <li v-for="d in summary.departments" :key="d.name">
                            <div class="flex justify-between font-medium"><span>{{ d.name }}</span><span>{{ formatMinutes(d.minutes) }}</span></div>
                            <div v-for="p in d.people" :key="p.name" class="flex justify-between pl-3 text-xs text-muted-foreground">
                                <span>{{ p.name }}</span><span>{{ formatMinutes(p.minutes) }}</span>
                            </div>
                        </li>
                    </ul>
                </CardContent>
            </Card>

            <Card class="lg:col-span-2">
                <CardHeader>
                    <CardTitle class="text-base">By task</CardTitle>
                    <CardDescription>Who worked on what, {{ filters.from }} to {{ filters.to }}</CardDescription>
                </CardHeader>
                <CardContent class="overflow-x-auto">
                    <p v-if="summary.tasks.length === 0" class="text-sm text-muted-foreground">No time logged in this range.</p>
                    <table v-else class="w-full text-sm">
                        <thead class="text-left text-xs text-muted-foreground">
                            <tr>
                                <th class="py-1.5 font-medium">Task</th>
                                <th class="py-1.5 font-medium">Department</th>
                                <th class="py-1.5 font-medium">Who</th>
                                <th class="py-1.5 text-right font-medium">Time</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="t in summary.tasks" :key="`${t.id}-${t.title}`">
                                <td class="py-1.5">{{ t.title }} <span v-if="t.status" class="text-xs text-muted-foreground">· {{ t.status }}</span></td>
                                <td class="py-1.5">{{ t.department }}</td>
                                <td class="py-1.5 text-xs">{{ t.people.map((p) => `${p.name} (${formatMinutes(p.minutes)})`).join(', ') }}</td>
                                <td class="py-1.5 text-right tabular-nums">{{ formatMinutes(t.minutes) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </CardContent>
            </Card>
        </div>

        <div v-if="summary.marketing.length || summary.seo.length" class="grid gap-4 lg:grid-cols-2">
            <Card v-if="summary.marketing.length">
                <CardHeader><CardTitle class="text-base">Marketing</CardTitle></CardHeader>
                <CardContent>
                    <ul class="divide-y text-sm">
                        <li v-for="r in summary.marketing" :key="r.title" class="py-1.5">
                            <div class="flex justify-between"><span class="font-medium">{{ r.title }}</span><span>{{ formatMinutes(r.minutes) }}</span></div>
                            <p class="text-xs text-muted-foreground">{{ [r.campaign, r.channel, r.deliverable_type].filter(Boolean).join(' · ') || 'No campaign details' }}</p>
                        </li>
                    </ul>
                </CardContent>
            </Card>
            <Card v-if="summary.seo.length">
                <CardHeader><CardTitle class="text-base">SEO</CardTitle></CardHeader>
                <CardContent>
                    <ul class="divide-y text-sm">
                        <li v-for="r in summary.seo" :key="r.title" class="py-1.5">
                            <div class="flex justify-between"><span class="font-medium">{{ r.title }}</span><span>{{ formatMinutes(r.minutes) }}</span></div>
                            <p class="truncate text-xs text-muted-foreground">{{ [r.keyword, r.work_type, r.target_url].filter(Boolean).join(' · ') || 'No SEO details' }}</p>
                        </li>
                    </ul>
                </CardContent>
            </Card>
        </div>

        <Card v-if="summary.completed_without_time.length">
            <CardHeader><CardTitle class="text-base">Also completed (no time logged)</CardTitle></CardHeader>
            <CardContent>
                <ul class="divide-y text-sm">
                    <li v-for="t in summary.completed_without_time" :key="t.title" class="flex justify-between py-1.5">
                        <span>{{ t.title }}</span><span class="text-xs text-muted-foreground">{{ t.department }}</span>
                    </li>
                </ul>
            </CardContent>
        </Card>
    </div>
</template>
