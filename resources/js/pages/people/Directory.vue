<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { reactive, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Input } from '@/components/ui/input';
import { avatarColor, getInitials } from '@/composables/useInitials';
import { departmentDot, formatDate } from '@/lib/format';
import { directory } from '@/routes';

/**
 * Staff directory (B-18): find who does what, who leads a team, who's on a client, who's out.
 */
const props = defineProps<{
    people: {
        id: number;
        name: string;
        title: string | null;
        department: { id: number; name: string; color: string } | null;
        leads: string[];
        clients: string[];
        out_until: string | null;
    }[];
    departments: { id: number; name: string }[];
    filters: { search?: string; department?: number | string };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Directory', href: directory() }] },
});

const form = reactive({
    search: props.filters.search ?? '',
    department: props.filters.department
        ? String(props.filters.department)
        : '',
});
const apply = useDebounceFn(() => {
    const query = Object.fromEntries(
        Object.entries(form).filter(([, v]) => v !== ''),
    );
    router.get(
        directory.url({ query }),
        {},
        { preserveScroll: true, preserveState: true, replace: true },
    );
}, 250);
watch(form, apply);
</script>

<template>
    <Head title="Directory" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <Heading
            title="Directory"
            description="Everyone at the agency: role, department, which team they lead and the clients you share with them."
        />

        <div class="flex flex-wrap gap-2">
            <Input
                v-model="form.search"
                type="search"
                class="w-64"
                placeholder="Search name or title"
                aria-label="Search people"
            />
            <NativeSelect
                v-model="form.department"
                class="w-44"
                aria-label="Department"
            >
                <option value="">All departments</option>
                <option
                    v-for="d in departments"
                    :key="d.id"
                    :value="String(d.id)"
                >
                    {{ d.name }}
                </option>
            </NativeSelect>
            <span class="self-center text-xs text-muted-foreground"
                >{{ people.length }} people</span
            >
        </div>

        <ul class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <li
                v-for="p in people"
                :key="p.id"
                class="flex gap-3 rounded-lg border p-3"
            >
                <span
                    class="flex size-10 shrink-0 items-center justify-center rounded-full text-sm font-semibold"
                    :class="avatarColor(p.name)"
                    >{{ getInitials(p.name) }}</span
                >
                <div class="min-w-0 space-y-1 text-sm">
                    <div class="font-medium">
                        {{ p.name }}
                        <span
                            v-if="p.out_until"
                            class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-[11px] font-normal text-amber-900 dark:bg-amber-950 dark:text-amber-200"
                            >Out until {{ formatDate(p.out_until) }}</span
                        >
                    </div>
                    <div class="text-muted-foreground">
                        {{ p.title ?? '—' }}
                    </div>
                    <div
                        v-if="p.department"
                        class="flex items-center gap-1 text-xs"
                    >
                        <span
                            class="size-2 rounded-full"
                            :class="
                                departmentDot[p.department.color] ??
                                'bg-slate-400'
                            "
                        />{{ p.department.name }}
                        <span
                            v-if="p.leads.length"
                            class="ml-1 rounded bg-violet-100 px-1.5 py-0.5 text-violet-900 dark:bg-violet-950 dark:text-violet-200"
                            >Leads {{ p.leads.join(', ') }}</span
                        >
                    </div>
                    <div
                        v-if="p.clients.length"
                        class="text-xs text-muted-foreground"
                    >
                        Clients: {{ p.clients.join(' · ') }}
                    </div>
                </div>
            </li>
        </ul>
        <p v-if="people.length === 0" class="text-sm text-muted-foreground">
            Nobody matches.
        </p>
    </div>
</template>
