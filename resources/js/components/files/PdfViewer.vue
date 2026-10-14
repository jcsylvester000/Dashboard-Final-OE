<script setup lang="ts">
import { getDocument, GlobalWorkerOptions, TextLayer } from 'pdfjs-dist';
import type { PDFDocumentProxy } from 'pdfjs-dist';
import workerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url';
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

/**
 * E-30: PDF pages rendered in the app (PDF.js) with a selectable text layer, so a
 * reviewer can select text and highlight it with a comment. Highlights are stored as
 * boxes in fractions (0..1) of the page, so they line up at any zoom. Pages render as
 * they scroll into view. Loaded only when a PDF is opened.
 */
export type Highlight = {
    id: number;
    number: number;
    page: number;
    rects: number[][];
    color: string;
    resolved: boolean;
};

GlobalWorkerOptions.workerSrc = workerUrl;

const props = withDefaults(
    defineProps<{
        url: string;
        highlights?: Highlight[];
        selectedId?: number | null;
        draft?: { page: number; rects: number[][] } | null;
    }>(),
    { highlights: () => [], selectedId: null, draft: null },
);
const emit = defineEmits<{
    select: [sel: { page: number; quote: string; rects: number[][] }];
    open: [id: number];
    failed: [];
}>();

const root = ref<HTMLElement | null>(null);
const pages = ref<{ n: number; w: number; h: number }[]>([]);
const status = ref<'loading' | 'ready' | 'failed'>('loading');
const zoom = ref(1);
let doc: PDFDocumentProxy | null = null;
let observer: IntersectionObserver | null = null;
const rendered = new Set<number>();
let baseScale = 1;

async function load(): Promise<void> {
    status.value = 'loading';
    rendered.clear();
    try {
        doc = await getDocument({
            url: props.url,
            isEvalSupported: false,
            withCredentials: true,
        }).promise;
        const first = await doc.getPage(1);
        const width = (root.value?.clientWidth ?? 800) - 32;
        const vp1 = first.getViewport({ scale: 1 });
        baseScale = Math.max(0.5, Math.min(2, width / vp1.width));
        const list = [];
        for (let n = 1; n <= doc.numPages; n++) {
            const vp =
                n === 1
                    ? vp1
                    : (await doc.getPage(n)).getViewport({ scale: 1 });
            list.push({ n, w: vp.width, h: vp.height });
        }
        pages.value = list;
        status.value = 'ready';
        await nextTick();
        observe();
    } catch {
        status.value = 'failed';
        emit('failed');
    }
}

function observe(): void {
    observer?.disconnect();
    observer = new IntersectionObserver(
        (entries) => {
            for (const e of entries) {
                const n = Number((e.target as HTMLElement).dataset.page);
                if (e.isIntersecting && !rendered.has(n)) {
                    rendered.add(n);
                    void renderPage(n, e.target as HTMLElement);
                }
            }
        },
        { root: root.value, rootMargin: '600px 0px' },
    );
    root.value
        ?.querySelectorAll<HTMLElement>('[data-page]')
        .forEach((el) => observer?.observe(el));
}

async function renderPage(n: number, el: HTMLElement): Promise<void> {
    if (!doc) {
        return;
    }
    const page = await doc.getPage(n);
    const scale = baseScale * zoom.value;
    const viewport = page.getViewport({ scale });
    const dpr = window.devicePixelRatio || 1;
    const canvas = el.querySelector('canvas') as HTMLCanvasElement;
    const text = el.querySelector('.textLayer') as HTMLDivElement;
    canvas.width = Math.floor(viewport.width * dpr);
    canvas.height = Math.floor(viewport.height * dpr);
    const ctx = canvas.getContext('2d');
    if (!ctx) {
        return;
    }
    await page.render({
        canvasContext: ctx,
        viewport,
        transform: dpr !== 1 ? [dpr, 0, 0, dpr, 0, 0] : undefined,
    }).promise;
    text.replaceChildren();
    el.style.setProperty('--scale-factor', String(scale));
    await new TextLayer({
        textContentSource: page.streamTextContent(),
        container: text,
        viewport,
    }).render();
}

watch(zoom, async () => {
    rendered.clear();
    await nextTick();
    observe();
});
watch(() => props.url, load);

/** Selected text inside one page -> page number, text and boxes as fractions of the page. */
function onMouseUp(e: MouseEvent): void {
    const sel = window.getSelection();
    if (!sel || sel.isCollapsed || sel.rangeCount === 0) {
        openAt(e); // a plain click on a highlight opens its comment
        return;
    }
    const range = sel.getRangeAt(0);
    const start = (
        range.startContainer instanceof Element
            ? range.startContainer
            : range.startContainer.parentElement
    )?.closest<HTMLElement>('[data-page]');
    if (!start || !root.value?.contains(start)) {
        return;
    }
    const box = start.getBoundingClientRect();
    const rects = Array.from(range.getClientRects())
        .filter(
            (r) =>
                r.width > 1 &&
                r.height > 1 &&
                r.bottom > box.top &&
                r.top < box.bottom,
        )
        .slice(0, 60)
        .map((r) => {
            const x = Math.max(0, (r.left - box.left) / box.width);
            const y = Math.max(0, (r.top - box.top) / box.height);
            return [
                x,
                y,
                Math.min(1 - x, r.width / box.width),
                Math.min(1 - y, r.height / box.height),
            ].map((v) => +v.toFixed(4));
        });
    const quote = sel.toString().replace(/\s+/g, ' ').trim().slice(0, 2000);
    if (quote && rects.length) {
        emit('select', { page: Number(start.dataset.page), quote, rects });
    }
}

function openAt(e: MouseEvent): void {
    const pageEl = (e.target as HTMLElement | null)?.closest<HTMLElement>(
        '[data-page]',
    );
    if (!pageEl) {
        return;
    }
    const box = pageEl.getBoundingClientRect();
    const x = (e.clientX - box.left) / box.width;
    const y = (e.clientY - box.top) / box.height;
    const n = Number(pageEl.dataset.page);
    const hit = props.highlights.find(
        (h) =>
            h.page === n &&
            h.rects.some(
                (r) =>
                    x >= r[0] &&
                    x <= r[0] + r[2] &&
                    y >= r[1] &&
                    y <= r[1] + r[3],
            ),
    );
    if (hit) {
        emit('open', hit.id);
    }
}

function scrollTo(page: number): void {
    root.value
        ?.querySelector(`[data-page="${page}"]`)
        ?.scrollIntoView({ block: 'start', behavior: 'smooth' });
}
defineExpose({ scrollTo });

onMounted(load);
onBeforeUnmount(() => {
    observer?.disconnect();
    void doc?.destroy();
});
</script>

<template>
    <div class="oe-pdf flex h-full w-full flex-col">
        <div
            class="flex items-center gap-2 border-b border-white/10 px-3 py-1 text-xs"
        >
            <button
                type="button"
                class="rounded px-2 py-0.5 hover:bg-white/10"
                aria-label="Zoom out"
                @click="zoom = Math.max(0.5, +(zoom - 0.25).toFixed(2))"
            >
                −
            </button>
            <span class="w-12 text-center">{{ Math.round(zoom * 100) }}%</span>
            <button
                type="button"
                class="rounded px-2 py-0.5 hover:bg-white/10"
                aria-label="Zoom in"
                @click="zoom = Math.min(3, +(zoom + 0.25).toFixed(2))"
            >
                +
            </button>
            <span class="text-white/60"
                >{{ pages.length }} page{{ pages.length === 1 ? '' : 's' }} ·
                select text to highlight it</span
            >
        </div>
        <div
            ref="root"
            class="min-h-0 flex-1 overflow-auto bg-neutral-800 p-4"
            @mouseup="onMouseUp"
        >
            <p
                v-if="status === 'loading'"
                class="text-center text-sm text-white/70"
            >
                Opening PDF…
            </p>
            <p
                v-else-if="status === 'failed'"
                class="text-center text-sm text-white/70"
            >
                This PDF could not be shown here.
            </p>
            <div
                v-for="p in pages"
                :key="`${p.n}-${zoom}`"
                :data-page="p.n"
                class="relative mx-auto mb-4 bg-white shadow"
                :style="{
                    width: `${p.w * baseScale * zoom}px`,
                    height: `${p.h * baseScale * zoom}px`,
                }"
            >
                <canvas class="absolute inset-0 h-full w-full" />
                <div class="textLayer" />
                <!-- highlights (behind the text so selection still works) -->
                <template
                    v-for="h in highlights.filter((x) => x.page === p.n)"
                    :key="h.id"
                >
                    <div
                        v-for="(r, i) in h.rects"
                        :key="i"
                        class="pointer-events-none absolute z-[2] mix-blend-multiply"
                        :class="
                            selectedId === h.id ? 'ring-2 ring-sky-500' : ''
                        "
                        :style="{
                            left: `${r[0] * 100}%`,
                            top: `${r[1] * 100}%`,
                            width: `${r[2] * 100}%`,
                            height: `${r[3] * 100}%`,
                            background: h.color,
                            opacity: h.resolved ? 0.18 : 0.38,
                        }"
                        aria-hidden="true"
                    />
                </template>
                <div
                    v-for="(r, i) in draft && draft.page === p.n
                        ? draft.rects
                        : []"
                    :key="`d${i}`"
                    class="pointer-events-none absolute z-[2] bg-yellow-300/50"
                    :style="{
                        left: `${r[0] * 100}%`,
                        top: `${r[1] * 100}%`,
                        width: `${r[2] * 100}%`,
                        height: `${r[3] * 100}%`,
                    }"
                />
                <span
                    class="absolute -top-4 right-0 text-[10px] text-white/50"
                    >{{ p.n }}</span
                >
            </div>
        </div>
    </div>
</template>

<style>
/* E-30: minimal PDF.js text layer (transparent, selectable text over the canvas). */
.oe-pdf .textLayer {
    position: absolute;
    inset: 0;
    overflow: clip;
    line-height: 1;
    text-size-adjust: none;
    forced-color-adjust: none;
    transform-origin: 0 0;
    z-index: 3;
}
.oe-pdf .textLayer :is(span, br) {
    color: transparent;
    position: absolute;
    white-space: pre;
    cursor: text;
    transform-origin: 0% 0%;
}
.oe-pdf .textLayer ::selection {
    background: rgb(0 100 255 / 0.3);
}
.oe-pdf .textLayer br::selection {
    background: transparent;
}
</style>
