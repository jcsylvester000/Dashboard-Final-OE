<script setup lang="ts">
import type { Device, SignIn } from '@/components/security/SignedInDevices.vue';
import SignedInDevices from '@/components/security/SignedInDevices.vue';
import { destroy as destroyUserSession } from '@/routes/admin/users/sessions';
import { Form, Head, Link, router } from '@inertiajs/vue3';
import {
    KeyRound,
    Link2,
    LogOut,
    ShieldOff,
    UserCheck,
    UserX,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import UserController from '@/actions/App/Http/Controllers/Admin/UserController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { confirmDialog } from '@/composables/useConfirm';
import { formatDateTime, timeAgo } from '@/lib/format';
import { index } from '@/routes/admin/users';
import type {
    AccessLinkRow,
    DepartmentOption,
    IssuedSecret,
    Member,
    Option,
} from '@/types/admin';
import IssuedSecretCard from './partials/IssuedSecretCard.vue';
import UserFields from './partials/UserFields.vue';

const props = defineProps<{
    member: Member;
    accessLinks: AccessLinkRow[];
    roles: Option[];
    departments: DepartmentOption[];
    issuedSecret: IssuedSecret | null;
    handover: {
        open_tasks: number;
        projects_led: number;
        departments_led: number;
        running_timers: number;
    } | null;
    handoverTo: { id: number; name: string; title: string | null }[];
    devices: Device[];
    loginHistory: SignIn[];
}>();

const offboarding = ref(false);
const reassignTo = ref<string>('');

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Team members', href: index() }],
    },
});

const busy = ref<string | null>(null);

type Action =
    | 'issueAccessLink'
    | 'temporaryPassword'
    | 'resetTwoFactor'
    | 'signOut'
    | 'deactivate'
    | 'reactivate';

const confirmations: Partial<Record<Action, string>> = {
    temporaryPassword:
        'Replace their password with a temporary one and sign them out everywhere?',
    resetTwoFactor:
        'Turn off two-factor for this member? They can set it up again after logging in.',
    signOut: 'Sign this member out of every browser and device?',
    deactivate:
        'Deactivate this member? Their open work is handed over, they are signed out immediately and cannot log in until reactivated.',
};

async function run(action: Action): Promise<void> {
    const message = confirmations[action];
    if (message && !(await confirmDialog(message))) {
        return;
    }

    busy.value = action;
    router.post(
        UserController[action].url(props.member.id),
        action === 'deactivate' && reassignTo.value !== ''
            ? { reassign_to: Number(reassignTo.value) }
            : {},
        {
            preserveScroll: true,
            onFinish: () => (busy.value = null),
        },
    );
}

const confirmName = ref('');
const anonymised = computed(() =>
    props.member.email.endsWith('@anonymised.invalid'),
);
const plural = (n: number, word: string) => `${n} ${word}${n === 1 ? '' : 's'}`;
const handoverSummary = computed(() => {
    const h = props.handover;
    if (!h) {
        return '';
    }
    const parts = [plural(h.open_tasks, 'open task')];
    const leads = [
        h.projects_led ? plural(h.projects_led, 'project') : '',
        h.departments_led ? plural(h.departments_led, 'department') : '',
    ].filter(Boolean);
    if (leads.length) {
        parts.push(`leads ${leads.join(' and ')}`);
    }
    if (h.running_timers) {
        parts.push('a timer is running (it will be stopped and saved)');
    }

    return parts.join(' · ') + '.';
});
const anonymiseError = ref<string | null>(null);

async function anonymise(): Promise<void> {
    if (
        !(await confirmDialog(
            `Erase ${props.member.name}'s personal details? This cannot be undone.`,
        ))
    ) {
        return;
    }
    busy.value = 'anonymise';
    anonymiseError.value = null;
    router.post(
        UserController.anonymise.url(props.member.id),
        { confirm_name: confirmName.value },
        {
            preserveScroll: true,
            onSuccess: () => (confirmName.value = ''),
            onError: (e) =>
                (anonymiseError.value =
                    Object.values(e)[0] ?? 'Could not erase.'),
            onFinish: () => (busy.value = null),
        },
    );
}

const statusVariant: Record<
    AccessLinkRow['status'],
    'default' | 'secondary' | 'outline' | 'destructive'
> = {
    active: 'default',
    used: 'secondary',
    expired: 'outline',
    revoked: 'outline',
};
</script>

<template>
    <Head :title="`Edit ${member.name}`" />

    <div class="flex max-w-4xl flex-1 flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">
                    {{ member.name }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    Last login {{ timeAgo(member.last_login_at) }} · Added
                    {{ formatDateTime(member.created_at) }}
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Badge
                    :variant="member.is_active ? 'secondary' : 'destructive'"
                >
                    {{ member.is_active ? 'Active' : 'Deactivated' }}
                </Badge>
                <Badge variant="outline">
                    2FA {{ member.two_factor ? 'on' : 'off' }}
                </Badge>
                <Badge v-if="member.must_change_password" variant="outline">
                    Must change password
                </Badge>
            </div>
        </div>

        <IssuedSecretCard v-if="issuedSecret" :secret="issuedSecret" />

        <Card>
            <CardHeader>
                <CardTitle class="text-base">Details & access</CardTitle>
                <CardDescription>
                    Role controls what they can do across the app. Client
                    workspace roles are set on each workspace's Members page.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Form
                    v-bind="UserController.update.form(member.id)"
                    v-slot="{ errors, processing }"
                    class="flex flex-col gap-6"
                    :options="{ preserveScroll: true }"
                >
                    <UserFields
                        :member="member"
                        :roles="roles"
                        :departments="departments"
                        :errors="errors"
                    />
                    <div>
                        <Button type="submit" :disabled="processing">
                            <Spinner v-if="processing" />
                            Save changes
                        </Button>
                    </div>
                </Form>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle class="text-base">Account actions</CardTitle>
                <CardDescription>
                    Nothing is emailed. Copy any link or password shown and
                    share it privately.
                </CardDescription>
            </CardHeader>
            <CardContent class="flex flex-wrap gap-2">
                <template v-if="member.is_active">
                    <Button
                        variant="outline"
                        :disabled="busy !== null"
                        @click="run('issueAccessLink')"
                    >
                        <Link2 />
                        {{
                            member.last_login_at
                                ? 'Issue reset link'
                                : 'Issue new setup link'
                        }}
                    </Button>
                    <Button
                        v-if="!member.is_self"
                        variant="outline"
                        :disabled="busy !== null"
                        @click="run('temporaryPassword')"
                    >
                        <KeyRound /> Temporary password
                    </Button>
                    <Button
                        v-if="member.two_factor"
                        variant="outline"
                        :disabled="busy !== null"
                        @click="run('resetTwoFactor')"
                    >
                        <ShieldOff /> Reset 2FA
                    </Button>
                    <Button
                        v-if="!member.is_self"
                        variant="outline"
                        :disabled="busy !== null"
                        @click="run('signOut')"
                    >
                        <LogOut /> Sign out everywhere
                    </Button>
                    <Button
                        v-if="!member.is_self"
                        variant="destructive"
                        :disabled="busy !== null"
                        @click="offboarding = !offboarding"
                    >
                        <UserX /> Deactivate…
                    </Button>
                    <div
                        v-if="offboarding && handover"
                        class="w-full space-y-2 rounded-lg border border-destructive/40 bg-destructive/5 p-3 text-sm"
                    >
                        <p class="font-medium">Offboard {{ member.name }}</p>
                        <p class="text-muted-foreground">
                            {{ handoverSummary }} Their logged time, approvals
                            and files stay on record for billing.
                        </p>
                        <label class="grid max-w-sm gap-1">
                            <span class="text-xs text-muted-foreground"
                                >Hand open work to</span
                            >
                            <select
                                v-model="reassignTo"
                                class="h-9 rounded-md border bg-background px-2"
                            >
                                <option value="">
                                    Nobody (leave unassigned in the department
                                    queue)
                                </option>
                                <option
                                    v-for="p in handoverTo"
                                    :key="p.id"
                                    :value="String(p.id)"
                                >
                                    {{ p.name
                                    }}<template v-if="p.title">
                                        · {{ p.title }}</template
                                    >
                                </option>
                            </select>
                        </label>
                        <p class="text-xs text-muted-foreground">
                            Tasks in clients the new owner isn't a member of are
                            left unassigned instead.
                        </p>
                        <div class="flex gap-2">
                            <Button
                                variant="destructive"
                                size="sm"
                                :disabled="busy !== null"
                                @click="run('deactivate')"
                            >
                                <Spinner v-if="busy === 'deactivate'" /> Hand
                                over and deactivate
                            </Button>
                            <Button
                                variant="ghost"
                                size="sm"
                                @click="offboarding = false"
                                >Cancel</Button
                            >
                        </div>
                    </div>
                </template>
                <Button
                    v-else-if="!anonymised"
                    :disabled="busy !== null"
                    @click="run('reactivate')"
                >
                    <UserCheck /> Reactivate
                </Button>
                <p v-else class="text-sm text-muted-foreground">
                    This account was anonymised and can't be reactivated. Add a
                    new team member instead.
                </p>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle class="text-base">Personal data</CardTitle>
                <CardDescription>
                    Data Privacy Act requests. The export is everything the app
                    holds about this person. Erasing replaces their name and
                    email with "Former member #{{ member.id }}" and deletes
                    sign-ins, alerts and saved views; time, approvals and
                    invoices are kept for billing records.
                </CardDescription>
            </CardHeader>
            <CardContent class="space-y-3 text-sm">
                <a
                    :href="UserController.exportData.url(member.id)"
                    class="inline-flex items-center gap-1 underline"
                    download
                    >Download personal data (JSON)</a
                >
                <form
                    v-if="!member.is_active && !anonymised"
                    class="flex flex-wrap items-center gap-2"
                    @submit.prevent="anonymise"
                >
                    <input
                        v-model="confirmName"
                        class="h-9 w-64 rounded-md border bg-background px-2"
                        :placeholder="`Type ${member.name} to confirm`"
                        aria-label="Type the full name to confirm"
                    />
                    <Button
                        variant="destructive"
                        size="sm"
                        type="submit"
                        :disabled="confirmName !== member.name || busy !== null"
                        >Erase personal details</Button
                    >
                    <span
                        v-if="anonymiseError"
                        class="text-xs text-destructive"
                        >{{ anonymiseError }}</span
                    >
                </form>
                <p
                    v-else-if="member.is_active"
                    class="text-xs text-muted-foreground"
                >
                    To erase, deactivate the account first.
                </p>
                <p v-else class="text-xs text-muted-foreground">
                    This account has been anonymised.
                </p>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle class="text-base">Access link history</CardTitle>
                <CardDescription>Last 10 links issued.</CardDescription>
            </CardHeader>
            <CardContent>
                <p
                    v-if="accessLinks.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    No links issued yet.
                </p>
                <ul v-else class="divide-y text-sm">
                    <li
                        v-for="link in accessLinks"
                        :key="link.id"
                        class="flex flex-wrap items-center justify-between gap-2 py-2"
                    >
                        <span>
                            <span class="font-medium capitalize">{{
                                link.purpose
                            }}</span>
                            · issued by {{ link.created_by ?? 'System' }}
                            {{ timeAgo(link.created_at) }}
                        </span>
                        <span class="flex items-center gap-2">
                            <span class="text-xs text-muted-foreground">
                                {{
                                    link.used_at
                                        ? `used ${formatDateTime(link.used_at)}`
                                        : `expires ${formatDateTime(link.expires_at)}`
                                }}
                            </span>
                            <Badge :variant="statusVariant[link.status]">{{
                                link.status
                            }}</Badge>
                        </span>
                    </li>
                </ul>
            </CardContent>
        </Card>

        <Card>
            <CardContent class="pt-6">
                <SignedInDevices
                    :title="`Where ${member.name} is signed in`"
                    :devices="devices"
                    :history="loginHistory"
                    :revoke-url="
                        (id: number) =>
                            destroyUserSession.url({
                                user: member.id,
                                userSession: id,
                            })
                    "
                />
            </CardContent>
        </Card>

        <div>
            <Link
                :href="index()"
                class="text-sm text-muted-foreground underline underline-offset-4"
                >Back to team members</Link
            >
        </div>
    </div>
</template>
