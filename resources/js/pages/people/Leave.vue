<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { confirmDialog } from '@/composables/useConfirm';
import { formatDate, localToday } from '@/lib/format';
import { cancel, decide, index as leaveIndex, store } from '@/routes/leave';

/**
 * Leave (B-14): file time off, see what your lead decided, approve your team's requests,
 * and the holiday calendar. Approved leave lowers capacity on Workload and the timesheet.
 */
type Leave = {
    id: number;
    person: string | null;
    type: string;
    starts_on: string;
    ends_on: string;
    working_days: number;
    reason: string | null;
    status: 'pending' | 'approved' | 'rejected' | 'cancelled';
    reviewer: string | null;
    review_note: string | null;
    can_cancel: boolean;
};

const props = defineProps<{
    mine: Leave[];
    toDecide: Leave[];
    teamUpcoming: Leave[];
    holidays: { date: string; name: string; kind: string }[];
    types: Record<string, string>;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Leave', href: leaveIndex() }] },
});

const today = localToday();
const me = usePage().props.auth.user.name;
const form = useForm({
    type: 'vacation',
    starts_on: today,
    ends_on: today,
    reason: '',
});
function file(): void {
    form.post(store.url(), {
        preserveScroll: true,
        onSuccess: () => form.reset('reason'),
    });
}

const notes = ref<Record<number, string>>({});
const errors = ref<Record<number, string>>({});
function respond(l: Leave, decision: 'approve' | 'reject'): void {
    router.post(
        decide.url(l.id),
        { decision, note: notes.value[l.id] || null },
        {
            preserveScroll: true,
            onError: (e) =>
                (errors.value[l.id] = Object.values(e)[0] ?? 'Could not save.'),
        },
    );
}
async function withdraw(l: Leave): Promise<void> {
    if (await confirmDialog('Cancel this leave?')) {
        router.post(cancel.url(l.id), {}, { preserveScroll: true });
    }
}

const statusClass: Record<Leave['status'], string> = {
    pending:
        'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-200',
    approved:
        'bg-emerald-100 text-emerald-900 dark:bg-emerald-950 dark:text-emerald-200',
    rejected: 'bg-rose-100 text-rose-900 dark:bg-rose-950 dark:text-rose-200',
    cancelled: 'bg-muted text-muted-foreground',
};
const kindLabel: Record<string, string> = {
    regular: 'Regular holiday',
    special: 'Special non-working',
    special_working: 'Special working day',
};
const upcomingHolidays = computed(() =>
    props.holidays.filter((h) => h.date >= today).slice(0, 8),
);
const range = (l: Leave) =>
    l.starts_on === l.ends_on
        ? formatDate(l.starts_on)
        : `${formatDate(l.starts_on)} – ${formatDate(l.ends_on)}`;
</script>

<template>
    <Head title="Leave" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <Heading
            title="Leave"
            description="File time off. Your department lead approves it; approved days come off your weekly capacity."
        />

        <div class="grid gap-4 lg:grid-cols-3">
            <Card class="lg:col-span-2">
                <CardHeader>
                    <CardTitle class="text-base">File leave</CardTitle>
                    <CardDescription
                        >Weekends and holidays inside the dates are not
                        counted.</CardDescription
                    >
                </CardHeader>
                <CardContent>
                    <form
                        class="grid gap-3 sm:grid-cols-4"
                        @submit.prevent="file"
                    >
                        <label class="grid gap-1 text-sm">
                            <span class="text-xs text-muted-foreground"
                                >Type</span
                            >
                            <NativeSelect v-model="form.type">
                                <option
                                    v-for="(label, key) in types"
                                    :key="key"
                                    :value="key"
                                >
                                    {{ label }}
                                </option>
                            </NativeSelect>
                        </label>
                        <label class="grid gap-1 text-sm">
                            <span class="text-xs text-muted-foreground"
                                >From</span
                            >
                            <Input
                                v-model="form.starts_on"
                                type="date"
                                required
                            />
                            <InputError :message="form.errors.starts_on" />
                        </label>
                        <label class="grid gap-1 text-sm">
                            <span class="text-xs text-muted-foreground"
                                >To</span
                            >
                            <Input
                                v-model="form.ends_on"
                                type="date"
                                :min="form.starts_on"
                                required
                            />
                            <InputError :message="form.errors.ends_on" />
                        </label>
                        <label class="grid gap-1 text-sm sm:col-span-4">
                            <span class="text-xs text-muted-foreground"
                                >Note for your lead (optional)</span
                            >
                            <Input
                                v-model="form.reason"
                                maxlength="500"
                                placeholder="e.g. Family trip; handover notes in the task"
                            />
                        </label>
                        <div>
                            <Button type="submit" :disabled="form.processing"
                                >File leave</Button
                            >
                        </div>
                    </form>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle class="text-base">Upcoming holidays</CardTitle>
                    <CardDescription
                        >Philippines. Admins keep this list
                        current.</CardDescription
                    >
                </CardHeader>
                <CardContent>
                    <ul class="space-y-1.5 text-sm">
                        <li
                            v-for="h in upcomingHolidays"
                            :key="h.date"
                            class="flex justify-between gap-2"
                        >
                            <span
                                >{{ h.name }}
                                <span class="text-xs text-muted-foreground"
                                    >· {{ kindLabel[h.kind] ?? h.kind }}</span
                                ></span
                            >
                            <span class="shrink-0 text-muted-foreground">{{
                                formatDate(h.date)
                            }}</span>
                        </li>
                        <li
                            v-if="upcomingHolidays.length === 0"
                            class="text-muted-foreground"
                        >
                            No more holidays on file this year.
                        </li>
                    </ul>
                </CardContent>
            </Card>
        </div>

        <Card v-if="toDecide.length">
            <CardHeader>
                <CardTitle class="text-base"
                    >Waiting for your decision ·
                    {{ toDecide.length }}</CardTitle
                >
            </CardHeader>
            <CardContent>
                <ul class="divide-y text-sm">
                    <li
                        v-for="l in toDecide"
                        :key="l.id"
                        class="flex flex-wrap items-center justify-between gap-2 py-2"
                    >
                        <div>
                            <div class="font-medium">
                                {{ l.person }} · {{ types[l.type] ?? l.type }} ·
                                {{ l.working_days }} day{{
                                    l.working_days === 1 ? '' : 's'
                                }}
                            </div>
                            <div class="text-xs text-muted-foreground">
                                {{ range(l)
                                }}<template v-if="l.reason">
                                    · "{{ l.reason }}"</template
                                >
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <Input
                                v-model="notes[l.id]"
                                class="h-8 w-56"
                                placeholder="Note (needed to decline)"
                                :aria-label="`Note for ${l.person}`"
                            />
                            <Button size="sm" @click="respond(l, 'approve')"
                                >Approve</Button
                            >
                            <Button
                                size="sm"
                                variant="outline"
                                @click="respond(l, 'reject')"
                                >Decline</Button
                            >
                            <span
                                v-if="errors[l.id]"
                                class="text-xs text-destructive"
                                >{{ errors[l.id] }}</span
                            >
                        </div>
                    </li>
                </ul>
            </CardContent>
        </Card>

        <div class="grid gap-4 lg:grid-cols-2">
            <Card>
                <CardHeader
                    ><CardTitle class="text-base"
                        >My leave</CardTitle
                    ></CardHeader
                >
                <CardContent>
                    <p
                        v-if="mine.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        No leave filed yet.
                    </p>
                    <ul v-else class="divide-y text-sm">
                        <li
                            v-for="l in mine"
                            :key="l.id"
                            class="flex flex-wrap items-center justify-between gap-2 py-2"
                        >
                            <div>
                                <div>
                                    {{ types[l.type] ?? l.type }} ·
                                    {{ range(l) }} · {{ l.working_days }} day{{
                                        l.working_days === 1 ? '' : 's'
                                    }}
                                </div>
                                <div
                                    v-if="l.reviewer || l.review_note"
                                    class="text-xs text-muted-foreground"
                                >
                                    {{ l.reviewer
                                    }}<template v-if="l.review_note"
                                        >: "{{ l.review_note }}"</template
                                    >
                                </div>
                            </div>
                            <span class="flex items-center gap-2">
                                <span
                                    class="rounded-full px-2 py-0.5 text-xs"
                                    :class="statusClass[l.status]"
                                    >{{ l.status }}</span
                                >
                                <Button
                                    v-if="l.can_cancel"
                                    size="sm"
                                    variant="ghost"
                                    @click="withdraw(l)"
                                    >Cancel</Button
                                >
                            </span>
                        </li>
                    </ul>
                </CardContent>
            </Card>
            <Card>
                <CardHeader>
                    <CardTitle class="text-base"
                        >Who's out in my team</CardTitle
                    >
                    <CardDescription
                        >Approved leave in the next 30 days.</CardDescription
                    >
                </CardHeader>
                <CardContent>
                    <p
                        v-if="teamUpcoming.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        Nobody is out.
                    </p>
                    <ul v-else class="space-y-1.5 text-sm">
                        <li
                            v-for="l in teamUpcoming"
                            :key="l.id"
                            class="flex justify-between gap-2"
                        >
                            <span
                                >{{ l.person
                                }}<span
                                    v-if="l.person === me"
                                    class="text-xs text-muted-foreground"
                                >
                                    (you)</span
                                ></span
                            >
                            <span class="text-muted-foreground">{{
                                range(l)
                            }}</span>
                        </li>
                    </ul>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
