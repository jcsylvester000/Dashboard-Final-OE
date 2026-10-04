<script setup lang="ts">
import { Check, Copy, KeyRound } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/format';
import type { IssuedSecret } from '@/types/admin';

const props = defineProps<{ secret: IssuedSecret }>();

const copied = ref(false);

async function copy(): Promise<void> {
    try {
        await navigator.clipboard.writeText(props.secret.value);
        copied.value = true;
        setTimeout(() => (copied.value = false), 2000);
    } catch {
        copied.value = false;
    }
}

const titles: Record<IssuedSecret['purpose'], string> = {
    setup: 'Account setup link',
    reset: 'Password reset link',
    temporary: 'Temporary password',
};
</script>

<template>
    <div
        class="rounded-lg border border-emerald-300 bg-emerald-50 p-4 dark:border-emerald-900 dark:bg-emerald-950/30"
        role="status"
    >
        <div class="mb-2 flex items-center gap-2 font-medium">
            <KeyRound class="size-4 text-emerald-700" />
            {{ titles[secret.purpose] }}
        </div>
        <div class="flex gap-2">
            <code
                class="min-w-0 flex-1 truncate rounded-md border bg-background px-3 py-2 font-mono text-xs"
                >{{ secret.value }}</code
            >
            <Button type="button" size="sm" variant="outline" @click="copy">
                <Check v-if="copied" />
                <Copy v-else />
                {{ copied ? 'Copied' : 'Copy' }}
            </Button>
        </div>
        <p class="mt-2 text-xs text-muted-foreground">
            Shown once — it is not stored and will disappear when you leave this
            page. Share it privately (chat, in person).
            <template v-if="secret.expiresAt">
                Works once; expires {{ formatDateTime(secret.expiresAt) }}.
            </template>
            <template v-else>
                The member must choose a new password at next login.
            </template>
        </p>
    </div>
</template>
