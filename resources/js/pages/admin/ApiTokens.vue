<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { confirmDialog } from '@/composables/useConfirm';
import { formatDateTime, timeAgo } from '@/lib/format';
import { destroy, index } from '@/routes/admin/api-tokens';

/**
 * E-97: every API token people created for the mobile app or integrations.
 */
defineProps<{
    tokens: {
        id: number;
        name: string;
        owner: string;
        owner_email: string | null;
        owner_active: boolean;
        abilities: string[];
        last_used_at: string | null;
        expires_at: string | null;
        expired: boolean;
        created_at: string | null;
    }[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'API tokens', href: index() }] },
});

async function revoke(id: number, name: string, owner: string): Promise<void> {
    if (
        await confirmDialog(
            `Revoke "${name}" (${owner})? Apps using it stop working at once.`,
        )
    ) {
        router.delete(destroy.url(id), { preserveScroll: true });
    }
}
</script>

<template>
    <Head title="API tokens" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <Heading
            title="API tokens"
            description="Tokens people created in Settings > API tokens for the mobile app and integrations. Deactivating someone revokes theirs automatically."
        />

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-left text-sm">
                <thead class="bg-muted/50 text-xs text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2 font-medium">Token</th>
                        <th class="px-4 py-2 font-medium">Owner</th>
                        <th class="px-4 py-2 font-medium">Access</th>
                        <th class="px-4 py-2 font-medium">Last used</th>
                        <th class="px-4 py-2 font-medium">Expires</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-if="tokens.length === 0">
                        <td
                            colspan="6"
                            class="px-4 py-8 text-center text-muted-foreground"
                        >
                            No API tokens.
                        </td>
                    </tr>
                    <tr
                        v-for="t in tokens"
                        :key="t.id"
                        :class="t.expired ? 'opacity-60' : ''"
                    >
                        <td class="px-4 py-2 font-medium">{{ t.name }}</td>
                        <td class="px-4 py-2">
                            {{ t.owner }}
                            <span
                                v-if="!t.owner_active"
                                class="text-xs text-rose-700 dark:text-rose-300"
                                >(deactivated)</span
                            >
                            <span class="block text-xs text-muted-foreground">{{
                                t.owner_email
                            }}</span>
                        </td>
                        <td class="px-4 py-2 text-xs">
                            {{
                                t.abilities.includes('write')
                                    ? 'Read & write'
                                    : 'Read'
                            }}
                        </td>
                        <td class="px-4 py-2 text-xs text-muted-foreground">
                            {{
                                t.last_used_at
                                    ? timeAgo(t.last_used_at)
                                    : 'Never'
                            }}
                        </td>
                        <td class="px-4 py-2 text-xs text-muted-foreground">
                            {{
                                t.expires_at
                                    ? (t.expired ? 'Expired ' : '') +
                                      formatDateTime(t.expires_at)
                                    : 'Never'
                            }}
                        </td>
                        <td class="px-4 py-2 text-right">
                            <Button
                                size="sm"
                                variant="outline"
                                @click="revoke(t.id, t.name, t.owner)"
                                >Revoke</Button
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
