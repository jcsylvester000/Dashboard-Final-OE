<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Copy,
    Download,
    FileText,
    Globe,
    Lock,
    LockOpen,
    Share2,
    Trash2,
    Users,
    UsersRound,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import DropZone from '@/components/files/DropZone.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { confirmDialog } from '@/composables/useConfirm';
import { formatBytes, timeAgo } from '@/lib/format';
import { destroy, index, link, lock, update } from '@/routes/drive';

type DriveRow = {
    id: number;
    name: string;
    mime: string;
    size: number;
    is_image: boolean;
    visibility: 'private' | 'team' | 'workspace' | 'public';
    workspace: { id: number; name: string } | null;
    owner: string | null;
    locked: boolean;
    share_url: string | null;
    share_expires_at: string | null;
    share_expired: boolean;
    share_downloads: number;
    open_url: string;
    uploaded_at: string | null;
    can_manage: boolean;
};

const props = defineProps<{
    tab: 'mine' | 'shared';
    configured: boolean;
    publicByUrl?: boolean;
    quota: { used: number; limit: number; free: number; is_admin: boolean };
    allowed: string[];
    files: DriveRow[];
    shared: DriveRow[];
    workspaces: { id: number; name: string }[];
    visibilities: Record<string, string>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'My files', href: index() }],
    },
});

const opts = { preserveScroll: true };
const percent = computed(() =>
    props.quota.limit > 0
        ? Math.min(
              100,
              Math.round((props.quota.used / props.quota.limit) * 100),
          )
        : 0,
);
const rows = computed(() =>
    props.tab === 'shared' ? props.shared : props.files,
);
const sharing = ref<number | null>(null);
const draft = ref<{
    visibility: string;
    workspace_id: string;
    expires_in_days: string;
}>({ visibility: 'private', workspace_id: '', expires_in_days: '0' });
const copied = ref<number | null>(null);

const badge: Record<DriveRow['visibility'], { label: string; cls: string }> = {
    private: { label: 'Private', cls: 'bg-muted text-muted-foreground' },
    team: {
        label: 'Team',
        cls: 'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-200',
    },
    workspace: {
        label: 'Workspace team',
        cls: 'bg-violet-100 text-violet-800 dark:bg-violet-950 dark:text-violet-200',
    },
    public: {
        label: 'Public link',
        cls: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200',
    },
};

function openShare(f: DriveRow): void {
    sharing.value = sharing.value === f.id ? null : f.id;
    draft.value = {
        visibility: f.visibility,
        workspace_id: f.workspace ? String(f.workspace.id) : '',
        expires_in_days: '0',
    };
}

function saveShare(f: DriveRow): void {
    router.put(
        update.url(f.id),
        {
            visibility: draft.value.visibility,
            workspace_id:
                draft.value.visibility === 'workspace'
                    ? Number(draft.value.workspace_id) || null
                    : null,
            expires_in_days:
                draft.value.visibility === 'public'
                    ? Number(draft.value.expires_in_days)
                    : null,
        },
        opts,
    );
}

function toggleLock(f: DriveRow): void {
    router.post(lock.url(f.id), { locked: !f.locked }, opts);
}

async function newLink(f: DriveRow): Promise<void> {
    if (
        await confirmDialog(
            'Create a new link? Anyone using the old link will lose access.',
        )
    ) {
        router.post(link.url(f.id), {}, opts);
    }
}

async function remove(f: DriveRow): Promise<void> {
    if (await confirmDialog(`Delete ${f.name}? This can't be undone.`)) {
        router.delete(destroy.url(f.id), opts);
    }
}

async function copy(f: DriveRow): Promise<void> {
    if (!f.share_url) {
        return;
    }
    await navigator.clipboard.writeText(f.share_url).catch(() => undefined);
    copied.value = f.id;
    setTimeout(() => (copied.value = null), 2000);
}

function rename(f: DriveRow): void {
    const name = window.prompt('Rename file', f.name);
    if (name && name.trim() && name !== f.name) {
        router.put(update.url(f.id), { name: name.trim() }, opts);
    }
}
</script>

<template>
    <Head title="My files" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">My files</h1>
                <p class="text-sm text-muted-foreground">
                    Your own storage for pictures and files. Keep them private,
                    share with the team or a client workspace's team, or send
                    clients a link.
                </p>
            </div>
            <div class="w-full max-w-xs space-y-1" data-testid="drive-quota">
                <div class="flex justify-between text-xs">
                    <span class="font-medium"
                        >{{ formatBytes(quota.used) }} of
                        {{ formatBytes(quota.limit) }} used</span
                    >
                    <span class="text-muted-foreground"
                        >{{ quota.is_admin ? 'Admin' : 'Member' }} quota</span
                    >
                </div>
                <div
                    class="h-2 overflow-hidden rounded-full bg-muted"
                    role="progressbar"
                    :aria-valuenow="percent"
                    aria-valuemin="0"
                    aria-valuemax="100"
                >
                    <div
                        class="h-full rounded-full"
                        :class="
                            percent >= 90
                                ? 'bg-rose-500'
                                : percent >= 70
                                  ? 'bg-amber-500'
                                  : 'bg-emerald-500'
                        "
                        :style="{ width: `${percent}%` }"
                    />
                </div>
            </div>
        </div>

        <p
            v-if="!configured"
            class="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950/30 dark:text-amber-200"
        >
            File storage isn't connected yet. An admin needs to add
            <code>UPLOADTHING_TOKEN</code> to the server's .env.
        </p>

        <nav class="flex gap-1 border-b text-sm">
            <Link
                :href="index({ query: { tab: 'mine' } })"
                class="-mb-px border-b-2 px-3 py-2"
                :class="
                    tab === 'mine'
                        ? 'border-foreground font-medium'
                        : 'border-transparent text-muted-foreground hover:text-foreground'
                "
                >My files
                <span class="text-xs text-muted-foreground">{{
                    files.length
                }}</span></Link
            >
            <Link
                :href="index({ query: { tab: 'shared' } })"
                class="-mb-px border-b-2 px-3 py-2"
                :class="
                    tab === 'shared'
                        ? 'border-foreground font-medium'
                        : 'border-transparent text-muted-foreground hover:text-foreground'
                "
                >Shared with me
                <span class="text-xs text-muted-foreground">{{
                    shared.length
                }}</span></Link
            >
        </nav>

        <p
            v-if="configured && publicByUrl && tab === 'mine'"
            class="text-xs text-muted-foreground"
        >
            For marketing assets and working files. Keep IDs, contracts and
            payroll out of My files for now; attach those to the task instead
            (stored privately).
        </p>
        <DropZone
            v-if="tab === 'mine'"
            :free-bytes="quota.free"
            :allowed="allowed"
            :disabled="!configured || quota.free <= 0"
        />

        <Card>
            <CardHeader>
                <CardTitle class="text-base">{{
                    tab === 'mine' ? 'Your files' : 'Shared with you'
                }}</CardTitle>
                <CardDescription>
                    <template v-if="tab === 'mine'"
                        >Locked files can't be deleted, renamed or re-shared
                        until unlocked. Public links work without a
                        login.</template
                    >
                    <template v-else
                        >Files teammates shared with the whole team or with a
                        client workspace you work on.</template
                    >
                </CardDescription>
            </CardHeader>
            <CardContent>
                <p
                    v-if="rows.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    {{
                        tab === 'mine'
                            ? 'No files yet. Drop one above.'
                            : 'Nothing shared with you yet.'
                    }}
                </p>
                <ul v-else class="divide-y">
                    <li v-for="f in rows" :key="f.id" class="py-3">
                        <div class="flex items-center gap-3">
                            <a
                                :href="f.open_url"
                                target="_blank"
                                rel="noopener"
                                class="shrink-0"
                            >
                                <img
                                    v-if="f.is_image"
                                    :src="f.open_url"
                                    :alt="f.name"
                                    class="size-12 rounded object-cover"
                                    loading="lazy"
                                    decoding="async"
                                />
                                <span
                                    v-else
                                    class="flex size-12 items-center justify-center rounded bg-muted"
                                    ><FileText
                                        class="size-5 text-muted-foreground"
                                /></span>
                            </a>
                            <div class="min-w-0 flex-1">
                                <a
                                    :href="f.open_url"
                                    target="_blank"
                                    rel="noopener"
                                    class="block truncate font-medium hover:underline"
                                    :title="f.name"
                                    >{{ f.name }}</a
                                >
                                <p
                                    class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground"
                                >
                                    <span>{{ formatBytes(f.size) }}</span>
                                    <span>· {{ timeAgo(f.uploaded_at) }}</span>
                                    <span v-if="f.owner"
                                        >· from {{ f.owner }}</span
                                    >
                                    <span
                                        class="rounded px-1.5 py-0.5 font-medium"
                                        :class="badge[f.visibility].cls"
                                    >
                                        {{ badge[f.visibility].label
                                        }}<template
                                            v-if="
                                                f.visibility === 'workspace' &&
                                                f.workspace
                                            "
                                            >: {{ f.workspace.name }}</template
                                        >
                                    </span>
                                    <span
                                        v-if="f.share_expired"
                                        class="font-medium text-rose-600"
                                        >link expired</span
                                    >
                                    <span
                                        v-if="f.locked"
                                        class="inline-flex items-center gap-0.5 font-medium text-amber-700"
                                        ><Lock class="size-3" /> Locked</span
                                    >
                                </p>
                            </div>
                            <div class="flex shrink-0 items-center gap-1">
                                <Button variant="ghost" size="icon" as-child>
                                    <a
                                        :href="f.open_url"
                                        target="_blank"
                                        rel="noopener"
                                        :aria-label="`Open ${f.name}`"
                                        ><Download class="size-4"
                                    /></a>
                                </Button>
                                <template v-if="f.can_manage">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        @click="openShare(f)"
                                        ><Share2 class="size-4" /> Share</Button
                                    >
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        :aria-label="
                                            f.locked ? 'Unlock' : 'Lock'
                                        "
                                        :title="f.locked ? 'Unlock' : 'Lock'"
                                        @click="toggleLock(f)"
                                    >
                                        <component
                                            :is="f.locked ? LockOpen : Lock"
                                            class="size-4"
                                        />
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        :disabled="f.locked"
                                        :aria-label="`Delete ${f.name}`"
                                        :title="
                                            f.locked
                                                ? 'Unlock to delete'
                                                : 'Delete'
                                        "
                                        @click="remove(f)"
                                    >
                                        <Trash2 class="size-4" />
                                    </Button>
                                </template>
                            </div>
                        </div>

                        <!-- Sharing panel -->
                        <div
                            v-if="sharing === f.id && f.can_manage"
                            class="mt-3 space-y-3 rounded-lg border bg-muted/30 p-3 text-sm"
                        >
                            <p v-if="f.locked" class="text-xs text-amber-700">
                                This file is locked. Unlock it to change sharing
                                or rename.
                            </p>
                            <div class="flex flex-wrap items-end gap-2">
                                <label class="grid gap-1">
                                    <span class="text-xs text-muted-foreground"
                                        >Who can open it</span
                                    >
                                    <NativeSelect
                                        v-model="draft.visibility"
                                        class="w-64"
                                        :disabled="f.locked"
                                        aria-label="Who can open it"
                                    >
                                        <option
                                            v-for="(label, key) in visibilities"
                                            :key="key"
                                            :value="key"
                                        >
                                            {{ label }}
                                        </option>
                                    </NativeSelect>
                                </label>
                                <label
                                    v-if="draft.visibility === 'workspace'"
                                    class="grid gap-1"
                                >
                                    <span class="text-xs text-muted-foreground"
                                        >Workspace (client)</span
                                    >
                                    <NativeSelect
                                        v-model="draft.workspace_id"
                                        class="w-56"
                                        :disabled="f.locked"
                                        aria-label="Client workspace"
                                    >
                                        <option value="" disabled>
                                            Choose a workspace…
                                        </option>
                                        <option
                                            v-for="w in workspaces"
                                            :key="w.id"
                                            :value="String(w.id)"
                                        >
                                            {{ w.name }}
                                        </option>
                                    </NativeSelect>
                                </label>
                                <label
                                    v-if="draft.visibility === 'public'"
                                    class="grid gap-1"
                                >
                                    <span class="text-xs text-muted-foreground"
                                        >Link expires</span
                                    >
                                    <NativeSelect
                                        v-model="draft.expires_in_days"
                                        class="w-36"
                                        :disabled="f.locked"
                                        aria-label="Link expires"
                                    >
                                        <option value="0">Never</option>
                                        <option value="1">In 1 day</option>
                                        <option value="7">In 7 days</option>
                                        <option value="30">In 30 days</option>
                                        <option value="90">In 90 days</option>
                                    </NativeSelect>
                                </label>
                                <Button
                                    size="sm"
                                    :disabled="
                                        f.locked ||
                                        (draft.visibility === 'workspace' &&
                                            !draft.workspace_id)
                                    "
                                    @click="saveShare(f)"
                                    >Save sharing</Button
                                >
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    :disabled="f.locked"
                                    @click="rename(f)"
                                    >Rename</Button
                                >
                            </div>
                            <p
                                class="flex items-center gap-1 text-xs text-muted-foreground"
                            >
                                <component
                                    :is="
                                        draft.visibility === 'public'
                                            ? Globe
                                            : draft.visibility === 'team'
                                              ? Users
                                              : draft.visibility === 'workspace'
                                                ? UsersRound
                                                : Lock
                                    "
                                    class="size-3.5"
                                />
                                <template v-if="draft.visibility === 'private'"
                                    >Only you (and admins) can open
                                    it.</template
                                >
                                <template
                                    v-else-if="draft.visibility === 'team'"
                                    >Every staff member can open it from "Shared
                                    with me".</template
                                >
                                <template
                                    v-else-if="draft.visibility === 'workspace'"
                                    >Agency staff on that client workspace can
                                    open it. The client cannot; use a public
                                    link for clients.</template
                                >
                                <template v-else
                                    >Anyone with the link can open it, no login
                                    needed. Good for sending work to
                                    clients.</template
                                >
                            </p>
                            <div
                                v-if="f.share_url"
                                class="flex flex-wrap items-center gap-2"
                            >
                                <input
                                    :value="f.share_url"
                                    readonly
                                    class="h-8 min-w-0 flex-1 rounded-md border bg-background px-2 text-xs"
                                    aria-label="Share link"
                                    @focus="
                                        (
                                            $event.target as HTMLInputElement
                                        ).select()
                                    "
                                />
                                <Button
                                    size="sm"
                                    variant="outline"
                                    @click="copy(f)"
                                    ><Copy class="size-4" />
                                    {{
                                        copied === f.id ? 'Copied' : 'Copy link'
                                    }}</Button
                                >
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    :disabled="f.locked"
                                    @click="newLink(f)"
                                    >New link</Button
                                >
                                <span class="text-xs text-muted-foreground">
                                    {{
                                        f.share_expires_at
                                            ? `Expires ${new Date(f.share_expires_at).toLocaleDateString()}`
                                            : 'Never expires'
                                    }}
                                    · {{ f.share_downloads }} download(s)
                                </span>
                            </div>
                        </div>
                    </li>
                </ul>
            </CardContent>
        </Card>
    </div>
</template>
