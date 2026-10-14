<script setup lang="ts">
import { Lightbulb } from '@lucide/vue';
import { ref } from 'vue';
import { store as storeFinding } from '@/routes/findings';

/**
 * E-31: "Save as finding" for a comment, a PDF highlight or a pin. Opens a small form
 * (title, tags, confidence) and saves to the Findings library without leaving the page.
 */
const props = withDefaults(
    defineProps<{
        sourceType: 'comment' | 'annotation';
        sourceId: number;
        defaultTitle: string;
        dark?: boolean;
    }>(),
    { dark: false },
);

const open = ref(false);
const title = ref('');
const tags = ref('');
const confidence = ref<'low' | 'medium' | 'high'>('medium');
const busy = ref(false);
const message = ref('');
const saved = ref(false);

function start(): void {
    title.value = props.defaultTitle.replace(/\s+/g, ' ').trim().slice(0, 120);
    message.value = '';
    open.value = true;
}

function xsrf(): string {
    return decodeURIComponent(
        document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
    );
}

async function save(): Promise<void> {
    busy.value = true;
    message.value = '';
    try {
        const res = await fetch(storeFinding.url(), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': xsrf(),
            },
            body: JSON.stringify({
                source_type: props.sourceType,
                source_id: props.sourceId,
                title: title.value,
                tags: tags.value
                    .split(',')
                    .map((t) => t.trim())
                    .filter(Boolean),
                confidence: confidence.value,
            }),
        });
        const data = (await res.json().catch(() => ({}))) as {
            message?: string;
            errors?: Record<string, string[]>;
        };
        if (!res.ok) {
            message.value = data.errors
                ? (Object.values(data.errors)[0]?.[0] ?? 'Could not save.')
                : (data.message ?? 'Could not save.');
            return;
        }
        saved.value = true;
        open.value = false;
        message.value = 'Saved to Findings.';
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <span class="inline-block">
        <button
            v-if="!open"
            type="button"
            class="inline-flex items-center gap-1 text-xs hover:underline disabled:opacity-50"
            :class="dark ? 'text-amber-200' : 'text-muted-foreground'"
            :disabled="saved"
            @click="start"
        >
            <Lightbulb class="size-3.5" />
            {{ saved ? 'Saved as finding' : 'Save as finding' }}
        </button>
        <form
            v-else
            class="mt-1 grid gap-1 rounded border p-2 text-xs"
            :class="dark ? 'border-white/20' : ''"
            @submit.prevent="save"
        >
            <input
                v-model="title"
                required
                maxlength="200"
                aria-label="Finding title"
                class="h-7 rounded border px-2"
                :class="
                    dark ? 'border-white/20 bg-neutral-900' : 'bg-background'
                "
            />
            <input
                v-model="tags"
                placeholder="Tags, comma separated"
                aria-label="Tags"
                class="h-7 rounded border px-2"
                :class="
                    dark ? 'border-white/20 bg-neutral-900' : 'bg-background'
                "
            />
            <div class="flex items-center gap-2">
                <select
                    v-model="confidence"
                    aria-label="Confidence"
                    class="h-7 rounded border px-1"
                    :class="
                        dark
                            ? 'border-white/20 bg-neutral-900'
                            : 'bg-background'
                    "
                >
                    <option value="low">Low confidence</option>
                    <option value="medium">Medium confidence</option>
                    <option value="high">High confidence</option>
                </select>
                <button
                    type="submit"
                    class="rounded bg-primary px-2 py-1 text-primary-foreground disabled:opacity-50"
                    :disabled="busy || !title.trim()"
                >
                    Save
                </button>
                <button type="button" class="underline" @click="open = false">
                    Cancel
                </button>
            </div>
        </form>
        <span
            v-if="message"
            class="ml-1 text-xs"
            role="status"
            :class="saved ? 'text-emerald-500' : 'text-rose-500'"
            >{{ message }}</span
        >
    </span>
</template>
