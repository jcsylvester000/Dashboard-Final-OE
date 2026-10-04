<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Copy, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDateTime, timeAgo } from '@/lib/format';
import { destroy, index, store } from '@/routes/api-tokens';

const props = defineProps<{
    tokens: { id: number; name: string; abilities: string[]; last_used_at: string | null; expires_at: string | null; created_at: string | null }[];
    plainToken: string | null;
    maxDays: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'API tokens', href: index() }],
    },
});

const form = useForm({ name: '', access: 'read', days: 90 });
const copied = ref(false);

function create(): void {
    form.post(store.url(), { preserveScroll: true, onSuccess: () => form.reset('name') });
}

function revoke(id: number): void {
    if (window.confirm('Revoke this token? Apps using it stop working immediately.')) {
        router.delete(destroy.url(id), { preserveScroll: true });
    }
}

async function copy(): Promise<void> {
    if (props.plainToken) {
        await navigator.clipboard.writeText(props.plainToken);
        copied.value = true;
    }
}
</script>

<template>
    <Head title="API tokens" />

    <h1 class="sr-only">API tokens</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="API tokens"
            description="For the mobile app and integrations. A token acts as you, with your access. Keep it secret."
        />

        <div v-if="plainToken" class="space-y-2 rounded-md border border-emerald-300 bg-emerald-50 p-3 text-sm dark:border-emerald-900 dark:bg-emerald-950/40">
            <p class="font-medium">Copy your new token now - it will not be shown again.</p>
            <div class="flex gap-2">
                <code class="flex-1 overflow-x-auto rounded bg-background px-2 py-1 text-xs">{{ plainToken }}</code>
                <Button size="sm" variant="outline" @click="copy"><Copy class="size-4" /> {{ copied ? 'Copied' : 'Copy' }}</Button>
            </div>
            <p class="text-xs text-muted-foreground">Send it as <code>Authorization: Bearer &lt;token&gt;</code> to <code>/api/v1/...</code></p>
        </div>

        <form class="grid gap-3 sm:grid-cols-3" @submit.prevent="create">
            <div class="grid gap-1 sm:col-span-3">
                <Label for="token-name">Name</Label>
                <Input id="token-name" v-model="form.name" placeholder="e.g. My phone" />
                <InputError :message="form.errors.name" />
            </div>
            <div class="grid gap-1">
                <Label>Access</Label>
                <NativeSelect v-model="form.access">
                    <option value="read">Read only</option>
                    <option value="write">Read &amp; write</option>
                </NativeSelect>
            </div>
            <div class="grid gap-1">
                <Label>Expires after</Label>
                <NativeSelect v-model="form.days">
                    <option :value="30">30 days</option>
                    <option :value="90">90 days</option>
                    <option :value="180">180 days</option>
                    <option :value="maxDays">{{ maxDays }} days</option>
                </NativeSelect>
                <InputError :message="form.errors.days" />
            </div>
            <div class="flex items-end">
                <Button type="submit" :disabled="form.processing">Create token</Button>
            </div>
        </form>

        <ul v-if="tokens.length" class="divide-y rounded-md border text-sm">
            <li v-for="t in tokens" :key="t.id" class="flex items-center justify-between gap-3 p-3">
                <div>
                    <p class="font-medium">{{ t.name }}</p>
                    <p class="text-xs text-muted-foreground">
                        {{ t.abilities.includes('write') ? 'Read & write' : 'Read only' }}
                        · last used {{ t.last_used_at ? timeAgo(t.last_used_at) : 'never' }}
                        · expires {{ t.expires_at ? formatDateTime(t.expires_at) : 'never' }}
                    </p>
                </div>
                <Button variant="ghost" size="icon" aria-label="Revoke token" @click="revoke(t.id)"><Trash2 class="size-4" /></Button>
            </li>
        </ul>
        <p v-else class="text-sm text-muted-foreground">No tokens yet.</p>
    </div>
</template>
