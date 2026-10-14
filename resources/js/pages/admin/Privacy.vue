<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import Heading from '@/components/Heading.vue';
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
import { formatDateTime } from '@/lib/format';
import { dpo, index, notice as publishNotice } from '@/routes/admin/privacy';
import {
    store as storeIncident,
    update as updateIncident,
} from '@/routes/admin/privacy/incidents';

/**
 * E-99 (admins): staff privacy notice + who acknowledged it, DPO contact, and the
 * personal data incident log. Not legal advice: confirm requirements with counsel.
 */
type Incident = {
    id: number;
    title: string;
    description: string;
    data_involved: string | null;
    people_affected: number | null;
    severity: string;
    status: string;
    actions: string | null;
    discovered_at: string;
    occurred_at: string | null;
    npc_notified_at: string | null;
    people_notified_at: string | null;
    notify_by: string;
    by: string | null;
};

const props = defineProps<{
    notice: {
        version: number;
        title: string;
        body: string;
        published_at: string | null;
        dpo_name: string | null;
        dpo_email: string | null;
        dpo_phone: string | null;
    };
    acknowledgements: {
        total: number;
        done: number;
        people: {
            id: number;
            name: string;
            title: string | null;
            acknowledged_at: string | null;
        }[];
    };
    incidents: Incident[];
    severities: Record<string, string>;
    statuses: Record<string, string>;
    notifyHours: number;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Privacy', href: index() }] },
});

const opts = { preserveScroll: true };

const noticeForm = useForm({
    title: props.notice.title,
    body: props.notice.body,
});
const dpoForm = useForm({
    dpo_name: props.notice.dpo_name ?? '',
    dpo_email: props.notice.dpo_email ?? '',
    dpo_phone: props.notice.dpo_phone ?? '',
});

const showPending = ref(false);
const pending = computed(() =>
    props.acknowledgements.people.filter((p) => !p.acknowledged_at),
);

/** datetime-local value from an ISO string. */
function local(iso: string | null): string {
    if (!iso) {
        return '';
    }
    const d = new Date(iso);
    const pad = (n: number) => String(n).padStart(2, '0');

    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

const editing = ref<number | null>(null);
const blank = () => ({
    title: '',
    discovered_at: local(new Date().toISOString()),
    occurred_at: '',
    description: '',
    data_involved: '',
    people_affected: '' as string | number,
    severity: 'medium',
    status: 'open',
    npc_notified_at: '',
    people_notified_at: '',
    actions: '',
});
const incidentForm = useForm(blank());

function newIncident(): void {
    editing.value = 0;
    incidentForm.defaults(blank());
    incidentForm.reset();
    incidentForm.clearErrors();
}

function edit(i: Incident): void {
    editing.value = i.id;
    incidentForm.defaults({
        title: i.title,
        discovered_at: local(i.discovered_at),
        occurred_at: local(i.occurred_at),
        description: i.description,
        data_involved: i.data_involved ?? '',
        people_affected: i.people_affected ?? '',
        severity: i.severity,
        status: i.status,
        npc_notified_at: local(i.npc_notified_at),
        people_notified_at: local(i.people_notified_at),
        actions: i.actions ?? '',
    });
    incidentForm.reset();
    incidentForm.clearErrors();
}

function saveIncident(): void {
    const form = incidentForm.transform((d) => ({
        ...d,
        occurred_at: d.occurred_at || null,
        npc_notified_at: d.npc_notified_at || null,
        people_notified_at: d.people_notified_at || null,
        people_affected:
            d.people_affected === '' ? null : Number(d.people_affected),
    }));
    const done = { ...opts, onSuccess: () => (editing.value = null) };
    if (editing.value) {
        form.put(updateIncident.url(editing.value), done);
    } else {
        form.post(storeIncident.url(), done);
    }
}

function overdue(i: Incident): boolean {
    return (
        i.status !== 'closed' &&
        !i.npc_notified_at &&
        new Date(i.notify_by) < new Date()
    );
}

const severityClass: Record<string, string> = {
    low: 'text-muted-foreground',
    medium: 'text-amber-700',
    high: 'font-medium text-rose-600',
};
</script>

<template>
    <Head title="Privacy" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <Heading
            title="Privacy"
            description="Data Privacy Act housekeeping: staff notice, DPO contact, incident log. Confirm requirements with counsel; this is not legal advice."
        />

        <div class="grid gap-4 lg:grid-cols-2">
            <Card>
                <CardHeader>
                    <CardTitle class="text-base"
                        >Staff privacy notice</CardTitle
                    >
                    <CardDescription>
                        <template v-if="notice.version"
                            >Version {{ notice.version }} ·
                            {{ acknowledgements.done }} of
                            {{ acknowledgements.total }} active people
                            acknowledged it.
                            <button
                                v-if="pending.length"
                                type="button"
                                class="underline"
                                @click="showPending = !showPending"
                            >
                                {{ showPending ? 'Hide' : 'Show' }} who hasn't
                            </button></template
                        >
                        <template v-else>Not published yet.</template>
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-3">
                    <ul
                        v-if="showPending"
                        class="max-h-40 overflow-y-auto rounded-md border p-2 text-sm"
                    >
                        <li v-for="p in pending" :key="p.id">
                            {{ p.name }}
                            <span class="text-xs text-muted-foreground">{{
                                p.title
                            }}</span>
                        </li>
                    </ul>
                    <form
                        class="space-y-2"
                        @submit.prevent="
                            noticeForm.post(publishNotice.url(), opts)
                        "
                    >
                        <Input
                            v-model="noticeForm.title"
                            aria-label="Notice title"
                        />
                        <InputError :message="noticeForm.errors.title" />
                        <textarea
                            v-model="noticeForm.body"
                            rows="10"
                            aria-label="Notice text"
                            class="w-full rounded-md border bg-transparent p-2 text-sm"
                            placeholder="What personal data the app holds, why, who sees it, how long it is kept, and how to reach the DPO."
                        />
                        <InputError :message="noticeForm.errors.body" />
                        <Button
                            type="submit"
                            size="sm"
                            :disabled="noticeForm.processing"
                            >Publish as version {{ notice.version + 1 }}</Button
                        >
                        <p class="text-xs text-muted-foreground">
                            Publishing asks everyone to read and acknowledge it
                            again.
                        </p>
                    </form>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle class="text-base"
                        >Data Protection Officer</CardTitle
                    >
                    <CardDescription
                        >Shown to everyone at the bottom of the menu and on the
                        Privacy page.</CardDescription
                    >
                </CardHeader>
                <CardContent>
                    <form
                        class="grid gap-2"
                        @submit.prevent="dpoForm.put(dpo.url(), opts)"
                    >
                        <Input
                            v-model="dpoForm.dpo_name"
                            placeholder="Name"
                            aria-label="DPO name"
                        />
                        <Input
                            v-model="dpoForm.dpo_email"
                            type="email"
                            placeholder="Email"
                            aria-label="DPO email"
                        />
                        <InputError :message="dpoForm.errors.dpo_email" />
                        <Input
                            v-model="dpoForm.dpo_phone"
                            placeholder="Phone"
                            aria-label="DPO phone"
                        />
                        <div>
                            <Button
                                type="submit"
                                size="sm"
                                :disabled="dpoForm.processing"
                                >Save</Button
                            >
                        </div>
                    </form>
                </CardContent>
            </Card>
        </div>

        <Card>
            <CardHeader
                class="flex flex-row flex-wrap items-start justify-between gap-2"
            >
                <div>
                    <CardTitle class="text-base">Incident log</CardTitle>
                    <CardDescription
                        >Admins only; every entry and change is in the activity
                        log. Describe the kinds of data involved, never copy the
                        data itself. Guidance: NPC Circular 16-03 expects a
                        notifiable breach to be reported within
                        {{ notifyHours }} hours of knowing about
                        it.</CardDescription
                    >
                </div>
                <Button v-if="editing === null" size="sm" @click="newIncident"
                    >Record an incident</Button
                >
            </CardHeader>
            <CardContent class="space-y-3">
                <form
                    v-if="editing !== null"
                    class="grid gap-2 rounded-md border p-3 text-sm sm:grid-cols-4"
                    @submit.prevent="saveIncident"
                >
                    <div class="grid gap-1 sm:col-span-4">
                        <label for="inc-title" class="text-xs"
                            >What happened</label
                        >
                        <Input id="inc-title" v-model="incidentForm.title" />
                        <InputError :message="incidentForm.errors.title" />
                    </div>
                    <div class="grid gap-1">
                        <label for="inc-disc" class="text-xs">Discovered</label>
                        <Input
                            id="inc-disc"
                            v-model="incidentForm.discovered_at"
                            type="datetime-local"
                        />
                        <InputError
                            :message="incidentForm.errors.discovered_at"
                        />
                    </div>
                    <div class="grid gap-1">
                        <label for="inc-occ" class="text-xs"
                            >Happened (if known)</label
                        >
                        <Input
                            id="inc-occ"
                            v-model="incidentForm.occurred_at"
                            type="datetime-local"
                        />
                        <InputError
                            :message="incidentForm.errors.occurred_at"
                        />
                    </div>
                    <div class="grid gap-1">
                        <label for="inc-sev" class="text-xs">Severity</label>
                        <NativeSelect
                            id="inc-sev"
                            v-model="incidentForm.severity"
                        >
                            <option
                                v-for="(label, key) in severities"
                                :key="key"
                                :value="key"
                            >
                                {{ label }}
                            </option>
                        </NativeSelect>
                    </div>
                    <div class="grid gap-1">
                        <label for="inc-status" class="text-xs">Status</label>
                        <NativeSelect
                            id="inc-status"
                            v-model="incidentForm.status"
                        >
                            <option
                                v-for="(label, key) in statuses"
                                :key="key"
                                :value="key"
                            >
                                {{ label }}
                            </option>
                        </NativeSelect>
                    </div>
                    <div class="grid gap-1 sm:col-span-4">
                        <label for="inc-desc" class="text-xs"
                            >Description</label
                        >
                        <textarea
                            id="inc-desc"
                            v-model="incidentForm.description"
                            rows="3"
                            class="rounded-md border bg-transparent p-2"
                        />
                        <InputError
                            :message="incidentForm.errors.description"
                        />
                    </div>
                    <div class="grid gap-1 sm:col-span-3">
                        <label for="inc-data" class="text-xs"
                            >Kinds of data involved</label
                        >
                        <Input
                            id="inc-data"
                            v-model="incidentForm.data_involved"
                            placeholder="e.g. staff names and work emails"
                        />
                    </div>
                    <div class="grid gap-1">
                        <label for="inc-people" class="text-xs"
                            >People affected</label
                        >
                        <Input
                            id="inc-people"
                            v-model="incidentForm.people_affected"
                            type="number"
                            min="0"
                        />
                    </div>
                    <div class="grid gap-1 sm:col-span-2">
                        <label for="inc-npc" class="text-xs"
                            >NPC notified</label
                        >
                        <Input
                            id="inc-npc"
                            v-model="incidentForm.npc_notified_at"
                            type="datetime-local"
                        />
                    </div>
                    <div class="grid gap-1 sm:col-span-2">
                        <label for="inc-ppl" class="text-xs"
                            >People notified</label
                        >
                        <Input
                            id="inc-ppl"
                            v-model="incidentForm.people_notified_at"
                            type="datetime-local"
                        />
                    </div>
                    <div class="grid gap-1 sm:col-span-4">
                        <label for="inc-act" class="text-xs"
                            >Actions taken</label
                        >
                        <textarea
                            id="inc-act"
                            v-model="incidentForm.actions"
                            rows="2"
                            class="rounded-md border bg-transparent p-2"
                        />
                    </div>
                    <div class="flex gap-2 sm:col-span-4">
                        <Button
                            type="submit"
                            size="sm"
                            :disabled="incidentForm.processing"
                            >Save</Button
                        >
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            @click="editing = null"
                            >Cancel</Button
                        >
                    </div>
                </form>

                <p
                    v-if="incidents.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    No incidents recorded.
                </p>
                <ul v-else class="divide-y text-sm">
                    <li
                        v-for="i in incidents"
                        :key="i.id"
                        class="flex flex-wrap items-start justify-between gap-2 py-2"
                    >
                        <div class="min-w-0">
                            <p class="font-medium">{{ i.title }}</p>
                            <p class="text-xs text-muted-foreground">
                                Discovered
                                {{ formatDateTime(i.discovered_at) }} ·
                                <span :class="severityClass[i.severity]">{{
                                    severities[i.severity]
                                }}</span>
                                · {{ statuses[i.status] }}
                                <template v-if="i.people_affected !== null">
                                    · {{ i.people_affected }} people</template
                                >
                                <template v-if="i.npc_notified_at">
                                    · NPC notified
                                    {{
                                        formatDateTime(i.npc_notified_at)
                                    }}</template
                                >
                                <span
                                    v-else-if="overdue(i)"
                                    class="font-medium text-rose-600"
                                >
                                    · NPC not notified; {{ notifyHours }} h
                                    passed</span
                                >
                                <template v-else-if="i.status !== 'closed'">
                                    · notify by
                                    {{ formatDateTime(i.notify_by) }} if
                                    notifiable</template
                                >
                            </p>
                        </div>
                        <Button
                            v-if="editing === null"
                            variant="outline"
                            size="sm"
                            @click="edit(i)"
                            >Edit</Button
                        >
                    </li>
                </ul>
            </CardContent>
        </Card>
    </div>
</template>
