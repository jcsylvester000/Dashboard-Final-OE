<script setup lang="ts">
import { nextTick, onMounted, ref, watch } from 'vue';

/**
 * E-20: shows server-rendered, sanitised Markdown (RichText::render escapes raw HTML and
 * drops unsafe links). Code blocks get a Copy button.
 */
const props = defineProps<{ html: string }>();
const root = ref<HTMLElement | null>(null);

function addCopyButtons(): void {
    root.value?.querySelectorAll('pre').forEach((pre) => {
        if (pre.querySelector('button')) {
            return;
        }
        const button = document.createElement('button');
        button.type = 'button';
        button.textContent = 'Copy';
        button.className = 'oe-copy';
        button.addEventListener('click', () => {
            void navigator.clipboard
                ?.writeText(
                    pre.querySelector('code')?.textContent ??
                        pre.textContent ??
                        '',
                )
                .then(() => {
                    button.textContent = 'Copied';
                    window.setTimeout(
                        () => (button.textContent = 'Copy'),
                        1200,
                    );
                });
        });
        pre.appendChild(button);
    });
}

onMounted(addCopyButtons);
watch(
    () => props.html,
    () => void nextTick(addCopyButtons),
);
</script>

<template>
    <!-- eslint-disable-next-line vue/no-v-html -- sanitised on the server (RichText) -->
    <div ref="root" class="oe-rich text-sm leading-relaxed" v-html="html" />
</template>

<style scoped>
.oe-rich :deep(p) {
    margin: 0.25rem 0;
}
.oe-rich :deep(h1),
.oe-rich :deep(h2),
.oe-rich :deep(h3) {
    font-weight: 600;
    margin: 0.75rem 0 0.25rem;
}
.oe-rich :deep(h1) {
    font-size: 1.125rem;
}
.oe-rich :deep(h2) {
    font-size: 1rem;
}
.oe-rich :deep(ul) {
    list-style: disc;
    padding-left: 1.25rem;
}
.oe-rich :deep(ol) {
    list-style: decimal;
    padding-left: 1.25rem;
}
.oe-rich :deep(a) {
    text-decoration: underline;
}
.oe-rich :deep(code) {
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 0.8125rem;
    background: var(--muted);
    border-radius: 0.25rem;
    padding: 0 0.25rem;
}
.oe-rich :deep(pre) {
    position: relative;
    background: var(--muted);
    border-radius: 0.5rem;
    padding: 0.75rem;
    overflow-x: auto;
    margin: 0.5rem 0;
}
.oe-rich :deep(pre code) {
    background: transparent;
    padding: 0;
    white-space: pre;
}
.oe-rich :deep(.oe-copy) {
    position: absolute;
    top: 0.375rem;
    right: 0.375rem;
    font-size: 0.6875rem;
    border: 1px solid var(--border);
    border-radius: 0.25rem;
    padding: 0 0.375rem;
    background: var(--background);
}
.oe-rich :deep(blockquote) {
    border-left: 3px solid var(--border);
    padding-left: 0.75rem;
    color: var(--muted-foreground);
}
.oe-rich :deep(table) {
    border-collapse: collapse;
    margin: 0.5rem 0;
}
.oe-rich :deep(th),
.oe-rich :deep(td) {
    border: 1px solid var(--border);
    padding: 0.25rem 0.5rem;
}
.oe-rich :deep(.oe-file-img) {
    max-height: 12rem;
    border-radius: 0.375rem;
    border: 1px solid var(--border);
    margin: 0.25rem 0;
}
</style>
