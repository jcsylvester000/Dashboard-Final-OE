<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import type { AnnotationPin, AnnotationShape } from '@/types/files';

/**
 * E-04 / E-05: the layer over an image that shows numbered pins and vector drawings,
 * and lets the reviewer place a pin or draw. Everything is stored as fractions (0..1) of
 * the image, so it lines up at any zoom and screen size. It sits inside the zoomed
 * wrapper, so it scales with the image; pins counter-scale to stay readable.
 */
export type Tool =
    | 'move'
    | 'pin'
    | 'pen'
    | 'arrow'
    | 'rect'
    | 'ellipse'
    | 'text';

const props = defineProps<{
    pins: AnnotationPin[];
    draftShapes: AnnotationShape[];
    draftPin: { x: number; y: number } | null;
    tool: Tool;
    color: string;
    selectedId: number | null;
    scale: number;
}>();
const emit = defineEmits<{
    place: [x: number, y: number];
    shape: [shape: AnnotationShape];
    select: [id: number];
}>();

const root = ref<HTMLElement | null>(null);
const size = ref({ w: 1, h: 1 });
let observer: ResizeObserver | null = null;
onMounted(() => {
    observer = new ResizeObserver(() => {
        if (root.value) {
            // Layout size (transforms don't change it): the space shapes are drawn in.
            size.value = {
                w: root.value.offsetWidth || 1,
                h: root.value.offsetHeight || 1,
            };
        }
    });
    if (root.value) {
        observer.observe(root.value);
    }
});
onBeforeUnmount(() => observer?.disconnect());

const live = ref<AnnotationShape | null>(null);

function point(e: PointerEvent): [number, number] {
    const r = root.value!.getBoundingClientRect(); // includes the zoom transform
    const clamp = (n: number) => Math.min(1, Math.max(0, n));
    return [
        +clamp((e.clientX - r.left) / r.width).toFixed(4),
        +clamp((e.clientY - r.top) / r.height).toFixed(4),
    ];
}

function down(e: PointerEvent): void {
    if (props.tool === 'move') {
        return;
    }
    e.stopPropagation();
    const p = point(e);
    if (props.tool === 'pin') {
        emit('place', p[0], p[1]);
        return;
    }
    if (props.tool === 'text') {
        const text = window.prompt('Text to place on the image')?.trim();
        if (text) {
            emit('shape', {
                type: 'text',
                color: props.color,
                points: [p],
                text: text.slice(0, 200),
            });
        }
        return;
    }
    (e.target as Element).setPointerCapture?.(e.pointerId);
    live.value = {
        type: props.tool,
        color: props.color,
        width: 3,
        points: [p, p],
    };
}
function move(e: PointerEvent): void {
    if (!live.value) {
        return;
    }
    e.stopPropagation();
    const p = point(e);
    if (live.value.type === 'pen') {
        if (live.value.points.length < 500) {
            live.value.points.push(p);
        }
    } else {
        live.value.points = [live.value.points[0], p];
    }
}
function up(): void {
    if (live.value) {
        const s = live.value;
        live.value = null;
        const [a, b] = [s.points[0], s.points[s.points.length - 1]];
        // Ignore clicks that didn't draw anything.
        if (
            s.type === 'pen'
                ? s.points.length > 2
                : Math.hypot(a[0] - b[0], a[1] - b[1]) > 0.005
        ) {
            emit('shape', s.type === 'pen' ? s : { ...s, points: [a, b] });
        }
    }
}

const px = (p: [number, number]) => [p[0] * size.value.w, p[1] * size.value.h];
function path(points: [number, number][]): string {
    return points.map((p, i) => `${i ? 'L' : 'M'}${px(p).join(' ')}`).join(' ');
}
function arrowHead(s: AnnotationShape): string {
    const [x1, y1] = px(s.points[0]);
    const [x2, y2] = px(s.points[s.points.length - 1]);
    const a = Math.atan2(y2 - y1, x2 - x1);
    const l = 14;
    const p1 = [x2 - l * Math.cos(a - 0.45), y2 - l * Math.sin(a - 0.45)];
    const p2 = [x2 - l * Math.cos(a + 0.45), y2 - l * Math.sin(a + 0.45)];
    return `M${p1.join(' ')} L${x2} ${y2} L${p2.join(' ')}`;
}
function box(s: AnnotationShape) {
    const [x1, y1] = px(s.points[0]);
    const [x2, y2] = px(s.points[s.points.length - 1]);
    return {
        x: Math.min(x1, x2),
        y: Math.min(y1, y2),
        w: Math.abs(x2 - x1),
        h: Math.abs(y2 - y1),
    };
}
</script>

<template>
    <div
        ref="root"
        class="absolute inset-0"
        :class="
            tool === 'move'
                ? 'pointer-events-none'
                : 'cursor-crosshair touch-none'
        "
        @pointerdown="down"
        @pointermove="move"
        @pointerup="up"
        @pointercancel="up"
    >
        <svg
            class="pointer-events-none absolute inset-0 h-full w-full overflow-visible"
            :viewBox="`0 0 ${size.w} ${size.h}`"
            aria-hidden="true"
        >
            <template
                v-for="(s, i) in [
                    ...pins.flatMap((p) =>
                        p.shapes.map((sh) => ({
                            ...sh,
                            dim: selectedId !== null && selectedId !== p.id,
                        })),
                    ),
                    ...draftShapes.map((sh) => ({ ...sh, dim: false })),
                    ...(live ? [{ ...live, dim: false }] : []),
                ]"
                :key="i"
            >
                <g
                    :stroke="s.color"
                    :fill="s.type === 'text' ? s.color : 'none'"
                    :stroke-width="s.width ?? 3"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    vector-effect="non-scaling-stroke"
                    :opacity="s.dim ? 0.35 : 1"
                >
                    <path
                        v-if="s.type === 'pen'"
                        :d="path(s.points)"
                        vector-effect="non-scaling-stroke"
                    />
                    <template v-else-if="s.type === 'arrow'">
                        <path
                            :d="path(s.points)"
                            vector-effect="non-scaling-stroke"
                        />
                        <path
                            :d="arrowHead(s)"
                            vector-effect="non-scaling-stroke"
                        />
                    </template>
                    <rect
                        v-else-if="s.type === 'rect'"
                        v-bind="{
                            x: box(s).x,
                            y: box(s).y,
                            width: box(s).w,
                            height: box(s).h,
                        }"
                        vector-effect="non-scaling-stroke"
                    />
                    <ellipse
                        v-else-if="s.type === 'ellipse'"
                        :cx="box(s).x + box(s).w / 2"
                        :cy="box(s).y + box(s).h / 2"
                        :rx="box(s).w / 2"
                        :ry="box(s).h / 2"
                        vector-effect="non-scaling-stroke"
                    />
                    <text
                        v-else-if="s.type === 'text'"
                        :x="px(s.points[0])[0]"
                        :y="px(s.points[0])[1]"
                        stroke="#000"
                        stroke-width="0.6"
                        font-size="18"
                        font-weight="700"
                        font-family="system-ui, sans-serif"
                    >
                        {{ s.text }}
                    </text>
                </g>
            </template>
        </svg>

        <button
            v-for="p in pins.filter((p) => p.x !== null && p.y !== null)"
            :key="p.id"
            type="button"
            class="pointer-events-auto absolute flex size-7 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border-2 border-white text-xs font-bold text-black shadow-md focus-visible:ring-4 focus-visible:ring-white focus-visible:outline-none"
            :class="[
                p.resolved ? 'opacity-50' : '',
                selectedId === p.id ? 'ring-4 ring-white/80' : '',
            ]"
            :style="{
                left: `${(p.x ?? 0) * 100}%`,
                top: `${(p.y ?? 0) * 100}%`,
                background: p.color,
                transform: `translate(-50%, -50%) scale(${1 / scale})`,
            }"
            :aria-label="`Pin ${p.number}${p.resolved ? ' (resolved)' : ''}: ${p.body}`"
            @pointerdown.stop
            @click.stop="emit('select', p.id)"
        >
            {{ p.number }}
        </button>
        <span
            v-if="draftPin"
            class="pointer-events-none absolute flex size-7 items-center justify-center rounded-full border-2 border-dashed border-white text-xs font-bold text-black shadow-md"
            :style="{
                left: `${draftPin.x * 100}%`,
                top: `${draftPin.y * 100}%`,
                background: color,
                transform: `translate(-50%, -50%) scale(${1 / scale})`,
            }"
            aria-hidden="true"
            >+</span
        >
    </div>
</template>
