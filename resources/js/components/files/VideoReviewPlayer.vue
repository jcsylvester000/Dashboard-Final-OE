<script setup lang="ts">
import {
    Captions,
    ChevronsLeft,
    ChevronsRight,
    Pause,
    Play,
    Repeat,
    Volume2,
    VolumeX,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { clock, frameMid, frameOf, timecode } from '@/lib/timecode';
import type { VideoCaption } from '@/types/files';

/**
 * E-14: a player made for review. Frame step (, and .), J/K/L, speed 0.25x-2x, loop an
 * in-out range (I / O), timecode HH:MM:SS:FF, safe-area guides for 1:1, 4:5, 9:16 and
 * 16:9, captions (E-19). E-15: comments show as markers (range comments as bars) on the
 * timeline; clicking one jumps to its frame. The default slot sits exactly over the
 * picture, for pins and drawings (E-16).
 */
export type Marker = {
    id: number;
    number: number;
    time_ms: number;
    end_ms?: number | null;
    color: string;
    resolved: boolean;
};

const props = withDefaults(
    defineProps<{
        src: string;
        poster?: string | null;
        fps?: number | null;
        captions?: VideoCaption[];
        markers?: Marker[];
        selectedId?: number | null;
    }>(),
    {
        poster: null,
        fps: 25,
        captions: () => [],
        markers: () => [],
        selectedId: null,
    },
);
const emit = defineEmits<{
    time: [ms: number];
    selectMarker: [id: number];
    range: [range: { in: number; out: number } | null];
}>();

const video = ref<HTMLVideoElement | null>(null);
const rate = computed(() => (props.fps && props.fps > 0 ? props.fps : 25));
const currentMs = ref(0);
const durationMs = ref(0);
const paused = ref(true);
const speed = ref(1);
const muted = ref(false);
const loop = ref(false);
const range = ref<{ in: number | null; out: number | null }>({
    in: null,
    out: null,
});
const guide = ref<'' | '1:1' | '4:5' | '9:16' | '16:9'>('');
const ccOn = ref(false);
const frameSize = ref({ w: 16, h: 9 });
const SPEEDS = [0.25, 0.5, 1, 1.5, 2];

let raf = 0;
function tick(): void {
    const v = video.value;
    if (v) {
        currentMs.value = v.currentTime * 1000;
        emit('time', currentMs.value);
        if (
            loop.value &&
            range.value.in !== null &&
            range.value.out !== null &&
            v.currentTime * 1000 >= range.value.out
        ) {
            v.currentTime = range.value.in / 1000;
        }
    }
    if (!paused.value) {
        raf = requestAnimationFrame(tick);
    }
}
function onPlay(): void {
    paused.value = false;
    cancelAnimationFrame(raf);
    raf = requestAnimationFrame(tick);
}
function onPause(): void {
    paused.value = true;
    cancelAnimationFrame(raf);
    tick();
}
function onMeta(): void {
    const v = video.value;
    if (v) {
        durationMs.value = (v.duration || 0) * 1000;
        frameSize.value = { w: v.videoWidth || 16, h: v.videoHeight || 9 };
    }
}

function toggle(): void {
    const v = video.value;
    if (!v) {
        return;
    }
    if (v.paused) {
        void v.play();
    } else {
        v.pause();
    }
}
function pause(): void {
    video.value?.pause();
}
/** Jump to a time (ms), landing in the middle of that frame. */
function seek(ms: number): void {
    const v = video.value;
    if (!v) {
        return;
    }
    v.currentTime = frameMid(frameOf(ms / 1000, rate.value), rate.value);
    currentMs.value = v.currentTime * 1000;
    emit('time', currentMs.value);
}
function step(frames: number): void {
    const v = video.value;
    if (!v) {
        return;
    }
    v.pause();
    const target = frameOf(v.currentTime, rate.value) + frames;
    v.currentTime = Math.min(
        Math.max(0, frameMid(target, rate.value)),
        v.duration || Infinity,
    );
    currentMs.value = v.currentTime * 1000;
    emit('time', currentMs.value);
}
function setSpeed(s: number): void {
    speed.value = s;
    if (video.value) {
        video.value.playbackRate = s;
    }
}
/** J: back 1 s (browsers can't play backwards smoothly). K: play/pause. L: play, then faster. */
function jkl(key: 'j' | 'k' | 'l'): void {
    const v = video.value;
    if (!v) {
        return;
    }
    if (key === 'k') {
        toggle();
    } else if (key === 'j') {
        v.pause();
        seek(Math.max(0, v.currentTime * 1000 - 1000));
    } else if (v.paused) {
        setSpeed(1);
        void v.play();
    } else {
        setSpeed(
            speed.value >= 2 ? 1 : speed.value < 1 ? 1 : speed.value + 0.5,
        );
    }
}
function markIn(): void {
    range.value.in = Math.round(currentMs.value);
    if (range.value.out !== null && range.value.out <= range.value.in) {
        range.value.out = null;
    }
    emitRange();
}
function markOut(): void {
    const t = Math.round(currentMs.value);
    if (range.value.in === null || t <= range.value.in) {
        return;
    }
    range.value.out = t;
    emitRange();
}
function clearRange(): void {
    range.value = { in: null, out: null };
    loop.value = false;
    emitRange();
}
function emitRange(): void {
    emit(
        'range',
        range.value.in !== null && range.value.out !== null
            ? { in: range.value.in, out: range.value.out }
            : null,
    );
}
function toggleCaptions(): void {
    ccOn.value = !ccOn.value;
    const tracks = video.value?.textTracks;
    if (tracks) {
        for (let i = 0; i < tracks.length; i++) {
            tracks[i].mode = ccOn.value && i === 0 ? 'showing' : 'hidden';
        }
    }
}
function onTimeline(e: MouseEvent): void {
    const el = e.currentTarget as HTMLElement;
    const r = el.getBoundingClientRect();
    seek(((e.clientX - r.left) / r.width) * durationMs.value);
}
const pct = (ms: number) =>
    durationMs.value
        ? `${Math.min(100, (ms / durationMs.value) * 100)}%`
        : '0%';

/** Guide rectangle for the chosen ratio, centred in the frame (fractions of the frame). */
const guideBox = computed(() => {
    if (!guide.value) {
        return null;
    }
    const [a, b] = guide.value.split(':').map(Number);
    const target = a / b;
    const frame = frameSize.value.w / frameSize.value.h;
    return target < frame
        ? { w: target / frame, h: 1 }
        : { w: 1, h: frame / target };
});

function onKey(e: KeyboardEvent): void {
    const tag = (e.target as HTMLElement | null)?.tagName;
    if (
        tag === 'TEXTAREA' ||
        tag === 'INPUT' ||
        tag === 'SELECT' ||
        e.ctrlKey ||
        e.metaKey ||
        e.altKey
    ) {
        return;
    }
    const k = e.key.toLowerCase();
    if (k === ' ' || k === 'k') {
        e.preventDefault();
        jkl('k');
    } else if (k === 'j' || k === 'l') {
        jkl(k);
    } else if (k === ',') {
        step(-1);
    } else if (k === '.') {
        step(1);
    } else if (k === 'i') {
        markIn();
    } else if (k === 'o') {
        markOut();
    }
}
onMounted(() => window.addEventListener('keydown', onKey));
onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKey);
    cancelAnimationFrame(raf);
});
watch(
    () => props.src,
    () => {
        currentMs.value = 0;
        clearRange();
    },
);

defineExpose({ seek, pause, step, currentMs, paused, range });
</script>

<template>
    <div class="flex h-full w-full flex-col items-center justify-center gap-2">
        <div class="relative inline-block max-w-full">
            <video
                ref="video"
                :key="src"
                :src="src"
                :poster="poster ?? undefined"
                playsinline
                preload="metadata"
                :muted="muted"
                class="block max-h-[52vh] max-w-full md:max-h-[80vh]"
                @loadedmetadata="onMeta"
                @play="onPlay"
                @pause="onPause"
                @seeked="tick"
                @click="toggle"
            >
                <track
                    v-for="c in captions"
                    :key="c.id"
                    kind="subtitles"
                    :src="c.url"
                    :label="c.label"
                    :srclang="c.language ?? undefined"
                />
            </video>
            <div
                v-if="guideBox"
                class="pointer-events-none absolute border-2 border-dashed border-cyan-300/90"
                :style="{
                    left: `${((1 - guideBox.w) / 2) * 100}%`,
                    top: `${((1 - guideBox.h) / 2) * 100}%`,
                    width: `${guideBox.w * 100}%`,
                    height: `${guideBox.h * 100}%`,
                }"
                aria-hidden="true"
            >
                <span
                    class="absolute top-1 left-1 rounded bg-black/60 px-1 text-[10px]"
                    >{{ guide }}</span
                >
            </div>
            <slot />
        </div>

        <div class="w-full max-w-4xl space-y-1 px-2 text-xs">
            <div
                class="relative h-6 cursor-pointer rounded bg-white/10"
                role="slider"
                tabindex="0"
                aria-label="Timeline"
                :aria-valuemin="0"
                :aria-valuemax="Math.round(durationMs / 1000)"
                :aria-valuenow="Math.round(currentMs / 1000)"
                :aria-valuetext="timecode(currentMs, rate)"
                @click="onTimeline"
            >
                <div
                    v-if="range.in !== null"
                    class="absolute inset-y-0 bg-cyan-400/25"
                    :style="{
                        left: pct(range.in),
                        width:
                            range.out !== null
                                ? `calc(${pct(range.out)} - ${pct(range.in)})`
                                : '2px',
                    }"
                />
                <div
                    class="absolute inset-y-0 left-0 bg-white/25"
                    :style="{ width: pct(currentMs) }"
                />
                <template v-for="m in markers" :key="m.id">
                    <div
                        v-if="m.end_ms"
                        class="absolute bottom-0 h-1.5 rounded"
                        :style="{
                            left: pct(m.time_ms),
                            width: `calc(${pct(m.end_ms)} - ${pct(m.time_ms)})`,
                            background: m.color,
                            opacity: m.resolved ? 0.3 : 0.9,
                        }"
                    />
                    <button
                        type="button"
                        class="absolute top-0 flex size-4 -translate-x-1/2 items-center justify-center rounded-full text-[9px] font-bold text-black"
                        :class="selectedId === m.id ? 'ring-2 ring-white' : ''"
                        :style="{
                            left: pct(m.time_ms),
                            background: m.color,
                            opacity: m.resolved ? 0.35 : 1,
                        }"
                        :aria-label="`Comment ${m.number} at ${clock(m.time_ms)}`"
                        @click.stop="
                            seek(m.time_ms);
                            pause();
                            emit('selectMarker', m.id);
                        "
                    >
                        {{ m.number }}
                    </button>
                </template>
            </div>
            <div class="flex flex-wrap items-center gap-1">
                <button
                    type="button"
                    class="rounded p-1 hover:bg-white/10"
                    :aria-label="paused ? 'Play (K)' : 'Pause (K)'"
                    @click="toggle"
                >
                    <Play v-if="paused" class="size-4" />
                    <Pause v-else class="size-4" />
                </button>
                <button
                    type="button"
                    class="rounded p-1 hover:bg-white/10"
                    aria-label="Previous frame (,)"
                    @click="step(-1)"
                >
                    <ChevronsLeft class="size-4" />
                </button>
                <button
                    type="button"
                    class="rounded p-1 hover:bg-white/10"
                    aria-label="Next frame (.)"
                    @click="step(1)"
                >
                    <ChevronsRight class="size-4" />
                </button>
                <span
                    class="rounded bg-black/40 px-1.5 py-0.5 font-mono tabular-nums"
                    aria-live="off"
                    :title="`${rate} fps`"
                    >{{ timecode(currentMs, rate) }}</span
                >
                <span class="text-white/50"
                    >/ {{ timecode(durationMs, rate) }}</span
                >
                <select
                    :value="speed"
                    class="h-7 rounded border border-white/20 bg-neutral-900 px-1"
                    aria-label="Speed"
                    @change="
                        setSpeed(
                            Number(($event.target as HTMLSelectElement).value),
                        )
                    "
                >
                    <option v-for="s in SPEEDS" :key="s" :value="s">
                        {{ s }}x
                    </option>
                </select>
                <button
                    type="button"
                    class="rounded px-1.5 py-1 hover:bg-white/10"
                    title="Set loop start (I)"
                    @click="markIn"
                >
                    In
                </button>
                <button
                    type="button"
                    class="rounded px-1.5 py-1 hover:bg-white/10"
                    title="Set loop end (O)"
                    @click="markOut"
                >
                    Out
                </button>
                <button
                    type="button"
                    class="rounded p-1 hover:bg-white/10 disabled:opacity-40"
                    :class="loop ? 'bg-white text-black' : ''"
                    :aria-pressed="loop"
                    aria-label="Loop the in-out range"
                    :disabled="range.in === null || range.out === null"
                    @click="loop = !loop"
                >
                    <Repeat class="size-4" />
                </button>
                <button
                    v-if="range.in !== null"
                    type="button"
                    class="rounded px-1.5 py-1 text-white/70 hover:bg-white/10"
                    @click="clearRange"
                >
                    Clear range
                </button>
                <span class="flex-1" />
                <select
                    v-model="guide"
                    class="h-7 rounded border border-white/20 bg-neutral-900 px-1"
                    aria-label="Safe-area guide"
                >
                    <option value="">No guide</option>
                    <option value="9:16">9:16</option>
                    <option value="4:5">4:5</option>
                    <option value="1:1">1:1</option>
                    <option value="16:9">16:9</option>
                </select>
                <button
                    v-if="captions.length"
                    type="button"
                    class="rounded p-1 hover:bg-white/10"
                    :class="ccOn ? 'bg-white text-black' : ''"
                    :aria-pressed="ccOn"
                    aria-label="Captions"
                    @click="toggleCaptions"
                >
                    <Captions class="size-4" />
                </button>
                <button
                    type="button"
                    class="rounded p-1 hover:bg-white/10"
                    :aria-label="muted ? 'Unmute' : 'Mute'"
                    @click="muted = !muted"
                >
                    <VolumeX v-if="muted" class="size-4" />
                    <Volume2 v-else class="size-4" />
                </button>
            </div>
            <p v-if="range.in !== null" class="text-white/60">
                Range {{ timecode(range.in, rate) }}
                <template v-if="range.out !== null"
                    >– {{ timecode(range.out, rate) }} · new comments use this
                    range</template
                ><template v-else> · press O to set the end</template>
            </p>
        </div>
    </div>
</template>
