<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { Download, FileText, Paperclip, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { timeAgo } from '@/lib/format';
import { destroy, store } from '@/routes/attachments';
import type { AttachmentRow, FileLimits } from '@/types/files';

/**
 * Files on a task / project. Links are signed and expire after a few minutes;
 * reload the page if one stops working.
 */
const props = defineProps<{
    attachableType: 'task' | 'project' | 'comment' | 'workspace';
    attachableId: number;
    attachments: AttachmentRow[];
    limits: FileLimits;
    canUpload: boolean;
}>();

const page = usePage();
const input = ref<HTMLInputElement | null>(null);
const form = useForm<{ attachable_type: string; attachable_id: number; file: File | null }>({
    attachable_type: props.attachableType,
    attachable_id: props.attachableId,
    file: null,
});
const accept = computed(() => props.limits.extensions.map((e) => `.${e}`).join(','));
const fileError = computed(() => form.errors.file ?? (page.props.errors as Record<string, string> | undefined)?.file);

function pick(event: Event): void {
    const target = event.target as HTMLInputElement;
    form.file = target.files?.[0] ?? null;
    if (form.file) {
        form.post(store.url(), {
            forceFormData: true,
            preserveScroll: true,
            onFinish: () => {
                form.reset('file');
                if (input.value) {
                    input.value.value = '';
                }
            },
        });
    }
}

function remove(id: number, name: string): void {
    if (window.confirm(`Delete ${name}?`)) {
        router.delete(destroy.url(id), { preserveScroll: true });
    }
}

function size(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }
    if (bytes < 1024 * 1024) {
        return `${Math.round(bytes / 1024)} KB`;
    }

    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle class="flex items-center gap-2 text-base"><Paperclip class="size-4" /> Files</CardTitle>
            <CardDescription>Up to {{ limits.max_mb }} MB · {{ limits.extensions.join(', ') }}</CardDescription>
        </CardHeader>
        <CardContent class="space-y-3">
            <div v-if="canUpload">
                <input ref="input" type="file" class="hidden" :accept="accept" @change="pick" />
                <Button size="sm" variant="outline" :disabled="form.processing" @click="input?.click()">
                    {{ form.processing ? `Uploading ${form.progress?.percentage ?? 0}%` : 'Attach a file' }}
                </Button>
                <InputError :message="fileError" />
            </div>

            <p v-if="attachments.length === 0" class="text-sm text-muted-foreground">No files yet.</p>
            <ul v-else class="divide-y text-sm">
                <li v-for="a in attachments" :key="a.id" class="flex items-center gap-3 py-2">
                    <a :href="a.url" target="_blank" rel="noopener" class="shrink-0">
                        <img v-if="a.thumb_url" :src="a.thumb_url" :alt="a.name" class="size-10 rounded object-cover" loading="lazy" />
                        <span v-else class="flex size-10 items-center justify-center rounded bg-muted"><FileText class="size-5 text-muted-foreground" /></span>
                    </a>
                    <div class="min-w-0 flex-1">
                        <a :href="a.url" target="_blank" rel="noopener" class="block truncate font-medium hover:underline">{{ a.name }}</a>
                        <p class="text-xs text-muted-foreground">{{ size(a.size) }} · {{ a.uploader ?? 'Former member' }} · {{ timeAgo(a.created_at) }}</p>
                    </div>
                    <Button variant="ghost" size="icon" as-child>
                        <a :href="a.download_url" :aria-label="`Download ${a.name}`"><Download class="size-4" /></a>
                    </Button>
                    <Button v-if="a.can_delete" variant="ghost" size="icon" :aria-label="`Delete ${a.name}`" @click="remove(a.id, a.name)">
                        <Trash2 class="size-4" />
                    </Button>
                </li>
            </ul>
        </CardContent>
    </Card>
</template>
