<script setup lang="ts">
import { Form, Head, Link, router } from '@inertiajs/vue3';
import {
    KeyRound,
    Link2,
    LogOut,
    ShieldOff,
    UserCheck,
    UserX,
} from '@lucide/vue';
import { ref } from 'vue';
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
}>();

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
        'Deactivate this member? They are signed out immediately and cannot log in until reactivated.',
};

function run(action: Action): void {
    const message = confirmations[action];
    if (message && !window.confirm(message)) {
        return;
    }

    busy.value = action;
    router.post(
        UserController[action].url(props.member.id),
        {},
        {
            preserveScroll: true,
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
                <Badge :variant="member.is_active ? 'secondary' : 'destructive'">
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
                    Role controls what they can do across the app. Workspace
                    roles come later (P2).
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Form
                    v-bind="UserController.update.form(member.id)"
                    v-slot="{ errors, processing }"
                    class="flex flex-col gap-6"
                    preserve-scroll
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
                        @click="run('deactivate')"
                    >
                        <UserX /> Deactivate
                    </Button>
                </template>
                <Button
                    v-else
                    :disabled="busy !== null"
                    @click="run('reactivate')"
                >
                    <UserCheck /> Reactivate
                </Button>
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

        <div>
            <Link
                :href="index()"
                class="text-sm text-muted-foreground underline underline-offset-4"
                >Back to team members</Link
            >
        </div>
    </div>
</template>
