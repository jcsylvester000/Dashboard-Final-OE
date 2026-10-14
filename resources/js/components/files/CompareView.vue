<script setup lang="ts">
import { ref } from 'vue';

/**
 * E-03: two versions of an image side by side with zoom and pan kept in step, or one
 * over the other with a slider. Images of different sizes are letterboxed in equal
 * frames so the same spot lines up.
 */
defineProps<{
    left: { url: string; label: string };
    right: { url: string; label: string };
    mode: 'side' | 'slider';
}>();

const scale = ref(1);
const offset = ref({ x: 0, y: 0 });
const split = ref(50);
let drag: { x: number; y: number } | null = null;

function zoom(by: number): void {
    scale.value = Math.min(8, Math.max(0.5, +(scale.value * by).toFixed(2)));
}
function onWheel(e: WheelEvent): void {
    e.preventDefault();
    zoom(e.deltaY < 0 ? 1.15 : 1 / 1.15);
}
function onDown(e: PointerEvent): void {
    drag = { x: e.clientX - offset.value.x, y: e.clientY - offset.value.y };
    (e.currentTarget as HTMLElement).setPointerCapture?.(e.pointerId);
}
function onMove(e: PointerEvent): void {
    if (drag) {
        offset.value = { x: e.clientX - drag.x, y: e.clientY - drag.y };
    }
}
function reset(): void {
    scale.value = 1;
    offset.value = { x: 0, y: 0 };
}
const style = () => ({
    transform: `translate(${offset.value.x}px, ${offset.value.y}px) scale(${scale.value})`,
});
</script>

<template>
    <div class="flex h-full w-full flex-col">
        <div
            v-if="mode === 'side'"
            class="grid min-h-0 flex-1 grid-cols-1 gap-1 sm:grid-cols-2"
            @wheel="onWheel"
        >
            <figure
                v-for="(img, i) in [left, right]"
                :key="i"
                class="relative min-h-0 overflow-hidden bg-neutral-900"
                @pointerdown="onDown"
                @pointermove="onMove"
                @pointerup="drag = null"
                @dblclick="reset"
            >
                <img
                    :src="img.url"
                    :alt="img.label"
                    class="absolute inset-0 h-full w-full object-contain select-none"
                    draggable="false"
                    :style="style()"
                />
                <figcaption
                    class="absolute top-2 left-2 rounded bg-black/70 px-2 py-0.5 text-xs"
                >
                    {{ img.label }}
                </figcaption>
            </figure>
        </div>
        <div
            v-else
            class="relative min-h-0 flex-1 overflow-hidden bg-neutral-900"
            @wheel="onWheel"
            @pointerdown="onDown"
            @pointermove="onMove"
            @pointerup="drag = null"
            @dblclick="reset"
        >
            <img
                :src="left.url"
                :alt="left.label"
                class="absolute inset-0 h-full w-full object-contain select-none"
                draggable="false"
                :style="style()"
            />
            <img
                :src="right.url"
                :alt="right.label"
                class="absolute inset-0 h-full w-full object-contain select-none"
                draggable="false"
                :style="{ ...style(), clipPath: `inset(0 0 0 ${split}%)` }"
            />
            <div
                class="pointer-events-none absolute inset-y-0 w-0.5 bg-white"
                :style="{ left: `${split}%` }"
                aria-hidden="true"
            />
            <span
                class="absolute top-2 left-2 rounded bg-black/70 px-2 py-0.5 text-xs"
                >{{ left.label }}</span
            >
            <span
                class="absolute top-2 right-2 rounded bg-black/70 px-2 py-0.5 text-xs"
                >{{ right.label }}</span
            >
        </div>
        <div class="flex items-center gap-3 px-3 py-2 text-xs">
            <label
                v-if="mode === 'slider'"
                class="flex flex-1 items-center gap-2"
                >Slide
                <input
                    v-model.number="split"
                    type="range"
                    min="0"
                    max="100"
                    class="flex-1"
                    :aria-label="`Show ${left.label} on the left and ${right.label} on the right`"
            /></label>
            <span class="text-white/60"
                >Zoom {{ Math.round(scale * 100) }}% · scroll to zoom, drag to
                move, double-click to reset (both stay in step)</span
            >
        </div>
    </div>
</template>
