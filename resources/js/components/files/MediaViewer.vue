<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    ChevronLeft,
    ChevronRight,
    Download,
    Minus,
    Plus,
    X,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { timeAgo } from '@/lib/format';
import { review } from '@/routes/attachments';
import type { AttachmentRow } from '@/types/files';

/**
 * E-01: review files without leaving the task. Images zoom and pan, PDFs use the
 * browser's viewer, videos play inline. Arrows move between files, Esc closes,
 * + / - / 0 zoom. Reviewers approve or request changes on the version shown (E-06).
 */
const props = defineProps<{ items: AttachmentRow[]; start: number }>();
const emit = defineEmits<{ close: [] }>();

const index = ref(props.start);
const item = computed(() => props.items[index.value]);
const versionId = ref<number | null>(null);
watch(index, () => {
    versionId.value = null;
    resetZoom();
});
const shown = computed(() => {
    const it = item.value;
    const old =
        versionId.value !== null
            ? it.versions?.find((v) => v.id === versionId.value)
            : undefined;

    return old
        ? {
              id: old.id,
              version: old.version,
              url: old.url,
              download_url: old.download_url,
              mime: old.mime,
              latest: false,
              review_status: old.review_status ?? null,
              review_note: old.review_note ?? null,
              uploader: old.uploader,
              created_at: old.created_at,
          }
        : {
              id: it.id,
              version: it.version ?? 1,
              url: it.url,
              download_url: it.download_url,
              mime: it.mime,
              latest: true,
              review_status:
                  it.review_status ?? (it.approved ? 'approved' : null),
              review_note: it.review_note ?? null,
              uploader: it.uploader,
              created_at: it.created_at,
          };
});
const kind = computed(() =>
    shown.value.mime.startsWith('image/')
        ? 'image'
        : shown.value.mime === 'application/pdf'
          ? 'pdf'
          : shown.value.mime.startsWith('video/')
            ? 'video'
            : 'other',
);

// Zoom and pan (images).
const scale = ref(1);
const offset = ref({ x: 0, y: 0 });
let drag: { x: number; y: number } | null = null;
function resetZoom(): void {
    scale.value = 1;
    offset.value = { x: 0, y: 0 };
}
function zoom(by: number): void {
    scale.value = Math.min(8, Math.max(0.25, +(scale.value * by).toFixed(2)));
}
function onWheel(e: WheelEvent): void {
    if (kind.value === 'image') {
        e.preventDefault();
        zoom(e.deltaY < 0 ? 1.15 : 1 / 1.15);
    }
}
function onDown(e: PointerEvent): void {
    drag = { x: e.clientX - offset.value.x, y: e.clientY - offset.value.y };
    (e.target as HTMLElement).setPointerCapture?.(e.pointerId);
}
function onMove(e: PointerEvent): void {
    if (drag) {
        offset.value = { x: e.clientX - drag.x, y: e.clientY - drag.y };
    }
}
function onUp(): void {
    drag = null;
}

function go(step: number): void {
    const n = props.items.length;
    index.value = (index.value + step + n) % n;
}
function onKey(e: KeyboardEvent): void {
    const tag = (e.target as HTMLElement | null)?.tagName;
    if (tag === 'TEXTAREA' || tag === 'INPUT') {
        return;
    }
    if (e.key === 'Escape') {
        emit('close');
    } else if (e.key === 'ArrowRight') {
        go(1);
    } else if (e.key === 'ArrowLeft') {
        go(-1);
    } else if (e.key === '+' || e.key === '=') {
        zoom(1.25);
    } else if (e.key === '-') {
        zoom(0.8);
    } else if (e.key === '0') {
        resetZoom();
    }
}
onMounted(() => window.addEventListener('keydown', onKey));
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));

// E-06: decisions on the latest version.
const note = ref('');
const asking = ref(false);
function decide(decision: 'approve' | 'changes' | 'clear'): void {
    router.post(
        review.url(shown.value.id),
        { decision, note: decision === 'changes' ? note.value : null },
        {
            preserveScroll: true,
            // Reload only what changes so the viewer stays open on the same file.
            only: ['attachments', 'task', 'timeline'],
            onSuccess: () => {
                asking.value = false;
                note.value = '';
            },
        },
    );
}
</script>

<template>
    <div
        class="fixed inset-0 z-50 flex bg-neutral-950 text-white"
        role="dialog"
        aria-modal="true"
        :aria-label="`Viewing ${item.name}`"
    >
        <div
            class="relative flex min-w-0 flex-1 items-center justify-center overflow-hidden"
            @wheel="onWheel"
        >
            <img
                v-if="kind === 'image'"
                :src="shown.url"
                :alt="item.name"
                class="max-h-full max-w-full cursor-grab select-none active:cursor-grabbing"
                draggable="false"
                :style="{
                    transform: `translate(${offset.x}px, ${offset.y}px) scale(${scale})`,
                }"
                @pointerdown="onDown"
                @pointermove="onMove"
                @pointerup="onUp"
                @dblclick="scale === 1 ? zoom(2) : resetZoom()"
            />
            <iframe
                v-else-if="kind === 'pdf'"
                :src="shown.url"
                class="h-full w-full bg-white"
                :title="item.name"
            />
            <video
                v-else-if="kind === 'video'"
                :src="shown.url"
                controls
                class="max-h-full max-w-full"
            />
            <div v-else class="text-center text-sm">
                <p>No preview for this file type.</p>
                <a :href="shown.download_url" class="underline"
                    >Download {{ item.name }}</a
                >
            </div>

            <div class="absolute top-3 left-3 flex items-center gap-1">
                <Button
                    size="icon"
                    variant="secondary"
                    aria-label="Previous file"
                    :disabled="items.length < 2"
                    @click="go(-1)"
                    ><ChevronLeft class="size-4"
                /></Button>
                <Button
                    size="icon"
                    variant="secondary"
                    aria-label="Next file"
                    :disabled="items.length < 2"
                    @click="go(1)"
                    ><ChevronRight class="size-4"
                /></Button>
                <template v-if="kind === 'image'">
                    <Button
                        size="icon"
                        variant="secondary"
                        aria-label="Zoom out"
                        @click="zoom(0.8)"
                        ><Minus class="size-4"
                    /></Button>
                    <Button
                        size="sm"
                        variant="secondary"
                        class="w-16"
                        aria-label="Reset zoom"
                        @click="resetZoom"
                        >{{ Math.round(scale * 100) }}%</Button
                    >
                    <Button
                        size="icon"
                        variant="secondary"
                        aria-label="Zoom in"
                        @click="zoom(1.25)"
                        ><Plus class="size-4"
                    /></Button>
                </template>
                <span class="ml-2 text-xs text-white/70"
                    >{{ index + 1 }} / {{ items.length }}</span
                >
            </div>
        </div>

        <aside
            class="flex w-full max-w-xs flex-col gap-3 overflow-y-auto border-l border-white/10 bg-neutral-950 p-4 text-sm"
        >
            <div class="flex items-start justify-between gap-2">
                <p class="font-medium break-words">{{ item.name }}</p>
                <Button
                    size="icon"
                    variant="ghost"
                    class="text-white hover:bg-white/10"
                    aria-label="Close viewer (Esc)"
                    @click="emit('close')"
                    ><X class="size-4"
                /></Button>
            </div>

            <label v-if="item.versions?.length" class="grid gap-1 text-xs">
                <span class="text-white/60">Version</span>
                <select
                    v-model="versionId"
                    class="h-8 rounded border border-white/20 bg-neutral-900 px-2"
                >
                    <option :value="null">
                        v{{ item.version ?? 1 }} (latest)
                    </option>
                    <option
                        v-for="v in item.versions"
                        :key="v.id"
                        :value="v.id"
                    >
                        v{{ v.version }} · {{ timeAgo(v.created_at) }}
                    </option>
                </select>
            </label>
            <p class="text-xs text-white/60">
                v{{ shown.version }} · {{ shown.uploader ?? 'Former member' }} ·
                {{ timeAgo(shown.created_at) }}
            </p>

            <p
                v-if="shown.review_status === 'approved'"
                class="rounded bg-emerald-900/60 px-2 py-1 text-xs"
            >
                Approved
            </p>
            <div
                v-else-if="shown.review_status === 'changes'"
                class="rounded bg-amber-900/60 px-2 py-1 text-xs"
            >
                <p class="font-medium">Changes requested</p>
                <p
                    v-if="shown.review_note"
                    class="whitespace-pre-line text-white/80"
                >
                    {{ shown.review_note }}
                </p>
            </div>

            <template v-if="item.can_approve && shown.latest">
                <div v-if="!asking" class="flex flex-wrap gap-2">
                    <Button
                        size="sm"
                        class="bg-emerald-600 hover:bg-emerald-700"
                        @click="decide('approve')"
                        >Approve v{{ shown.version }}</Button
                    >
                    <Button size="sm" variant="secondary" @click="asking = true"
                        >Request changes</Button
                    >
                    <Button
                        v-if="shown.review_status"
                        size="sm"
                        variant="ghost"
                        class="text-white hover:bg-white/10"
                        @click="decide('clear')"
                        >Clear</Button
                    >
                </div>
                <form
                    v-else
                    class="grid gap-2"
                    @submit.prevent="decide('changes')"
                >
                    <textarea
                        v-model="note"
                        rows="4"
                        required
                        maxlength="2000"
                        class="rounded border border-white/20 bg-neutral-900 p-2 text-sm"
                        placeholder="What needs to change?"
                        aria-label="What needs to change"
                    />
                    <div class="flex gap-2">
                        <Button size="sm" type="submit" :disabled="!note.trim()"
                            >Send back</Button
                        >
                        <Button
                            size="sm"
                            type="button"
                            variant="ghost"
                            class="text-white hover:bg-white/10"
                            @click="asking = false"
                            >Cancel</Button
                        >
                    </div>
                </form>
            </template>
            <p v-else-if="!shown.latest" class="text-xs text-white/60">
                Older version: decisions apply to the latest.
            </p>

            <a
                :href="shown.download_url"
                class="mt-auto inline-flex items-center gap-1 text-xs text-white/80 underline"
                ><Download class="size-3.5" /> Download this version</a
            >
            <p class="text-[11px] text-white/40">
                ← → files · + − 0 zoom · Esc close
            </p>
        </aside>
    </div>
</template>
