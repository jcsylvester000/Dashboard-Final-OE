<script setup lang="ts">
import {
    ArrowUpRight,
    Circle,
    Hand,
    MapPin,
    Pencil,
    Redo2,
    Square,
    Type,
    Undo2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import type { Tool } from '@/components/files/AnnotationCanvas.vue';
import { timeAgo } from '@/lib/format';
import { timecode } from '@/lib/timecode';
import type { AnnotationPin } from '@/types/files';
import { ANNOTATION_COLORS } from '@/types/files';

/**
 * E-04 / E-05 / E-60: tools, the comment draft and the list of pin threads. Everything
 * on the image can also be done here with the keyboard: add a pin at the centre and nudge
 * it with the arrow buttons, open a pin from the list, reply, resolve.
 */
const props = defineProps<{
    pins: AnnotationPin[];
    canWrite: boolean;
    selectedId: number | null;
    drafting: boolean;
    draftCount: number;
    canUndo: boolean;
    canRedo: boolean;
    tool: Tool;
    color: string;
    busy: boolean;
    /** E-15: set on videos; comments then show and sort by timecode. */
    fps?: number | null;
    /** E-15: "at 00:00:35:12" for the comment being written */
    draftLabel?: string;
}>();
const emit = defineEmits<{
    'update:tool': [tool: Tool];
    'update:color': [color: string];
    select: [id: number | null];
    undo: [];
    redo: [];
    save: [body: string];
    cancel: [];
    reply: [id: number, body: string];
    resolve: [id: number, resolved: boolean];
    remove: [id: number];
    placeCenter: [];
    nudge: [dx: number, dy: number];
}>();

const tools: { key: Tool; label: string; icon: unknown }[] = [
    { key: 'move', label: 'Move and zoom', icon: Hand },
    { key: 'pin', label: 'Drop a pin', icon: MapPin },
    { key: 'pen', label: 'Pen', icon: Pencil },
    { key: 'arrow', label: 'Arrow', icon: ArrowUpRight },
    { key: 'rect', label: 'Rectangle', icon: Square },
    { key: 'ellipse', label: 'Ellipse', icon: Circle },
    { key: 'text', label: 'Text', icon: Type },
];
const colorNames: Record<string, string> = {
    '#E69F00': 'Orange',
    '#56B4E9': 'Sky blue',
    '#009E73': 'Green',
    '#F0E442': 'Yellow',
    '#0072B2': 'Blue',
    '#D55E00': 'Vermilion',
    '#CC79A7': 'Pink',
    '#000000': 'Black',
    '#FFFFFF': 'White',
};

const body = ref('');
const replyTo = ref<number | null>(null);
const replyBody = ref('');
const showResolved = ref(false);
const visible = computed(() => {
    const list = props.pins.filter((p) => showResolved.value || !p.resolved);

    return props.fps
        ? [...list].sort(
              (a, b) =>
                  (a.time_ms ?? Number.MAX_SAFE_INTEGER) -
                  (b.time_ms ?? Number.MAX_SAFE_INTEGER),
          )
        : list;
});
const carried = computed(
    () =>
        props.pins.filter((p) => p.carried_from_version && !p.resolved).length,
);
const resolvedCount = computed(
    () => props.pins.filter((p) => p.resolved).length,
);

function save(): void {
    if (body.value.trim()) {
        emit('save', body.value.trim());
        body.value = '';
    }
}
function sendReply(id: number): void {
    if (replyBody.value.trim()) {
        emit('reply', id, replyBody.value.trim());
        replyBody.value = '';
        replyTo.value = null;
    }
}
defineExpose({ showResolved });
</script>

<template>
    <div class="space-y-3">
        <div v-if="canWrite" class="space-y-2">
            <div
                class="flex flex-wrap gap-1"
                role="toolbar"
                aria-label="Annotation tools"
            >
                <button
                    v-for="t in tools"
                    :key="t.key"
                    type="button"
                    class="flex size-9 items-center justify-center rounded border border-white/20 focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none"
                    :class="
                        tool === t.key
                            ? 'bg-white text-black'
                            : 'hover:bg-white/10'
                    "
                    :aria-label="t.label"
                    :aria-pressed="tool === t.key"
                    :title="t.label"
                    @click="emit('update:tool', t.key)"
                >
                    <component :is="t.icon" class="size-4" />
                </button>
                <button
                    type="button"
                    class="flex size-9 items-center justify-center rounded border border-white/20 hover:bg-white/10 disabled:opacity-40"
                    aria-label="Undo"
                    title="Undo (Ctrl+Z)"
                    :disabled="!canUndo"
                    @click="emit('undo')"
                >
                    <Undo2 class="size-4" />
                </button>
                <button
                    type="button"
                    class="flex size-9 items-center justify-center rounded border border-white/20 hover:bg-white/10 disabled:opacity-40"
                    aria-label="Redo"
                    title="Redo (Ctrl+Y)"
                    :disabled="!canRedo"
                    @click="emit('redo')"
                >
                    <Redo2 class="size-4" />
                </button>
            </div>
            <div
                class="flex flex-wrap gap-1"
                role="radiogroup"
                aria-label="Colour"
            >
                <button
                    v-for="c in ANNOTATION_COLORS"
                    :key="c"
                    type="button"
                    role="radio"
                    class="size-6 rounded-full border-2 focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none"
                    :class="color === c ? 'border-white' : 'border-white/20'"
                    :style="{ background: c }"
                    :aria-checked="color === c"
                    :aria-label="colorNames[c] ?? c"
                    @click="emit('update:color', c)"
                />
            </div>

            <div
                v-if="drafting"
                class="space-y-2 rounded border border-white/20 p-2"
            >
                <p class="text-xs text-white/70">
                    New comment<template v-if="draftLabel">
                        {{ draftLabel }}</template
                    ><template v-if="draftCount">
                        · {{ draftCount }} drawing{{
                            draftCount === 1 ? '' : 's'
                        }}</template
                    >. Drag on the image to draw, or move the pin:
                </p>
                <div class="flex gap-1" aria-label="Move the pin">
                    <Button
                        size="sm"
                        variant="secondary"
                        aria-label="Move pin left"
                        @click="emit('nudge', -0.02, 0)"
                        >←</Button
                    >
                    <Button
                        size="sm"
                        variant="secondary"
                        aria-label="Move pin up"
                        @click="emit('nudge', 0, -0.02)"
                        >↑</Button
                    >
                    <Button
                        size="sm"
                        variant="secondary"
                        aria-label="Move pin down"
                        @click="emit('nudge', 0, 0.02)"
                        >↓</Button
                    >
                    <Button
                        size="sm"
                        variant="secondary"
                        aria-label="Move pin right"
                        @click="emit('nudge', 0.02, 0)"
                        >→</Button
                    >
                </div>
                <textarea
                    v-model="body"
                    rows="3"
                    maxlength="2000"
                    class="w-full rounded border border-white/20 bg-neutral-900 p-2 text-sm"
                    placeholder="What should change here? @mention someone"
                    aria-label="Comment for this pin"
                    @keydown.ctrl.enter="save"
                    @keydown.meta.enter="save"
                />
                <div class="flex gap-2">
                    <Button
                        size="sm"
                        :disabled="busy || !body.trim()"
                        @click="save"
                        >Save pin</Button
                    >
                    <Button
                        size="sm"
                        variant="ghost"
                        class="text-white hover:bg-white/10"
                        @click="emit('cancel')"
                        >Cancel</Button
                    >
                </div>
            </div>
            <Button
                v-else
                size="sm"
                variant="secondary"
                class="w-full"
                @click="emit('placeCenter')"
                >Add a pin (keyboard)</Button
            >
        </div>

        <p
            v-if="carried"
            class="rounded bg-sky-900/50 px-2 py-1 text-xs text-sky-100"
        >
            {{ carried }} open comment{{ carried === 1 ? '' : 's' }} carried
            over from the previous cut. Resolve each one once it's fixed.
        </p>
        <div class="flex items-center justify-between text-xs text-white/60">
            <span
                >{{ visible.length }} pin{{
                    visible.length === 1 ? '' : 's'
                }}</span
            >
            <label v-if="resolvedCount" class="flex items-center gap-1">
                <input
                    v-model="showResolved"
                    type="checkbox"
                    class="size-3.5"
                />
                Show {{ resolvedCount }} resolved
            </label>
        </div>

        <ul class="space-y-2" aria-label="Pins">
            <li
                v-for="p in visible"
                :key="p.id"
                class="rounded border p-2"
                :class="
                    selectedId === p.id ? 'border-white' : 'border-white/15'
                "
            >
                <button
                    type="button"
                    class="flex w-full items-start gap-2 text-left focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none"
                    :aria-expanded="selectedId === p.id"
                    @click="emit('select', selectedId === p.id ? null : p.id)"
                >
                    <span
                        class="flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-bold text-black"
                        :style="{ background: p.color }"
                        >{{ p.number }}</span
                    >
                    <span class="min-w-0 flex-1">
                        <span
                            v-if="
                                fps &&
                                p.time_ms !== null &&
                                p.time_ms !== undefined
                            "
                            class="mr-1 rounded bg-white/10 px-1 font-mono text-[10px]"
                            >{{ timecode(p.time_ms, fps)
                            }}<template v-if="p.end_ms">
                                – {{ timecode(p.end_ms, fps) }}</template
                            ></span
                        ><span
                            v-if="p.carried_from_version"
                            class="mr-1 rounded border border-sky-300/60 px-1 text-[10px] text-sky-200"
                            >Carried over · from v{{
                                p.carried_from_version
                            }}</span
                        >
                        <span
                            class="block text-sm break-words whitespace-pre-line"
                            :class="
                                p.resolved ? 'text-white/50 line-through' : ''
                            "
                            >{{ p.body }}</span
                        >
                        <span class="text-[11px] text-white/50"
                            >{{ p.author
                            }}<span
                                v-if="p.is_client"
                                class="ml-1 rounded border border-sky-300 px-1 text-[10px] text-sky-200 uppercase"
                                >Client</span
                            >
                            · {{ timeAgo(p.at)
                            }}<template v-if="p.x === null">
                                · whole file</template
                            ><template v-if="p.replies.length">
                                · {{ p.replies.length }} repl{{
                                    p.replies.length === 1 ? 'y' : 'ies'
                                }}</template
                            ></span
                        >
                    </span>
                </button>
                <div v-if="selectedId === p.id" class="mt-2 space-y-2 pl-8">
                    <div v-for="r in p.replies" :key="r.id" class="text-xs">
                        <p class="break-words whitespace-pre-line">
                            {{ r.body }}
                        </p>
                        <p class="text-white/50">
                            {{ r.author
                            }}<span
                                v-if="r.is_client"
                                class="ml-1 rounded border border-sky-300 px-1 text-[10px] text-sky-200 uppercase"
                                >Client</span
                            >
                            · {{ timeAgo(r.at) }}
                        </p>
                    </div>
                    <template v-if="canWrite">
                        <form
                            v-if="replyTo === p.id"
                            class="space-y-1"
                            @submit.prevent="sendReply(p.id)"
                        >
                            <textarea
                                v-model="replyBody"
                                rows="2"
                                maxlength="2000"
                                class="w-full rounded border border-white/20 bg-neutral-900 p-1.5 text-xs"
                                :aria-label="`Reply to pin ${p.number}`"
                            />
                            <Button
                                size="sm"
                                type="submit"
                                :disabled="busy || !replyBody.trim()"
                                >Reply</Button
                            >
                        </form>
                        <div class="flex flex-wrap gap-1">
                            <Button
                                v-if="replyTo !== p.id"
                                size="sm"
                                variant="ghost"
                                class="h-7 text-white hover:bg-white/10"
                                @click="replyTo = p.id"
                                >Reply</Button
                            >
                            <Button
                                size="sm"
                                variant="ghost"
                                class="h-7 text-white hover:bg-white/10"
                                @click="emit('resolve', p.id, !p.resolved)"
                                >{{ p.resolved ? 'Reopen' : 'Resolve' }}</Button
                            >
                            <Button
                                size="sm"
                                variant="ghost"
                                class="h-7 text-rose-300 hover:bg-white/10"
                                @click="emit('remove', p.id)"
                                >Delete</Button
                            >
                        </div>
                    </template>
                </div>
            </li>
        </ul>
        <p v-if="!pins.length && !drafting" class="text-xs text-white/50">
            No pins yet.<template v-if="canWrite">
                Choose the pin tool and click the spot, or draw first.</template
            >
        </p>
    </div>
</template>
