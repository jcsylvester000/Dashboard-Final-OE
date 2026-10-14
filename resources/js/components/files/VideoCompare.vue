<script setup lang="ts">
import { ChevronsLeft, ChevronsRight, Pause, Play } from '@lucide/vue';
import { computed, onBeforeUnmount, ref } from 'vue';
import { frameMid, frameOf, timecode } from '@/lib/timecode';

/**
 * E-17: two cuts side by side with play, pause, seek and frame steps kept in step.
 * The right (newer) cut leads; the left follows and is pulled back whenever it drifts
 * more than one frame. Sound comes from the right only.
 */
const props = withDefaults(
    defineProps<{
        left: { url: string; label: string };
        right: { url: string; label: string };
        fps?: number | null;
    }>(),
    { fps: 25 },
);

const a = ref<HTMLVideoElement | null>(null);
const b = ref<HTMLVideoElement | null>(null);
const rate = computed(() => (props.fps && props.fps > 0 ? props.fps : 25));
const paused = ref(true);
const ms = ref(0);
const duration = ref(0);
let raf = 0;

function sync(): void {
    if (!a.value || !b.value) {
        return;
    }
    ms.value = b.value.currentTime * 1000;
    if (Math.abs(a.value.currentTime - b.value.currentTime) > 1 / rate.value) {
        a.value.currentTime = b.value.currentTime;
    }
    if (!paused.value) {
        raf = requestAnimationFrame(sync);
    }
}
function toggle(): void {
    if (!a.value || !b.value) {
        return;
    }
    if (paused.value) {
        a.value.currentTime = b.value.currentTime;
        void Promise.all([a.value.play(), b.value.play()]);
        paused.value = false;
        raf = requestAnimationFrame(sync);
    } else {
        a.value.pause();
        b.value.pause();
        paused.value = true;
        cancelAnimationFrame(raf);
        a.value.currentTime = b.value.currentTime;
    }
}
function seekTo(seconds: number): void {
    if (!a.value || !b.value) {
        return;
    }
    const t = frameMid(frameOf(seconds, rate.value), rate.value);
    a.value.currentTime = t;
    b.value.currentTime = t;
    ms.value = t * 1000;
}
function step(frames: number): void {
    if (!b.value) {
        return;
    }
    if (!paused.value) {
        toggle();
    }
    seekTo(
        (frameOf(b.value.currentTime, rate.value) + frames + 0.5) / rate.value,
    );
}
function onTimeline(e: MouseEvent): void {
    const r = (e.currentTarget as HTMLElement).getBoundingClientRect();
    seekTo(((e.clientX - r.left) / r.width) * duration.value);
}
function onMeta(): void {
    duration.value = Math.max(a.value?.duration || 0, b.value?.duration || 0);
}
onBeforeUnmount(() => cancelAnimationFrame(raf));
</script>

<template>
    <div class="flex h-full w-full flex-col gap-2 p-2">
        <div class="grid min-h-0 flex-1 grid-cols-1 gap-1 sm:grid-cols-2">
            <figure
                class="relative flex min-h-0 items-center justify-center bg-black"
            >
                <video
                    ref="a"
                    :src="left.url"
                    playsinline
                    preload="metadata"
                    muted
                    class="max-h-full max-w-full"
                    @loadedmetadata="onMeta"
                />
                <figcaption
                    class="absolute top-2 left-2 rounded bg-black/70 px-2 py-0.5 text-xs"
                >
                    {{ left.label }}
                </figcaption>
            </figure>
            <figure
                class="relative flex min-h-0 items-center justify-center bg-black"
            >
                <video
                    ref="b"
                    :src="right.url"
                    playsinline
                    preload="metadata"
                    class="max-h-full max-w-full"
                    @loadedmetadata="onMeta"
                    @seeked="sync"
                />
                <figcaption
                    class="absolute top-2 left-2 rounded bg-black/70 px-2 py-0.5 text-xs"
                >
                    {{ right.label }}
                </figcaption>
            </figure>
        </div>
        <div class="space-y-1 text-xs">
            <div
                class="relative h-3 cursor-pointer rounded bg-white/10"
                role="slider"
                tabindex="0"
                aria-label="Timeline (both cuts)"
                :aria-valuetext="timecode(ms, rate)"
                @click="onTimeline"
            >
                <div
                    class="absolute inset-y-0 left-0 rounded bg-white/30"
                    :style="{
                        width: duration
                            ? `${(ms / 1000 / duration) * 100}%`
                            : '0%',
                    }"
                />
            </div>
            <div class="flex items-center gap-1">
                <button
                    type="button"
                    class="rounded p-1 hover:bg-white/10"
                    :aria-label="paused ? 'Play both' : 'Pause both'"
                    @click="toggle"
                >
                    <Play v-if="paused" class="size-4" />
                    <Pause v-else class="size-4" />
                </button>
                <button
                    type="button"
                    class="rounded p-1 hover:bg-white/10"
                    aria-label="Previous frame (both)"
                    @click="step(-1)"
                >
                    <ChevronsLeft class="size-4" />
                </button>
                <button
                    type="button"
                    class="rounded p-1 hover:bg-white/10"
                    aria-label="Next frame (both)"
                    @click="step(1)"
                >
                    <ChevronsRight class="size-4" />
                </button>
                <span class="font-mono tabular-nums">{{
                    timecode(ms, rate)
                }}</span>
                <span class="text-white/50">· synced within 1 frame</span>
            </div>
        </div>
    </div>
</template>
