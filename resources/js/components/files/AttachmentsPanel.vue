<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import {
    BadgeCheck,
    Download,
    FileText,
    FolderOpen,
    Paperclip,
    Trash2,
} from '@lucide/vue';
import {
    computed,
    defineAsyncComponent,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { confirmDialog } from '@/composables/useConfirm';
import { timeAgo } from '@/lib/format';
import {
    approve,
    clientLink,
    clientUnlink,
    destroy,
    fromDrive,
    store,
} from '@/routes/attachments';
import { uploadVideo } from '@/composables/useVideoUpload';
import type { VideoUploadState } from '@/composables/useVideoUpload';
import { picker } from '@/routes/drive';
import type { AttachmentRow, FileLimits } from '@/types/files';

// E-123: the viewer (zoom, compare, annotation) loads on first open, not with the page.
const MediaViewer = defineAsyncComponent(
    () => import('@/components/files/MediaViewer.vue'),
);
// E-23: logs / JSON / CSV open in the text viewer (also loaded on first open).
const TextViewer = defineAsyncComponent(
    () => import('@/components/files/TextViewer.vue'),
);
const reading = ref<AttachmentRow | null>(null);

/**
 * Files on a task / project. Links are signed and expire after a few minutes;
 * reload the page if one stops working. Reviewers can mark the approved file(s);
 * approved files are listed first and travel with handoffs.
 */
const props = defineProps<{
    attachableType: 'task' | 'project' | 'comment' | 'workspace';
    attachableId: number;
    attachments: AttachmentRow[];
    limits: FileLimits;
    canUpload: boolean;
    title?: string;
    description?: string;
    /** E-22: on bug tasks a pasted screenshot opens straight in annotate mode. */
    annotateOnPaste?: boolean;
    /** E-18: delivery specs the task's videos are checked against. */
    deliverySpecs?: {
        id: number;
        name: string;
        channel: string | null;
        deliverable: string | null;
    }[];
    deliverySpecId?: number | null;
    deliverySpecUrl?: string;
}>();

const page = usePage();
const input = ref<HTMLInputElement | null>(null);
const form = useForm<{
    attachable_type: string;
    attachable_id: number;
    file: File | null;
}>({
    attachable_type: props.attachableType,
    attachable_id: props.attachableId,
    file: null,
});
// E-11: videos upload straight to storage on tasks and projects.
const videoTarget = computed(
    () => props.attachableType === 'task' || props.attachableType === 'project',
);
const videoExts = computed(() =>
    videoTarget.value ? (props.limits.video?.extensions ?? []) : [],
);
const accept = computed(() =>
    [...new Set([...props.limits.extensions, ...videoExts.value])]
        .map((e) => `.${e}`)
        .join(','),
);
function isVideo(file: File): boolean {
    const ext = file.name.split('.').pop()?.toLowerCase() ?? '';

    return videoExts.value.includes(ext);
}
const videoUploads = ref<VideoUploadState[]>([]);
async function sendVideo(file: File, versionOf?: number): Promise<boolean> {
    const maxMb = props.limits.video?.max_mb ?? 0;
    if (maxMb && file.size > maxMb * 1024 * 1024) {
        videoUploads.value.push({
            name: file.name,
            loaded: 0,
            total: file.size,
            status: 'error',
            error: `Videos can be at most ${Math.round(maxMb / 1024)} GB.`,
            pause: () => {},
            resume: () => {},
            cancel: () => {},
        });

        return false;
    }
    const { state, done } = uploadVideo(file, {
        attachableType: props.attachableType as 'task' | 'project',
        attachableId: props.attachableId,
        versionOf,
    });
    videoUploads.value.push(state);
    const ok = await done;
    if (ok) {
        router.reload({ only: ['attachments'] });
        setTimeout(
            () =>
                (videoUploads.value = videoUploads.value.filter(
                    (v) => v !== state,
                )),
            2500,
        );
    }

    return ok;
}
// E-18: pick the spec this task's videos must meet (blank = match by channel).
const hasVideo = computed(() => props.attachments.some((a) => a.video));
function chooseSpec(e: Event): void {
    if (!props.deliverySpecUrl) {
        return;
    }
    const v = (e.target as HTMLSelectElement).value;
    router.put(
        props.deliverySpecUrl,
        { delivery_spec_id: v === '' ? null : Number(v) },
        { preserveScroll: true, only: ['task', 'attachments'] },
    );
}
function percent(v: VideoUploadState): number {
    return v.total ? Math.floor((v.loaded / v.total) * 100) : 0;
}
// Warn before leaving the page while a video is still uploading.
function beforeUnload(e: BeforeUnloadEvent): void {
    if (
        videoUploads.value.some((v) =>
            [
                'starting',
                'uploading',
                'paused',
                'waiting-network',
                'finishing',
            ].includes(v.status),
        )
    ) {
        e.preventDefault();
    }
}
// E-13: while a video is being prepared, refresh the list every 15 s (visible tab only).
let processingTimer: ReturnType<typeof setInterval> | null = null;
onMounted(() => {
    window.addEventListener('beforeunload', beforeUnload);
    processingTimer = setInterval(() => {
        if (
            document.visibilityState === 'visible' &&
            props.attachments.some((a) => a.video?.status === 'processing')
        ) {
            router.reload({ only: ['attachments'] });
        }
    }, 15000);
});
onBeforeUnmount(() => {
    window.removeEventListener('beforeunload', beforeUnload);
    if (processingTimer) {
        clearInterval(processingTimer);
    }
});
const fileError = computed(
    () =>
        form.errors.file ??
        (page.props.errors as Record<string, string> | undefined)?.file,
);
const queue = ref<
    { name: string; status: 'waiting' | 'uploading' | 'done' | 'error' }[]
>([]);
const dragging = ref(false);
const canPickDrive = computed(
    () =>
        props.canUpload &&
        (props.attachableType === 'task' || props.attachableType === 'project'),
);

function uploadOne(file: File): Promise<boolean> {
    return new Promise((resolve) => {
        form.file = file;
        form.post(store.url(), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => resolve(true),
            onError: () => resolve(false),
            onFinish: () => form.reset('file'),
        });
    });
}

/** Several files at once (picked or dropped), uploaded one after another. */
async function uploadMany(
    files: FileList | File[] | null | undefined,
): Promise<void> {
    const list = Array.from(files ?? []);
    if (!list.length || !props.canUpload) {
        return;
    }
    const videos = list.filter((f) => isVideo(f));
    for (const v of videos) {
        void sendVideo(v);
    }
    list.splice(0, list.length, ...list.filter((f) => !isVideo(f)));
    if (!list.length) {
        return;
    }
    queue.value = list.map((f) => ({ name: f.name, status: 'waiting' }));
    for (const [i, file] of list.entries()) {
        queue.value[i].status = 'uploading';
        queue.value[i].status = (await uploadOne(file)) ? 'done' : 'error';
    }
    if (input.value) {
        input.value.value = '';
    }
    if (queue.value.every((q) => q.status === 'done')) {
        setTimeout(() => (queue.value = []), 2500);
    }
}

function pick(event: Event): void {
    void uploadMany((event.target as HTMLInputElement).files);
}

function onDrop(e: DragEvent): void {
    dragging.value = false;
    void uploadMany(e.dataTransfer?.files);
}

// "Attach from My files"
const picking = ref(false);
const driveFiles = ref<
    { id: number; name: string; size: number; is_image: boolean }[]
>([]);
const driveSearch = ref('');

async function loadDrive(): Promise<void> {
    const res = await fetch(
        `${picker.url()}?q=${encodeURIComponent(driveSearch.value)}`,
        { headers: { Accept: 'application/json' }, credentials: 'same-origin' },
    );
    driveFiles.value = res.ok ? await res.json() : [];
}

function openPicker(): void {
    picking.value = !picking.value;
    if (picking.value) {
        void loadDrive();
    }
}

function attachFromDrive(id: number): void {
    router.post(
        fromDrive.url(),
        {
            attachable_type: props.attachableType,
            attachable_id: props.attachableId,
            drive_file_id: id,
        },
        { preserveScroll: true, onSuccess: () => (picking.value = false) },
    );
}

// E-01: open previewable files in the in-app viewer.
const viewing = ref<number | null>(null);
const viewerMode = ref<'view' | 'annotate'>('view');
const viewable = computed(() => props.attachments.filter((a) => a.previewable));
function open(a: AttachmentRow, e: MouseEvent): void {
    if (a.text_url && !(e.ctrlKey || e.metaKey || e.shiftKey)) {
        e.preventDefault();
        reading.value = a;

        return;
    }
    if (!a.previewable || e.ctrlKey || e.metaKey || e.shiftKey) {
        return; // let the link open normally
    }
    e.preventDefault();
    viewerMode.value = 'view';
    viewing.value = viewable.value.findIndex((v) => v.id === a.id);
}
// E-04 / E-22: one click to annotate an image.
function annotate(a: AttachmentRow): void {
    viewerMode.value = 'annotate';
    viewing.value = viewable.value.findIndex((v) => v.id === a.id);
}
const pendingAnnotate = ref<number | null>(null);
function onPasted(e: Event): void {
    const detail = (
        e as CustomEvent<{ id: number; type: string; attachableId: number }>
    ).detail;
    if (
        props.annotateOnPaste &&
        detail.type === props.attachableType &&
        detail.attachableId === props.attachableId
    ) {
        pendingAnnotate.value = detail.id;
        router.reload({ only: ['attachments'] });
    }
}
watch(
    () => props.attachments,
    () => {
        const a = props.attachments.find((x) => x.id === pendingAnnotate.value);
        if (a) {
            pendingAnnotate.value = null;
            annotate(a);
        }
    },
);
onMounted(() => window.addEventListener('oe:pasted-file', onPasted));
onBeforeUnmount(() => window.removeEventListener('oe:pasted-file', onPasted));

// E-02: upload a new version of a file.
const versionInput = ref<HTMLInputElement | null>(null);
const versionOf = ref<number | null>(null);
function pickVersion(a: AttachmentRow): void {
    versionOf.value = a.id;
    versionInput.value?.click();
}
function uploadVersion(e: Event): void {
    const file = (e.target as HTMLInputElement).files?.[0];
    if (!file || versionOf.value === null) {
        return;
    }
    if (isVideo(file)) {
        void sendVideo(file, versionOf.value);
        versionOf.value = null;
        if (versionInput.value) {
            versionInput.value.value = '';
        }

        return;
    }
    router.post(
        store.url(),
        {
            attachable_type: props.attachableType,
            attachable_id: props.attachableId,
            version_of: versionOf.value,
            file,
        },
        {
            forceFormData: true,
            preserveScroll: true,
            onFinish: () => {
                versionOf.value = null;
                if (versionInput.value) {
                    versionInput.value.value = '';
                }
            },
        },
    );
}

// E-08: client review links (14 days by default).
const copiedId = ref<number | null>(null);
function shareClientLink(a: AttachmentRow): void {
    router.post(
        clientLink.url(a.id),
        { expires_days: 14 },
        { preserveScroll: true },
    );
}
async function revokeClientLink(a: AttachmentRow): Promise<void> {
    if (
        await confirmDialog(
            `Turn off the client link for ${a.name}? The client will see "not found".`,
        )
    ) {
        router.delete(clientUnlink.url(a.id), { preserveScroll: true });
    }
}
async function copyClientLink(a: AttachmentRow): Promise<void> {
    if (!a.client_link) {
        return;
    }
    await navigator.clipboard.writeText(a.client_link.url);
    copiedId.value = a.id;
    setTimeout(() => (copiedId.value = null), 2000);
}

function toggleApproval(a: AttachmentRow): void {
    router.post(
        approve.url(a.id),
        { approved: !a.approved },
        { preserveScroll: true },
    );
}

async function remove(id: number, name: string): Promise<void> {
    if (await confirmDialog(`Delete ${name}?`)) {
        router.delete(destroy.url(id), { preserveScroll: true });
    }
}

function duration(ms: number): string {
    const s = Math.round(ms / 1000);
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    const sec = String(s % 60).padStart(2, '0');

    return h ? `${h}:${String(m).padStart(2, '0')}:${sec}` : `${m}:${sec}`;
}
function size(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }
    if (bytes < 1024 * 1024) {
        return `${Math.round(bytes / 1024)} KB`;
    }
    if (bytes >= 1024 ** 3) {
        return `${(bytes / 1024 ** 3).toFixed(2)} GB`;
    }

    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle class="flex items-center gap-2 text-base"
                ><Paperclip class="size-4" /> {{ title ?? 'Files' }}</CardTitle
            >
            <CardDescription>{{
                description ??
                `Up to ${limits.max_mb} MB · ${limits.extensions.join(', ')}`
            }}</CardDescription>
        </CardHeader>
        <CardContent class="space-y-3">
            <div
                v-if="canUpload"
                class="space-y-2 rounded-lg border-2 border-dashed p-3 transition"
                :class="
                    dragging
                        ? 'border-primary bg-primary/5'
                        : 'border-transparent'
                "
                @dragover.prevent="dragging = true"
                @dragleave.prevent="dragging = false"
                @drop.prevent="onDrop"
            >
                <input
                    ref="input"
                    type="file"
                    multiple
                    class="hidden"
                    :accept="accept"
                    @change="pick"
                />
                <div class="flex flex-wrap gap-2">
                    <Button
                        size="sm"
                        variant="outline"
                        :disabled="form.processing"
                        @click="input?.click()"
                    >
                        {{
                            form.processing
                                ? `Uploading ${form.progress?.percentage ?? 0}%`
                                : 'Attach files'
                        }}
                    </Button>
                    <Button
                        v-if="canPickDrive"
                        size="sm"
                        variant="ghost"
                        @click="openPicker"
                        ><FolderOpen class="size-4" /> From My files</Button
                    >
                </div>
                <p class="text-xs text-muted-foreground">
                    or drop several files here
                </p>
                <ul v-if="queue.length" class="space-y-0.5 text-xs">
                    <li
                        v-for="(q, i) in queue"
                        :key="i"
                        class="flex justify-between gap-2"
                    >
                        <span class="truncate">{{ q.name }}</span>
                        <span
                            :class="
                                q.status === 'error'
                                    ? 'text-rose-600'
                                    : q.status === 'done'
                                      ? 'text-emerald-700'
                                      : 'text-muted-foreground'
                            "
                            >{{ q.status }}</span
                        >
                    </li>
                </ul>
                <label
                    v-if="
                        hasVideo &&
                        deliverySpecs?.length &&
                        deliverySpecUrl &&
                        canUpload
                    "
                    class="flex flex-wrap items-center gap-2 text-xs"
                >
                    <span class="text-muted-foreground"
                        >Check videos against</span
                    >
                    <select
                        :value="deliverySpecId ?? ''"
                        class="h-7 rounded-md border bg-background px-2"
                        aria-label="Delivery spec for this task's videos"
                        @change="chooseSpec"
                    >
                        <option value="">Matching specs (by channel)</option>
                        <option
                            v-for="d in deliverySpecs"
                            :key="d.id"
                            :value="d.id"
                        >
                            {{ d.name }}
                        </option>
                    </select>
                </label>
                <ul v-if="videoUploads.length" class="space-y-2 text-xs">
                    <li
                        v-for="(v, i) in videoUploads"
                        :key="`v${i}`"
                        class="space-y-1 rounded border p-2"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <span class="truncate font-medium">{{
                                v.name
                            }}</span>
                            <span
                                class="shrink-0"
                                :class="
                                    v.status === 'error'
                                        ? 'text-rose-600'
                                        : v.status === 'done'
                                          ? 'text-emerald-700'
                                          : 'text-muted-foreground'
                                "
                                aria-live="polite"
                                >{{
                                    v.status === 'uploading'
                                        ? `${percent(v)}% · ${size(v.loaded)} of ${size(v.total)}`
                                        : v.status === 'waiting-network'
                                          ? 'Offline: resumes when you reconnect'
                                          : v.status === 'finishing'
                                            ? 'Finishing…'
                                            : v.status === 'done'
                                              ? 'Uploaded, preparing preview'
                                              : v.status
                                }}</span
                            >
                        </div>
                        <div
                            class="h-1.5 overflow-hidden rounded bg-muted"
                            role="progressbar"
                            :aria-valuenow="percent(v)"
                            aria-valuemin="0"
                            aria-valuemax="100"
                            :aria-label="`Uploading ${v.name}`"
                        >
                            <div
                                class="h-full bg-primary transition-all"
                                :style="{ width: `${percent(v)}%` }"
                            />
                        </div>
                        <p v-if="v.error" class="text-rose-600">
                            {{ v.error }}
                        </p>
                        <div
                            v-if="
                                [
                                    'uploading',
                                    'paused',
                                    'waiting-network',
                                ].includes(v.status)
                            "
                            class="flex gap-2"
                        >
                            <button
                                v-if="v.status === 'paused'"
                                type="button"
                                class="underline"
                                @click="v.resume()"
                            >
                                Resume
                            </button>
                            <button
                                v-else
                                type="button"
                                class="underline"
                                @click="v.pause()"
                            >
                                Pause
                            </button>
                            <button
                                type="button"
                                class="text-rose-600 underline"
                                @click="v.cancel()"
                            >
                                Cancel
                            </button>
                        </div>
                    </li>
                </ul>
                <InputError :message="fileError" />

                <div
                    v-if="picking"
                    class="space-y-2 rounded-md border bg-background p-2"
                >
                    <input
                        v-model="driveSearch"
                        type="search"
                        placeholder="Search my files"
                        class="h-8 w-full rounded-md border px-2 text-xs"
                        aria-label="Search my files"
                        @input="loadDrive"
                    />
                    <p
                        v-if="driveFiles.length === 0"
                        class="text-xs text-muted-foreground"
                    >
                        No files in My files yet.
                    </p>
                    <ul v-else class="max-h-48 divide-y overflow-auto text-xs">
                        <li
                            v-for="d in driveFiles"
                            :key="d.id"
                            class="flex items-center justify-between gap-2 py-1.5"
                        >
                            <span class="truncate" :title="d.name">{{
                                d.name
                            }}</span>
                            <Button
                                size="sm"
                                variant="outline"
                                class="h-7"
                                @click="attachFromDrive(d.id)"
                                >Attach</Button
                            >
                        </li>
                    </ul>
                </div>
            </div>

            <p
                v-if="attachments.length === 0"
                class="text-sm text-muted-foreground"
            >
                No files yet.
            </p>
            <ul v-else class="divide-y text-sm">
                <li
                    v-for="a in attachments"
                    :key="a.id"
                    class="flex items-center gap-3 py-2"
                >
                    <a
                        :href="a.url"
                        target="_blank"
                        rel="noopener"
                        class="shrink-0"
                        @click="open(a, $event)"
                    >
                        <img
                            v-if="a.thumb_url || a.mime.startsWith('image/')"
                            :src="a.thumb_url ?? a.url"
                            :alt="a.name"
                            class="size-10 rounded object-cover"
                            loading="lazy"
                            decoding="async"
                        />
                        <span
                            v-else
                            class="flex size-10 items-center justify-center rounded bg-muted"
                            ><FileText class="size-5 text-muted-foreground"
                        /></span>
                    </a>
                    <div class="min-w-0 flex-1">
                        <a
                            :href="a.url"
                            target="_blank"
                            rel="noopener"
                            class="block truncate font-medium hover:underline"
                            :title="a.name"
                            @click="open(a, $event)"
                            >{{ a.name }}</a
                        >
                        <button
                            v-if="a.mime.startsWith('image/')"
                            type="button"
                            class="mr-1 rounded bg-amber-100 px-1 text-[10px] font-medium text-amber-900 hover:underline dark:bg-amber-950 dark:text-amber-200"
                            :aria-label="`Annotate ${a.name}${a.open_pins ? `, ${a.open_pins} open pins` : ''}`"
                            @click="annotate(a)"
                        >
                            {{
                                a.open_pins
                                    ? `${a.open_pins} pin${a.open_pins === 1 ? '' : 's'}`
                                    : 'Annotate'
                            }}
                        </button>
                        <span
                            v-if="(a.version ?? 1) > 1 || a.versions?.length"
                            class="mr-1 rounded bg-muted px-1 text-[10px] font-medium"
                            :title="`${(a.versions?.length ?? 0) + 1} versions`"
                            >v{{ a.version ?? 1 }}</span
                        >
                        <span
                            v-if="a.review_status === 'changes'"
                            class="rounded bg-amber-100 px-1 text-[10px] font-medium text-amber-900 dark:bg-amber-950 dark:text-amber-200"
                            :title="a.review_note ?? ''"
                            >Changes requested</span
                        >
                        <span
                            v-for="c in (a.video?.spec_checks ?? []).filter(
                                (x) => x.pass === false,
                            )"
                            :key="`spec${c.spec_id}`"
                            class="mr-1 rounded bg-rose-100 px-1 text-[10px] font-medium text-rose-900 dark:bg-rose-950 dark:text-rose-200"
                            :title="`${c.spec}: ${c.issues.join('; ')}`"
                            >{{ c.issues[0]
                            }}<template v-if="c.issues.length > 1">
                                +{{ c.issues.length - 1 }}</template
                            ></span
                        >
                        <span
                            v-if="
                                (a.video?.spec_checks ?? []).length &&
                                (a.video?.spec_checks ?? []).every(
                                    (x) => x.pass === true,
                                )
                            "
                            class="mr-1 rounded bg-emerald-100 px-1 text-[10px] font-medium text-emerald-900 dark:bg-emerald-950 dark:text-emerald-200"
                            >Meets spec</span
                        >
                        <span
                            v-if="a.video?.status === 'processing'"
                            class="mr-1 rounded bg-sky-100 px-1 text-[10px] font-medium text-sky-900 dark:bg-sky-950 dark:text-sky-200"
                            >Preparing preview…</span
                        >
                        <span
                            v-else-if="a.video?.status === 'failed'"
                            class="mr-1 rounded bg-rose-100 px-1 text-[10px] font-medium text-rose-900 dark:bg-rose-950 dark:text-rose-200"
                            :title="a.video.error ?? ''"
                            >Preview failed (original downloadable)</span
                        >
                        <span
                            v-else-if="a.video?.duration_ms"
                            class="mr-1 rounded bg-muted px-1 text-[10px] font-medium"
                            :title="a.video.fps ? `${a.video.fps} fps` : ''"
                            >{{ duration(a.video.duration_ms) }} ·
                            {{ a.video.width }}×{{ a.video.height }}</span
                        >
                        <p class="text-xs text-muted-foreground">
                            {{ size(a.size) }} ·
                            {{ a.uploader ?? 'Former member' }} ·
                            {{ timeAgo(a.created_at)
                            }}<template v-if="a.from_drive">
                                · from My files</template
                            >
                        </p>
                        <p
                            v-if="a.task"
                            class="truncate text-xs text-muted-foreground"
                        >
                            On: {{ a.task.title }}
                        </p>
                        <p
                            v-if="a.approved"
                            class="inline-flex items-center gap-1 text-xs font-medium text-emerald-700"
                        >
                            <BadgeCheck class="size-3.5" /> Approved{{
                                a.approved_by
                                    ? ` by ${a.approved_by}`
                                    : a.client_decision_by
                                      ? ` by ${a.client_decision_by} (client)`
                                      : ''
                            }}{{
                                a.approved_at
                                    ? ` · ${timeAgo(a.approved_at)}`
                                    : ''
                            }}
                        </p>
                    </div>
                    <Button
                        v-if="a.can_approve"
                        :variant="a.approved ? 'ghost' : 'outline'"
                        size="sm"
                        @click="toggleApproval(a)"
                    >
                        {{ a.approved ? 'Unapprove' : 'Approve' }}
                    </Button>
                    <!-- E-08: client review link for this version -->
                    <template v-if="a.can_approve && !a.task">
                        <Button
                            v-if="a.client_link"
                            variant="ghost"
                            size="sm"
                            class="h-8 px-2 text-xs"
                            :title="`Client link · ${a.client_link.views} view(s)${a.client_link.expires_at ? ` · ends ${timeAgo(a.client_link.expires_at)}` : ''}`"
                            @click="copyClientLink(a)"
                            >{{
                                copiedId === a.id
                                    ? 'Copied'
                                    : 'Copy client link'
                            }}</Button
                        >
                        <Button
                            v-if="a.client_link"
                            variant="ghost"
                            size="sm"
                            class="h-8 px-2 text-xs"
                            @click="revokeClientLink(a)"
                            >Turn off</Button
                        >
                        <Button
                            v-else
                            variant="ghost"
                            size="sm"
                            class="h-8 px-2 text-xs"
                            :title="`Let the client comment on and approve ${a.name} without logging in`"
                            @click="shareClientLink(a)"
                            >Client review link</Button
                        >
                    </template>
                    <!-- E-09: feedback sheet -->
                    <Button
                        v-if="a.feedback_pdf_url"
                        variant="ghost"
                        size="sm"
                        class="h-8 px-2 text-xs"
                        as-child
                        ><a
                            :href="a.feedback_pdf_url"
                            :aria-label="`Feedback sheet PDF for ${a.name}`"
                            >Feedback PDF</a
                        ></Button
                    >
                    <Button
                        v-if="canUpload && !a.task"
                        variant="ghost"
                        size="sm"
                        class="h-8 px-2 text-xs"
                        :title="`Upload a new version of ${a.name}`"
                        @click="pickVersion(a)"
                        >New version</Button
                    >
                    <Button variant="ghost" size="icon" as-child>
                        <a
                            :href="a.download_url"
                            :aria-label="`Download ${a.name}`"
                            ><Download class="size-4"
                        /></a>
                    </Button>
                    <Button
                        v-if="a.can_delete"
                        variant="ghost"
                        size="icon"
                        :aria-label="`Delete ${a.name}`"
                        @click="remove(a.id, a.name)"
                    >
                        <Trash2 class="size-4" />
                    </Button>
                </li>
            </ul>
            <input
                ref="versionInput"
                type="file"
                class="hidden"
                :accept="accept"
                aria-label="New version"
                @change="uploadVersion"
            />
        </CardContent>
    </Card>
    <Teleport to="body">
        <MediaViewer
            v-if="viewing !== null && viewing >= 0 && viewable.length"
            :items="viewable"
            :start="viewing"
            :initial-mode="viewerMode"
            @close="viewing = null"
        />
        <TextViewer
            v-if="reading && reading.text_url"
            :name="reading.name"
            :url="reading.text_url"
            :download-url="reading.download_url"
            @close="reading = null"
        />
    </Teleport>
</template>
