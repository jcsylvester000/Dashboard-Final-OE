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
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import MediaViewer from '@/components/files/MediaViewer.vue';
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
import { approve, destroy, fromDrive, store } from '@/routes/attachments';
import { picker } from '@/routes/drive';
import type { AttachmentRow, FileLimits } from '@/types/files';

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
const accept = computed(() =>
    props.limits.extensions.map((e) => `.${e}`).join(','),
);
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

function size(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }
    if (bytes < 1024 * 1024) {
        return `${Math.round(bytes / 1024)} KB`;
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
                                a.approved_by ? ` by ${a.approved_by}` : ''
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
    </Teleport>
</template>
