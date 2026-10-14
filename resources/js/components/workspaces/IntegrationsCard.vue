<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import IntegrationsController from '@/actions/App/Http/Controllers/Workspaces/IntegrationsController';
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
import { timeAgo } from '@/lib/format';

/**
 * E-24 (D-04): GitHub repositories for this client. E-41 (D-05): optional Slack or
 * Teams channels (off until a test arrives; title + link only, never hours or rates).
 */
export type RepositoryRow = {
    id: number;
    full_name: string;
    on_open_status: string | null;
    on_merge_status: string | null;
};
export type ChatChannelRow = {
    id: number;
    provider: 'slack' | 'teams';
    name: string;
    url: string;
    events: string[];
    enabled: boolean;
    last_ok_at: string | null;
    last_error: string | null;
};

const props = defineProps<{
    workspaceSlug: string;
    taskKey: string | null;
    repositories: RepositoryRow[];
    webhook: { url: string; configured: boolean };
    channels: ChatChannelRow[];
    events: Record<string, string>;
}>();

const args = () => ({ workspace: props.workspaceSlug });
const repoForm = useForm({
    full_name: '',
    on_open_status: 'in-review' as string | null,
    on_merge_status: 'done' as string | null,
});
function addRepo(): void {
    repoForm.post(IntegrationsController.storeRepository.url(args()), {
        preserveScroll: true,
        onSuccess: () => repoForm.reset('full_name'),
    });
}
function setRule(
    r: RepositoryRow,
    field: 'on_open_status' | 'on_merge_status',
    on: boolean,
    slug: string,
): void {
    router.put(
        IntegrationsController.updateRepository.url({
            ...args(),
            repository: r.id,
        }),
        {
            on_open_status:
                field === 'on_open_status'
                    ? on
                        ? slug
                        : null
                    : r.on_open_status,
            on_merge_status:
                field === 'on_merge_status'
                    ? on
                        ? slug
                        : null
                    : r.on_merge_status,
        },
        { preserveScroll: true },
    );
}
async function removeRepo(r: RepositoryRow): Promise<void> {
    if (
        await confirmDialog(
            `Unlink ${r.full_name}? Existing links on tasks stay.`,
        )
    ) {
        router.delete(
            IntegrationsController.destroyRepository.url({
                ...args(),
                repository: r.id,
            }),
            { preserveScroll: true },
        );
    }
}

const chatForm = useForm({
    provider: 'slack' as 'slack' | 'teams',
    name: '',
    webhook_url: '',
    events: ['review_requested', 'approved', 'changes_requested'] as string[],
});
const showHelp = ref(false);
function addChannel(): void {
    chatForm.post(IntegrationsController.storeChannel.url(args()), {
        preserveScroll: true,
        onSuccess: () => chatForm.reset('name', 'webhook_url'),
    });
}
function toggle(c: ChatChannelRow): void {
    router.put(
        IntegrationsController.updateChannel.url({ ...args(), channel: c.id }),
        { enabled: !c.enabled },
        { preserveScroll: true },
    );
}
function setEvents(c: ChatChannelRow, key: string, on: boolean): void {
    const events = on
        ? [...new Set([...c.events, key])]
        : c.events.filter((e) => e !== key);
    if (events.length) {
        router.put(
            IntegrationsController.updateChannel.url({
                ...args(),
                channel: c.id,
            }),
            { events },
            { preserveScroll: true },
        );
    }
}
function test(c: ChatChannelRow): void {
    router.post(
        IntegrationsController.testChannel.url({ ...args(), channel: c.id }),
        {},
        { preserveScroll: true },
    );
}
async function removeChannel(c: ChatChannelRow): Promise<void> {
    if (await confirmDialog(`Remove the ${c.provider} channel "${c.name}"?`)) {
        router.delete(
            IntegrationsController.destroyChannel.url({
                ...args(),
                channel: c.id,
            }),
            { preserveScroll: true },
        );
    }
}
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle class="text-base">GitHub</CardTitle>
            <CardDescription>
                Pull requests and branches that name a task (<code
                    class="rounded bg-muted px-1"
                    >{{ taskKey ?? 'KEY' }}-24</code
                >) link to it, with checks and reviewers. Optional: move the
                task when a PR opens or merges (review sign-off and required
                checklist lines still apply).
            </CardDescription>
        </CardHeader>
        <CardContent class="space-y-4 text-sm">
            <ul v-if="repositories.length" class="divide-y rounded border">
                <li
                    v-for="r in repositories"
                    :key="r.id"
                    class="flex flex-wrap items-center gap-3 px-3 py-2"
                >
                    <span class="min-w-0 flex-1 font-mono text-xs">{{
                        r.full_name
                    }}</span>
                    <label class="flex items-center gap-1 text-xs">
                        <input
                            type="checkbox"
                            class="size-4"
                            :checked="r.on_open_status === 'in-review'"
                            @change="
                                setRule(
                                    r,
                                    'on_open_status',
                                    ($event.target as HTMLInputElement).checked,
                                    'in-review',
                                )
                            "
                        />
                        PR opened → In Review
                    </label>
                    <label class="flex items-center gap-1 text-xs">
                        <input
                            type="checkbox"
                            class="size-4"
                            :checked="r.on_merge_status === 'done'"
                            @change="
                                setRule(
                                    r,
                                    'on_merge_status',
                                    ($event.target as HTMLInputElement).checked,
                                    'done',
                                )
                            "
                        />
                        Merged → Done
                    </label>
                    <Button
                        size="icon"
                        variant="ghost"
                        :aria-label="`Unlink ${r.full_name}`"
                        @click="removeRepo(r)"
                        ><Trash2 class="size-4"
                    /></Button>
                </li>
            </ul>
            <form
                class="flex flex-wrap items-end gap-2"
                @submit.prevent="addRepo"
            >
                <label class="grid min-w-0 flex-1 gap-1">
                    <span class="text-xs text-muted-foreground"
                        >Repository</span
                    >
                    <Input
                        v-model="repoForm.full_name"
                        placeholder="owner/repository"
                        required
                    />
                </label>
                <Button
                    type="submit"
                    size="sm"
                    :disabled="repoForm.processing || !repoForm.full_name"
                    >Link</Button
                >
                <InputError
                    :message="repoForm.errors.full_name"
                    class="w-full"
                />
            </form>
            <div class="rounded border bg-muted/40 p-3 text-xs">
                <p class="font-medium">
                    Webhook setup (once per repository or organisation)
                    <span
                        class="ml-1 rounded px-1"
                        :class="
                            webhook.configured
                                ? 'bg-emerald-100 text-emerald-900 dark:bg-emerald-950 dark:text-emerald-200'
                                : 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-200'
                        "
                        >{{
                            webhook.configured
                                ? 'Secret set'
                                : 'Ask an admin to set GITHUB_WEBHOOK_SECRET'
                        }}</span
                    >
                </p>
                <ol class="mt-1 list-decimal space-y-0.5 pl-4">
                    <li>GitHub → Settings → Webhooks → Add webhook.</li>
                    <li>
                        Payload URL
                        <code class="rounded bg-background px-1">{{
                            webhook.url
                        }}</code
                        >, content type
                        <code class="rounded bg-background px-1"
                            >application/json</code
                        >, the shared secret.
                    </li>
                    <li>
                        Events: Pull requests, Pushes, Check suites, Statuses.
                    </li>
                </ol>
            </div>
        </CardContent>
    </Card>

    <Card>
        <CardHeader>
            <CardTitle class="text-base">Chat alerts (optional)</CardTitle>
            <CardDescription>
                Post review requests and decisions to a Slack or Teams channel.
                Messages carry only the event, the task title and a link. A
                channel stays off until a test message arrives.
            </CardDescription>
        </CardHeader>
        <CardContent class="space-y-4 text-sm">
            <ul v-if="channels.length" class="divide-y rounded border">
                <li
                    v-for="c in channels"
                    :key="c.id"
                    class="space-y-2 px-3 py-2"
                >
                    <div class="flex flex-wrap items-center gap-2">
                        <span
                            class="rounded bg-muted px-1.5 text-xs capitalize"
                            >{{ c.provider }}</span
                        >
                        <span class="font-medium">{{ c.name }}</span>
                        <span class="font-mono text-xs text-muted-foreground">{{
                            c.url
                        }}</span>
                        <span class="flex-1" />
                        <Button size="sm" variant="secondary" @click="test(c)"
                            >Send test</Button
                        >
                        <Button
                            size="sm"
                            :variant="c.enabled ? 'default' : 'outline'"
                            :disabled="!c.enabled && !c.last_ok_at"
                            :title="
                                !c.last_ok_at
                                    ? 'Send a test that arrives first'
                                    : ''
                            "
                            @click="toggle(c)"
                            >{{ c.enabled ? 'On' : 'Off' }}</Button
                        >
                        <Button
                            size="icon"
                            variant="ghost"
                            :aria-label="`Remove ${c.name}`"
                            @click="removeChannel(c)"
                            ><Trash2 class="size-4"
                        /></Button>
                    </div>
                    <div class="flex flex-wrap gap-3 text-xs">
                        <label
                            v-for="(label, key) in events"
                            :key="key"
                            class="flex items-center gap-1"
                        >
                            <input
                                type="checkbox"
                                class="size-3.5"
                                :checked="c.events.includes(String(key))"
                                @change="
                                    setEvents(
                                        c,
                                        String(key),
                                        ($event.target as HTMLInputElement)
                                            .checked,
                                    )
                                "
                            />
                            {{ label }}
                        </label>
                    </div>
                    <p
                        class="text-xs"
                        :class="
                            c.last_error
                                ? 'text-rose-600'
                                : 'text-muted-foreground'
                        "
                    >
                        <template v-if="c.last_error"
                            >Last send failed: {{ c.last_error }}</template
                        >
                        <template v-else-if="c.last_ok_at"
                            >Last message arrived
                            {{ timeAgo(c.last_ok_at) }}</template
                        >
                        <template v-else>No test sent yet</template>
                    </p>
                </li>
            </ul>
            <form
                class="grid gap-2 sm:grid-cols-4"
                @submit.prevent="addChannel"
            >
                <label class="grid gap-1">
                    <span class="text-xs text-muted-foreground">Service</span>
                    <NativeSelect
                        v-model="chatForm.provider"
                        aria-label="Chat service"
                    >
                        <option value="slack">Slack</option>
                        <option value="teams">
                            Microsoft Teams (Workflows)
                        </option>
                    </NativeSelect>
                </label>
                <label class="grid gap-1">
                    <span class="text-xs text-muted-foreground">Name</span>
                    <Input
                        v-model="chatForm.name"
                        maxlength="60"
                        placeholder="#brewhouse-reviews"
                        required
                    />
                </label>
                <label class="grid gap-1 sm:col-span-2">
                    <span class="text-xs text-muted-foreground"
                        >Webhook link (kept secret)</span
                    >
                    <Input
                        v-model="chatForm.webhook_url"
                        type="url"
                        autocomplete="off"
                        placeholder="https://hooks.slack.com/services/…"
                        required
                    />
                </label>
                <fieldset class="flex flex-wrap gap-3 text-xs sm:col-span-4">
                    <legend class="sr-only">Events</legend>
                    <label
                        v-for="(label, key) in events"
                        :key="key"
                        class="flex items-center gap-1"
                    >
                        <input
                            v-model="chatForm.events"
                            type="checkbox"
                            class="size-3.5"
                            :value="key"
                        />
                        {{ label }}
                    </label>
                </fieldset>
                <InputError
                    :message="
                        chatForm.errors.webhook_url ?? chatForm.errors.events
                    "
                    class="sm:col-span-4"
                />
                <div class="flex items-center gap-3 sm:col-span-4">
                    <Button
                        type="submit"
                        size="sm"
                        :disabled="chatForm.processing"
                        >Add channel</Button
                    >
                    <button
                        type="button"
                        class="text-xs underline"
                        @click="showHelp = !showHelp"
                    >
                        Where do I get the link?
                    </button>
                </div>
                <div
                    v-if="showHelp"
                    class="space-y-1 rounded border bg-muted/40 p-3 text-xs sm:col-span-4"
                >
                    <p>
                        <strong>Slack:</strong> create a Slack app with Incoming
                        Webhooks, add it to the channel, copy the
                        hooks.slack.com link.
                    </p>
                    <p>
                        <strong>Teams:</strong> in the channel, ⋯ → Workflows →
                        "Send webhook alerts to a channel", then copy the link.
                        Old "Office 365 connector" links were retired by
                        Microsoft and are refused.
                    </p>
                </div>
            </form>
        </CardContent>
    </Card>
</template>
