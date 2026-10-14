<script setup lang="ts">
import { computed, nextTick, ref } from 'vue';
import { store as storeAttachment } from '@/routes/attachments';
import type { MemberOption } from '@/types/workspace';

/**
 * Plain <textarea> with @mention autocomplete. Selecting a person inserts
 * the token @[Name](user:ID); selecting a department inserts @[Name](dept:ID),
 * which tags everyone in that department on this client. Works inside an
 * Inertia <Form> via `name`.
 */
const props = withDefaults(
    defineProps<{
        name: string;
        members: MemberOption[];
        departments?: { id: number; name: string }[];
        defaultValue?: string | null;
        rows?: number;
        placeholder?: string;
        id?: string;
        /** E-10: paste or drop images to upload them to this record and insert [[file:ID]]. */
        pasteTarget?: { type: 'task' | 'project'; id: number } | null;
    }>(),
    {
        defaultValue: '',
        rows: 5,
        placeholder: 'Type @ to tag a team member or department',
        departments: () => [],
    },
);

const value = ref(props.defaultValue ?? '');
const uploading = ref(0);
const uploadError = ref<string | null>(null);

function xsrf(): string {
    const m = document.cookie.match(/XSRF-TOKEN=([^;]+)/);

    return m ? decodeURIComponent(m[1]) : '';
}

async function uploadImages(files: File[]): Promise<void> {
    if (!props.pasteTarget) {
        return;
    }
    uploadError.value = null;
    for (const file of files) {
        uploading.value++;
        const body = new FormData();
        body.append('attachable_type', props.pasteTarget.type);
        body.append('attachable_id', String(props.pasteTarget.id));
        const ext = file.type.split('/')[1] ?? 'png';
        body.append(
            'file',
            file,
            file.name && file.name !== 'image.png'
                ? file.name
                : `screenshot-${Date.now()}.${ext}`,
        );
        try {
            const res = await fetch(storeAttachment.url(), {
                method: 'POST',
                body,
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': xsrf(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            const data = await res.json().catch(() => null);
            if (!res.ok || !data?.token) {
                uploadError.value =
                    data?.errors?.file?.[0] ??
                    data?.message ??
                    'Upload failed.';
            } else {
                insertAtCaret(`\n${data.token}\n`);
            }
        } catch {
            uploadError.value = 'Upload failed (network).';
        } finally {
            uploading.value--;
        }
    }
}

function insertAtCaret(text: string): void {
    const textarea = el.value;
    const caret = textarea?.selectionStart ?? value.value.length;
    value.value = value.value.slice(0, caret) + text + value.value.slice(caret);
    void nextTick(() => {
        const pos = caret + text.length;
        textarea?.setSelectionRange(pos, pos);
    });
}

function imagesFrom(
    list: DataTransferItemList | FileList | null | undefined,
): File[] {
    const items: (File | DataTransferItem)[] = list
        ? Array.from(list as ArrayLike<File | DataTransferItem>)
        : [];

    return items
        .map((x) =>
            x instanceof File ? x : x.kind === 'file' ? x.getAsFile() : null,
        )
        .filter((f): f is File => f !== null && f.type.startsWith('image/'));
}

function onPaste(e: ClipboardEvent): void {
    const images = imagesFrom(e.clipboardData?.items);
    if (props.pasteTarget && images.length) {
        e.preventDefault();
        void uploadImages(images);
    }
}

function onDrop(e: DragEvent): void {
    const images = imagesFrom(e.dataTransfer?.files);
    if (props.pasteTarget && images.length) {
        e.preventDefault();
        void uploadImages(images);
    }
}
const el = ref<HTMLTextAreaElement | null>(null);
const query = ref<string | null>(null);
const anchor = ref(0);
const active = ref(0);

type Option = {
    key: string;
    id: number;
    name: string;
    title?: string | null;
    team: boolean;
};

const matches = computed<Option[]>(() => {
    if (query.value === null) {
        return [];
    }
    const q = query.value.toLowerCase();
    const teams: Option[] = props.departments
        .filter((d) => d.name.toLowerCase().includes(q))
        .map((d) => ({
            key: `d${d.id}`,
            id: d.id,
            name: d.name,
            title: 'Whole department',
            team: true,
        }));
    const people: Option[] = props.members
        .filter((m) => m.name.toLowerCase().includes(q))
        .map((m) => ({
            key: `u${m.id}`,
            id: m.id,
            name: m.name,
            title: m.title,
            team: false,
        }));

    return [...teams.slice(0, 3), ...people].slice(0, 7);
});

function onInput(): void {
    const textarea = el.value;
    if (!textarea) {
        return;
    }

    const caret = textarea.selectionStart;
    const before = value.value.slice(0, caret);
    const match = /(^|\s)@([\p{L}\p{N} .'-]{0,30})$/u.exec(before);

    if (match) {
        query.value = match[2];
        anchor.value = caret - match[2].length - 1;
        active.value = 0;
    } else {
        query.value = null;
    }
}

async function pick(member: Option): Promise<void> {
    const textarea = el.value;
    if (!textarea) {
        return;
    }

    const caret = textarea.selectionStart;
    const token = `@[${member.name.replace(/[\]\r\n]/g, '')}](${member.team ? 'dept' : 'user'}:${member.id}) `;
    value.value =
        value.value.slice(0, anchor.value) + token + value.value.slice(caret);
    query.value = null;

    await nextTick();
    const pos = anchor.value + token.length;
    textarea.focus();
    textarea.setSelectionRange(pos, pos);
}

function onKeydown(event: KeyboardEvent): void {
    if (query.value === null || matches.value.length === 0) {
        return;
    }

    if (event.key === 'ArrowDown') {
        event.preventDefault();
        active.value = (active.value + 1) % matches.value.length;
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        active.value =
            (active.value - 1 + matches.value.length) % matches.value.length;
    } else if (event.key === 'Enter' || event.key === 'Tab') {
        event.preventDefault();
        void pick(matches.value[active.value]);
    } else if (event.key === 'Escape') {
        query.value = null;
    }
}
</script>

<template>
    <div class="relative">
        <textarea
            :id="id"
            ref="el"
            v-model="value"
            :name="name"
            :rows="rows"
            :placeholder="placeholder"
            class="w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
            @input="onInput"
            @keydown="onKeydown"
            @blur="query = null"
            @paste="onPaste"
            @drop="onDrop"
        />
        <p
            v-if="pasteTarget && uploading"
            class="text-xs text-muted-foreground"
        >
            Uploading image…
        </p>
        <p v-else-if="uploadError" class="text-xs text-destructive">
            {{ uploadError }}
        </p>
        <ul
            v-if="query !== null && matches.length"
            class="absolute z-20 mt-1 w-64 overflow-hidden rounded-md border bg-popover text-sm shadow-md"
            role="listbox"
        >
            <li
                v-for="(m, i) in matches"
                :key="m.key"
                role="option"
                :aria-selected="i === active"
                class="cursor-pointer px-3 py-2"
                :class="i === active ? 'bg-accent' : ''"
                @mousedown.prevent="pick(m)"
            >
                <div class="font-medium">
                    {{ m.team ? `@${m.name} team` : m.name }}
                </div>
                <div v-if="m.title" class="text-xs text-muted-foreground">
                    {{ m.title }}
                </div>
            </li>
        </ul>
    </div>
</template>
