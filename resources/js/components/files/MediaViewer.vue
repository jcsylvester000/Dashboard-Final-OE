<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    ChevronLeft,
    ChevronRight,
    Columns2,
    Download,
    Eye,
    Minus,
    MessageSquarePlus,
    Plus,
    X,
} from '@lucide/vue';
import {
    computed,
    defineAsyncComponent,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import type { Tool } from '@/components/files/AnnotationCanvas.vue';
import AnnotationPanel from '@/components/files/AnnotationPanel.vue';
import { Button } from '@/components/ui/button';
import { timeAgo } from '@/lib/format';
import {
    destroy as destroyAnnotation,
    update as updateAnnotation,
} from '@/routes/annotations';
import { review } from '@/routes/attachments';
import {
    index as annotationsIndex,
    store as storeAnnotation,
} from '@/routes/attachments/annotations';
import type {
    AnnotationPin,
    AnnotationShape,
    AttachmentRow,
} from '@/types/files';
import { ANNOTATION_COLORS } from '@/types/files';

// E-123: heavy viewer modes load only when first used.
const AnnotationCanvas = defineAsyncComponent(
    () => import('@/components/files/AnnotationCanvas.vue'),
);
const CompareView = defineAsyncComponent(
    () => import('@/components/files/CompareView.vue'),
);

/**
 * E-01: review files without leaving the task. Images zoom and pan, PDFs use the
 * browser's viewer, videos play inline. Arrows move between files, Esc closes,
 * + / - / 0 zoom. Reviewers approve or request changes on the version shown (E-06).
 *
 * E-04 / E-05: Annotate mode drops numbered pins and draws on images (stored as
 * fractions of the image, never burned into the file). E-03: Compare two versions side
 * by side or with a slider. E-56: on a phone the panel sits under the image, swipe
 * changes file, pinch zooms. E-60: focus stays in the viewer, saves are announced, pins
 * can be added and moved with the keyboard.
 */
const props = withDefaults(
    defineProps<{
        items: AttachmentRow[];
        start: number;
        initialMode?: 'view' | 'annotate';
    }>(),
    { initialMode: 'view' },
);
const emit = defineEmits<{ close: [] }>();

const root = ref<HTMLElement | null>(null);
const closeButton = ref<{ $el: HTMLElement } | null>(null);
const announce = ref('');

const index = ref(props.start);
const item = computed(() => props.items[index.value]);
const versionId = ref<number | null>(null);
const mode = ref<'view' | 'annotate' | 'compare'>(props.initialMode);
watch(index, () => {
    versionId.value = null;
    resetZoom();
    if (mode.value === 'compare') {
        mode.value = 'view';
    }
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

// Zoom and pan (images); pinch on touch screens.
const scale = ref(1);
const offset = ref({ x: 0, y: 0 });
let drag: { x: number; y: number; startX: number; startY: number } | null =
    null;
const touches = new Map<number, { x: number; y: number }>();
let pinchFrom: { dist: number; scale: number } | null = null;
function resetZoom(): void {
    scale.value = 1;
    offset.value = { x: 0, y: 0 };
}
function zoom(by: number): void {
    scale.value = Math.min(8, Math.max(0.25, +(scale.value * by).toFixed(2)));
}
function onWheel(e: WheelEvent): void {
    if (kind.value === 'image' && mode.value !== 'compare') {
        e.preventDefault();
        zoom(e.deltaY < 0 ? 1.15 : 1 / 1.15);
    }
}
function onDown(e: PointerEvent): void {
    touches.set(e.pointerId, { x: e.clientX, y: e.clientY });
    if (touches.size === 2) {
        const [a, b] = [...touches.values()];
        pinchFrom = {
            dist: Math.hypot(a.x - b.x, a.y - b.y),
            scale: scale.value,
        };
        drag = null;
        return;
    }
    drag = {
        x: e.clientX - offset.value.x,
        y: e.clientY - offset.value.y,
        startX: e.clientX,
        startY: e.clientY,
    };
    (e.currentTarget as HTMLElement).setPointerCapture?.(e.pointerId);
}
function onMove(e: PointerEvent): void {
    if (touches.has(e.pointerId)) {
        touches.set(e.pointerId, { x: e.clientX, y: e.clientY });
    }
    if (pinchFrom && touches.size === 2) {
        const [a, b] = [...touches.values()];
        scale.value = Math.min(
            8,
            Math.max(
                0.5,
                pinchFrom.scale *
                    (Math.hypot(a.x - b.x, a.y - b.y) / pinchFrom.dist),
            ),
        );
        return;
    }
    if (drag && scale.value !== 1) {
        offset.value = { x: e.clientX - drag.x, y: e.clientY - drag.y };
    }
}
function onUp(e: PointerEvent): void {
    touches.delete(e.pointerId);
    if (touches.size < 2) {
        pinchFrom = null;
    }
    // Swipe between files when not zoomed in (phones).
    if (drag && scale.value === 1 && mode.value === 'view') {
        const dx = e.clientX - drag.startX;
        const dy = e.clientY - drag.startY;
        if (Math.abs(dx) > 60 && Math.abs(dy) < 50 && props.items.length > 1) {
            go(dx < 0 ? 1 : -1);
        }
    }
    drag = null;
}

function go(step: number): void {
    const n = props.items.length;
    index.value = (index.value + step + n) % n;
}

/* ---------- E-04 / E-05: pins and drawings ---------- */
const pins = ref<AnnotationPin[]>([]);
const canWrite = ref(false);
const selectedId = ref<number | null>(null);
const tool = ref<Tool>(props.initialMode === 'annotate' ? 'pin' : 'move');
const color = ref(ANNOTATION_COLORS[0]);
const draftPin = ref<{ x: number; y: number } | null>(null);
const draftShapes = ref<AnnotationShape[]>([]);
const undone = ref<AnnotationShape[]>([]);
const busy = ref(false);
const drafting = computed(
    () => draftPin.value !== null || draftShapes.value.length > 0,
);
const showPins = computed(() =>
    pins.value.filter((p) => !p.resolved || p.id === selectedId.value),
);

function xsrf(): string {
    return decodeURIComponent(
        document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
    );
}
async function call(
    url: string,
    method: string,
    body?: unknown,
): Promise<boolean> {
    busy.value = true;
    try {
        const res = await fetch(url, {
            method,
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': xsrf(),
            },
            body: body ? JSON.stringify(body) : undefined,
        });
        const data = await res.json().catch(() => null);
        if (!res.ok) {
            announce.value = data?.message ?? 'Could not save. Try again.';
            return false;
        }
        if (Array.isArray(data?.pins)) {
            pins.value = data.pins;
        }
        if (typeof data?.can_write === 'boolean') {
            canWrite.value = data.can_write;
        }
        return true;
    } finally {
        busy.value = false;
    }
}
async function loadPins(): Promise<void> {
    pins.value = [];
    selectedId.value = null;
    cancelDraft();
    if (kind.value === 'image') {
        await call(annotationsIndex.url(shown.value.id), 'GET');
    }
}
watch(() => shown.value.id, loadPins, { immediate: true });

function cancelDraft(): void {
    draftPin.value = null;
    draftShapes.value = [];
    undone.value = [];
}
function place(x: number, y: number): void {
    draftPin.value = { x, y };
    selectedId.value = null;
}
function addShape(s: AnnotationShape): void {
    if (draftShapes.value.length < 50) {
        draftShapes.value.push(s);
        undone.value = [];
    }
}
function undo(): void {
    const s = draftShapes.value.pop();
    if (s) {
        undone.value = [...undone.value, s].slice(-20);
    }
}
function redo(): void {
    const s = undone.value.pop();
    if (s) {
        draftShapes.value.push(s);
    }
}
function placeCenter(): void {
    place(0.5, 0.5);
    announce.value =
        'Pin placed in the middle. Use the arrow buttons to move it.';
}
function nudge(dx: number, dy: number): void {
    const p = draftPin.value ?? { x: 0.5, y: 0.5 };
    draftPin.value = {
        x: +Math.min(1, Math.max(0, p.x + dx)).toFixed(4),
        y: +Math.min(1, Math.max(0, p.y + dy)).toFixed(4),
    };
}
async function savePin(body: string): Promise<void> {
    const ok = await call(storeAnnotation.url(shown.value.id), 'POST', {
        body,
        x: draftPin.value?.x ?? null,
        y: draftPin.value?.y ?? null,
        color: color.value,
        shapes: draftShapes.value.length ? draftShapes.value : null,
    });
    if (ok) {
        const n = pins.value[pins.value.length - 1]?.number;
        announce.value = `Pin ${n ?? ''} saved.`;
        cancelDraft();
        // Keep the file list's pin count current without closing the viewer.
        router.reload({ only: ['attachments'] });
    }
}
async function reply(id: number, body: string): Promise<void> {
    if (
        await call(storeAnnotation.url(shown.value.id), 'POST', {
            body,
            parent_id: id,
        })
    ) {
        announce.value = 'Reply saved.';
    }
}
async function resolve(id: number, resolved: boolean): Promise<void> {
    if (await call(updateAnnotation.url(id), 'PATCH', { resolved })) {
        announce.value = resolved ? 'Pin resolved.' : 'Pin reopened.';
        router.reload({ only: ['attachments'] });
    }
}
async function remove(id: number): Promise<void> {
    if (
        window.confirm('Delete this comment?') &&
        (await call(destroyAnnotation.url(id), 'DELETE'))
    ) {
        announce.value = 'Comment deleted.';
        selectedId.value = null;
    }
}
function select(id: number | null): void {
    selectedId.value = id;
    const p = pins.value.find((x) => x.id === id);
    if (p && p.x !== null && p.y !== null && scale.value > 1) {
        resetZoom(); // bring the pin into view
    }
}

/* ---------- E-03: compare two versions ---------- */
const compareWith = ref<number | null>(null);
const compareMode = ref<'side' | 'slider'>('side');
const allVersions = computed(() => [
    {
        id: item.value.id,
        version: item.value.version ?? 1,
        url: item.value.url,
    },
    ...(item.value.versions ?? []).map((v) => ({
        id: v.id,
        version: v.version,
        url: v.url,
    })),
]);
const compareTarget = computed(
    () =>
        allVersions.value.find((v) => v.id === compareWith.value) ??
        allVersions.value.find((v) => v.id !== shown.value.id),
);
function startCompare(): void {
    cancelDraft();
    mode.value = 'compare';
    compareWith.value = compareTarget.value?.id ?? null;
}

/* ---------- keyboard and focus (E-60) ---------- */
function focusables(): HTMLElement[] {
    return [
        ...(root.value?.querySelectorAll<HTMLElement>(
            'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])',
        ) ?? []),
    ].filter((el) => !el.hasAttribute('disabled') && el.offsetParent !== null);
}
function onKey(e: KeyboardEvent): void {
    if (e.key === 'Tab') {
        const list = focusables();
        if (list.length) {
            const first = list[0];
            const last = list[list.length - 1];
            if (e.shiftKey && document.activeElement === first) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        }
        return;
    }
    const tag = (e.target as HTMLElement | null)?.tagName;
    if (tag === 'TEXTAREA' || tag === 'INPUT' || tag === 'SELECT') {
        return;
    }
    if (
        mode.value === 'annotate' &&
        (e.ctrlKey || e.metaKey) &&
        e.key.toLowerCase() === 'z'
    ) {
        e.preventDefault();
        undo();
    } else if (
        mode.value === 'annotate' &&
        (e.ctrlKey || e.metaKey) &&
        e.key.toLowerCase() === 'y'
    ) {
        e.preventDefault();
        redo();
    } else if (e.key === 'Escape') {
        if (drafting.value) {
            cancelDraft();
        } else {
            emit('close');
        }
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
let previousFocus: Element | null = null;
onMounted(() => {
    previousFocus = document.activeElement;
    window.addEventListener('keydown', onKey);
    void nextTick(() => closeButton.value?.$el?.focus());
});
onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKey);
    (previousFocus as HTMLElement | null)?.focus?.();
});

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
                announce.value =
                    decision === 'approve'
                        ? 'Approved.'
                        : decision === 'changes'
                          ? 'Sent back with your note.'
                          : 'Decision cleared.';
            },
        },
    );
}
</script>

<template>
    <div
        ref="root"
        class="fixed inset-0 z-50 flex flex-col bg-neutral-950 text-white md:flex-row"
        role="dialog"
        aria-modal="true"
        :aria-label="`Viewing ${item.name}`"
    >
        <p class="sr-only" aria-live="polite">{{ announce }}</p>

        <div
            class="relative flex min-h-0 min-w-0 flex-1 items-center justify-center overflow-hidden"
            @wheel="onWheel"
        >
            <CompareView
                v-if="mode === 'compare' && compareTarget"
                :left="{
                    url: compareTarget.url,
                    label: `v${compareTarget.version}`,
                }"
                :right="{ url: shown.url, label: `v${shown.version}` }"
                :mode="compareMode"
            />
            <div
                v-else-if="kind === 'image'"
                class="relative inline-block max-w-full touch-none select-none"
                :class="
                    tool === 'move' || mode === 'view'
                        ? 'cursor-grab active:cursor-grabbing'
                        : ''
                "
                :style="{
                    transform: `translate(${offset.x}px, ${offset.y}px) scale(${scale})`,
                }"
                @pointerdown="onDown"
                @pointermove="onMove"
                @pointerup="onUp"
                @pointercancel="onUp"
                @dblclick="
                    mode === 'view' && (scale === 1 ? zoom(2) : resetZoom())
                "
            >
                <img
                    :src="shown.url"
                    :alt="item.name"
                    class="block max-h-[58vh] max-w-full md:max-h-[94vh]"
                    draggable="false"
                />
                <AnnotationCanvas
                    v-if="mode === 'annotate' || pins.length"
                    :pins="
                        mode === 'annotate'
                            ? showPins
                            : showPins.filter((p) => !p.resolved)
                    "
                    :draft-shapes="draftShapes"
                    :draft-pin="draftPin"
                    :tool="mode === 'annotate' ? tool : 'move'"
                    :color="color"
                    :selected-id="selectedId"
                    :scale="scale"
                    @place="place"
                    @shape="addShape"
                    @select="
                        (id) => {
                            mode = 'annotate';
                            select(id);
                        }
                    "
                />
            </div>
            <iframe
                v-else-if="kind === 'pdf'"
                :src="shown.url"
                class="h-full w-full bg-white"
                :title="item.name"
            />
            <video
                v-else-if="kind === 'video'"
                :key="shown.url"
                :src="shown.url"
                :poster="
                    shown.latest ? (item.thumb_url ?? undefined) : undefined
                "
                controls
                playsinline
                preload="metadata"
                class="max-h-full max-w-full"
            />
            <p
                v-if="
                    kind === 'video' &&
                    shown.latest &&
                    item.video?.status === 'processing'
                "
                class="absolute bottom-4 left-1/2 -translate-x-1/2 rounded bg-black/70 px-3 py-1 text-xs"
                role="status"
            >
                Preparing a web copy. If the video doesn't play yet, try again
                in a few minutes.
            </p>
            <div v-else class="text-center text-sm">
                <p>No preview for this file type.</p>
                <a :href="shown.download_url" class="underline"
                    >Download {{ item.name }}</a
                >
            </div>

            <div
                class="absolute top-3 left-3 flex flex-wrap items-center gap-1"
            >
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
                <template v-if="kind === 'image' && mode !== 'compare'">
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
            class="flex max-h-[42vh] w-full flex-col gap-3 overflow-y-auto border-t border-white/10 bg-neutral-950 p-4 text-sm md:max-h-none md:max-w-xs md:border-t-0 md:border-l"
        >
            <div class="flex items-start justify-between gap-2">
                <p class="font-medium break-words">{{ item.name }}</p>
                <Button
                    ref="closeButton"
                    size="icon"
                    variant="ghost"
                    class="text-white hover:bg-white/10"
                    aria-label="Close viewer (Esc)"
                    @click="emit('close')"
                    ><X class="size-4"
                /></Button>
            </div>

            <div
                v-if="kind === 'image'"
                class="grid grid-cols-3 gap-1"
                role="tablist"
                aria-label="Viewer mode"
            >
                <button
                    type="button"
                    role="tab"
                    class="flex items-center justify-center gap-1 rounded border border-white/20 py-1.5 text-xs"
                    :class="
                        mode === 'view'
                            ? 'bg-white text-black'
                            : 'hover:bg-white/10'
                    "
                    :aria-selected="mode === 'view'"
                    @click="
                        mode = 'view';
                        tool = 'move';
                    "
                >
                    <Eye class="size-3.5" /> View
                </button>
                <button
                    type="button"
                    role="tab"
                    class="flex items-center justify-center gap-1 rounded border border-white/20 py-1.5 text-xs"
                    :class="
                        mode === 'annotate'
                            ? 'bg-white text-black'
                            : 'hover:bg-white/10'
                    "
                    :aria-selected="mode === 'annotate'"
                    @click="
                        mode = 'annotate';
                        tool = canWrite ? 'pin' : 'move';
                    "
                >
                    <MessageSquarePlus class="size-3.5" /> Pins
                    <span
                        v-if="pins.filter((p) => !p.resolved).length"
                        class="rounded-full bg-amber-400 px-1.5 text-[10px] font-bold text-black"
                        >{{ pins.filter((p) => !p.resolved).length }}</span
                    >
                </button>
                <button
                    type="button"
                    role="tab"
                    class="flex items-center justify-center gap-1 rounded border border-white/20 py-1.5 text-xs disabled:opacity-40"
                    :class="
                        mode === 'compare'
                            ? 'bg-white text-black'
                            : 'hover:bg-white/10'
                    "
                    :aria-selected="mode === 'compare'"
                    :disabled="allVersions.length < 2"
                    :title="
                        allVersions.length < 2
                            ? 'Only one version'
                            : 'Compare versions'
                    "
                    @click="startCompare"
                >
                    <Columns2 class="size-3.5" /> Compare
                </button>
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

            <div v-if="mode === 'compare'" class="grid gap-2 text-xs">
                <label class="grid gap-1"
                    ><span class="text-white/60"
                        >Compare v{{ shown.version }} with</span
                    >
                    <select
                        v-model="compareWith"
                        class="h-8 rounded border border-white/20 bg-neutral-900 px-2"
                    >
                        <option
                            v-for="v in allVersions.filter(
                                (x) => x.id !== shown.id,
                            )"
                            :key="v.id"
                            :value="v.id"
                        >
                            v{{ v.version }}
                        </option>
                    </select>
                </label>
                <div class="grid grid-cols-2 gap-1">
                    <Button
                        size="sm"
                        :variant="
                            compareMode === 'side' ? 'default' : 'secondary'
                        "
                        @click="compareMode = 'side'"
                        >Side by side</Button
                    >
                    <Button
                        size="sm"
                        :variant="
                            compareMode === 'slider' ? 'default' : 'secondary'
                        "
                        @click="compareMode = 'slider'"
                        >Slider</Button
                    >
                </div>
            </div>

            <AnnotationPanel
                v-if="mode === 'annotate' && kind === 'image'"
                v-model:tool="tool"
                v-model:color="color"
                :pins="pins"
                :can-write="canWrite"
                :selected-id="selectedId"
                :drafting="drafting"
                :draft-count="draftShapes.length"
                :can-undo="draftShapes.length > 0"
                :can-redo="undone.length > 0"
                :busy="busy"
                @select="select"
                @undo="undo"
                @redo="redo"
                @save="savePin"
                @cancel="cancelDraft"
                @reply="reply"
                @resolve="resolve"
                @remove="remove"
                @place-center="placeCenter"
                @nudge="nudge"
            />
            <p v-else-if="kind === 'pdf'" class="text-xs text-white/50">
                Pins and drawing work on images. PDFs open in the browser's
                viewer.
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

            <!-- E-56: decisions stay within thumb reach on a phone. -->
            <div
                v-if="item.can_approve && shown.latest"
                class="sticky bottom-0 -mx-4 mt-auto border-t border-white/10 bg-neutral-950 px-4 pt-2 pb-1"
            >
                <div v-if="!asking" class="grid grid-cols-2 gap-2">
                    <Button
                        class="h-11 bg-emerald-600 hover:bg-emerald-700"
                        @click="decide('approve')"
                        >Approve v{{ shown.version }}</Button
                    >
                    <Button
                        class="h-11"
                        variant="secondary"
                        @click="asking = true"
                        >Request changes</Button
                    >
                    <Button
                        v-if="shown.review_status"
                        size="sm"
                        variant="ghost"
                        class="col-span-2 text-white hover:bg-white/10"
                        @click="decide('clear')"
                        >Clear decision</Button
                    >
                </div>
                <form
                    v-else
                    class="grid gap-2"
                    @submit.prevent="decide('changes')"
                >
                    <textarea
                        v-model="note"
                        rows="3"
                        required
                        maxlength="2000"
                        class="rounded border border-white/20 bg-neutral-900 p-2 text-sm"
                        placeholder="What needs to change? (Pins on the image help.)"
                        aria-label="What needs to change"
                    />
                    <div class="flex gap-2">
                        <Button type="submit" :disabled="!note.trim()"
                            >Send back</Button
                        >
                        <Button
                            type="button"
                            variant="ghost"
                            class="text-white hover:bg-white/10"
                            @click="asking = false"
                            >Cancel</Button
                        >
                    </div>
                </form>
            </div>
            <p v-else-if="!shown.latest" class="text-xs text-white/60">
                Older version: decisions apply to the latest.
            </p>

            <a
                :href="shown.download_url"
                class="inline-flex items-center gap-1 text-xs text-white/80 underline"
                ><Download class="size-3.5" /> Download this version</a
            >
            <p class="hidden text-[11px] text-white/40 md:block">
                ← → files · + − 0 zoom · Ctrl+Z undo · Esc close
            </p>
            <p class="text-[11px] text-white/40 md:hidden">
                Swipe for the next file · pinch to zoom
            </p>
        </aside>
    </div>
</template>
