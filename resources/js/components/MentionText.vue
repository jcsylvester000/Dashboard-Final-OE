<script setup lang="ts">
import { computed } from 'vue';

/**
 * Renders text containing @[Name](user:12) tokens as plain text + mention chips.
 * No v-html: everything is text-escaped by Vue.
 */
const props = defineProps<{ text: string | null }>();

type Part = { kind: 'text'; value: string } | { kind: 'mention'; name: string; id: number };

const TOKEN = /@\[([^\]\r\n]{1,80})\]\(user:(\d{1,10})\)/g;

const parts = computed<Part[]>(() => {
    const text = props.text ?? '';
    const out: Part[] = [];
    let last = 0;

    for (const match of text.matchAll(TOKEN)) {
        const at = match.index ?? 0;
        if (at > last) {
            out.push({ kind: 'text', value: text.slice(last, at) });
        }
        out.push({ kind: 'mention', name: match[1], id: Number(match[2]) });
        last = at + match[0].length;
    }

    if (last < text.length) {
        out.push({ kind: 'text', value: text.slice(last) });
    }

    return out;
});
</script>

<template>
    <p class="text-sm leading-relaxed whitespace-pre-wrap">
        <template v-for="(part, i) in parts" :key="i">
            <span
                v-if="part.kind === 'mention'"
                class="rounded bg-blue-100 px-1 font-medium text-blue-800 dark:bg-blue-950 dark:text-blue-200"
                >@{{ part.name }}</span
            >
            <template v-else>{{ part.value }}</template>
        </template>
    </p>
</template>
