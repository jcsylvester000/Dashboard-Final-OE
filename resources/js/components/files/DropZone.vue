<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { CheckCircle2, CloudUpload, XCircle } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { formatBytes } from '@/lib/format';
import {
    cancel as cancelRoute,
    complete as completeRoute,
    prepare as prepareRoute,
} from '@/routes/drive';

/**
 * Drag-and-drop (or click) uploads to the team drive. Each file:
 *   1. POST /drive/uploads  -> our server checks type + quota, returns a presigned UploadThing URL
 *   2. PUT the file straight to UploadThing (progress bar)
 *   3. POST /drive/{id}/complete -> our server confirms the file is there
 */
const props = defineProps<{
    freeBytes: number;
    allowed: string[];
    disabled?: boolean;
}>();

type Item = {
    id: number;
    name: string;
    size: number;
    progress: number;
    status: 'waiting' | 'uploading' | 'checking' | 'done' | 'error';
    error?: string;
};

const input = ref<HTMLInputElement | null>(null);
const dragging = ref(false);
const items = ref<Item[]>([]);
let seq = 0;
const busy = computed(() =>
    items.value.some((i) =>
        ['waiting', 'uploading', 'checking'].includes(i.status),
    ),
);
const accept = computed(() => props.allowed.map((e) => `.${e}`).join(','));

function xsrf(): string {
    const raw = document.cookie
        .split('; ')
        .find((c) => c.startsWith('XSRF-TOKEN='));

    return raw ? decodeURIComponent(raw.split('=')[1]) : '';
}

async function postJson(
    url: string,
    body: Record<string, unknown>,
): Promise<Record<string, unknown>> {
    const res = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': xsrf(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(body),
    });
    const data = (await res.json().catch(() => ({}))) as Record<
        string,
        unknown
    >;
    if (!res.ok) {
        const errors = data.errors as Record<string, string[]> | undefined;
        const first = errors ? Object.values(errors)[0]?.[0] : undefined;
        throw new Error(
            first ??
                (data.message as string | undefined) ??
                `Request failed (${res.status})`,
        );
    }

    return data;
}

function put(url: string, file: File, item: Item): Promise<void> {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('PUT', url);
        xhr.upload.onprogress = (e) => {
            if (e.lengthComputable) {
                item.progress = Math.round((e.loaded / e.total) * 100);
            }
        };
        xhr.onload = () =>
            xhr.status >= 200 && xhr.status < 300
                ? resolve()
                : reject(
                      new Error(
                          `Upload failed (${xhr.status}) ${xhr.responseText.slice(0, 160)}`,
                      ),
                  );
        xhr.onerror = () =>
            reject(
                new Error('Upload failed (network or blocked by the browser).'),
            );
        const form = new FormData();
        form.append('file', file);
        xhr.send(form);
    });
}

async function uploadOne(file: File, item: Item): Promise<void> {
    try {
        item.status = 'uploading';
        const prepared = await postJson(prepareRoute.url(), {
            name: file.name,
            size: file.size,
            type: file.type || null,
        });
        try {
            await put(String(prepared.url), file, item);
        } catch (e) {
            // Give the reserved space back straight away.
            await postJson(cancelRoute.url(Number(prepared.id)), {}).catch(
                () => undefined,
            );
            throw e;
        }
        item.status = 'checking';
        await postJson(completeRoute.url(Number(prepared.id)), {});
        item.progress = 100;
        item.status = 'done';
    } catch (e) {
        item.status = 'error';
        item.error = e instanceof Error ? e.message : 'Upload failed.';
    }
}

async function add(files: FileList | File[] | null): Promise<void> {
    if (!files || props.disabled) {
        return;
    }
    const list = Array.from(files);
    let free = props.freeBytes;
    const queue: [File, Item][] = [];

    for (const file of list) {
        items.value.unshift({
            id: ++seq,
            name: file.name,
            size: file.size,
            progress: 0,
            status: 'waiting',
        });
        // Use the reactive copy so progress updates show on screen.
        const item = items.value[0];
        const ext = file.name.split('.').pop()?.toLowerCase() ?? '';
        if (!props.allowed.includes(ext)) {
            Object.assign(item, {
                status: 'error',
                error: `.${ext} files aren't allowed.`,
            });
        } else if (file.size > free) {
            Object.assign(item, {
                status: 'error',
                error: `Not enough space (${formatBytes(free)} left).`,
            });
        } else {
            free -= file.size;
            queue.push([file, item]);
        }
    }

    // One at a time keeps the quota check simple and the progress readable.
    for (const [file, item] of queue) {
        await uploadOne(file, item);
    }

    if (queue.length) {
        router.reload({ only: ['files', 'quota'] });
    }
    if (input.value) {
        input.value.value = '';
    }
}

function onDrop(e: DragEvent): void {
    dragging.value = false;
    void add(e.dataTransfer?.files ?? null);
}

function clearFinished(): void {
    items.value = items.value.filter(
        (i) => !['done', 'error'].includes(i.status),
    );
}
</script>

<template>
    <div class="space-y-3">
        <div
            role="button"
            tabindex="0"
            :aria-disabled="disabled"
            class="flex flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed px-6 py-10 text-center transition"
            :class="[
                dragging
                    ? 'border-primary bg-primary/5'
                    : 'border-muted-foreground/25 hover:border-muted-foreground/50',
                disabled ? 'cursor-not-allowed opacity-60' : 'cursor-pointer',
            ]"
            @click="!disabled && input?.click()"
            @keydown.enter.prevent="!disabled && input?.click()"
            @keydown.space.prevent="!disabled && input?.click()"
            @dragover.prevent="dragging = !disabled"
            @dragleave.prevent="dragging = false"
            @drop.prevent="onDrop"
        >
            <CloudUpload class="size-8 text-muted-foreground" />
            <p class="text-sm font-medium">
                Drop pictures and files here, or click to choose
            </p>
            <p class="text-xs text-muted-foreground">
                {{ formatBytes(freeBytes) }} free · {{ allowed.join(', ') }}
            </p>
            <input
                ref="input"
                type="file"
                multiple
                class="hidden"
                :accept="accept"
                data-testid="drive-file-input"
                @change="add(($event.target as HTMLInputElement).files)"
            />
        </div>

        <ul v-if="items.length" class="space-y-2 text-sm">
            <li
                v-for="i in items"
                :key="i.id"
                class="rounded-md border px-3 py-2"
            >
                <div class="flex items-center justify-between gap-3">
                    <span
                        class="min-w-0 truncate font-medium"
                        :title="i.name"
                        >{{ i.name }}</span
                    >
                    <span
                        class="flex shrink-0 items-center gap-1 text-xs text-muted-foreground"
                    >
                        {{ formatBytes(i.size) }}
                        <CheckCircle2
                            v-if="i.status === 'done'"
                            class="size-4 text-emerald-600"
                        />
                        <XCircle
                            v-else-if="i.status === 'error'"
                            class="size-4 text-rose-600"
                        />
                        <template v-else
                            >·
                            {{
                                i.status === 'checking'
                                    ? 'finishing…'
                                    : `${i.progress}%`
                            }}</template
                        >
                    </span>
                </div>
                <div
                    v-if="i.status !== 'error'"
                    class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-muted"
                >
                    <div
                        class="h-full rounded-full bg-primary transition-all"
                        :style="{ width: `${i.progress}%` }"
                    />
                </div>
                <p v-else class="mt-1 text-xs text-rose-600">{{ i.error }}</p>
            </li>
        </ul>
        <Button
            v-if="items.length && !busy"
            variant="ghost"
            size="sm"
            @click="clearFinished"
            >Clear list</Button
        >
    </div>
</template>
