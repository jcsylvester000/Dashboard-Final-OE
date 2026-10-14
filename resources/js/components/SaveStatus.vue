<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * E-57: every save shows its state. Any non-GET visit (create, update, delete) shows
 * "Saving…", then "Saved" or "Couldn't save"; announced to screen readers.
 */
type State = 'idle' | 'saving' | 'saved' | 'failed';

const state = ref<State>('idle');
let saving = false;
let hideTimer: ReturnType<typeof setTimeout> | undefined;
const off: (() => void)[] = [];

function show(next: State, hideAfter = 0): void {
    state.value = next;
    if (hideTimer) {
        clearTimeout(hideTimer);
    }
    if (hideAfter > 0) {
        hideTimer = setTimeout(() => (state.value = 'idle'), hideAfter);
    }
}

onMounted(() => {
    off.push(
        router.on('start', (event) => {
            saving = event.detail.visit.method !== 'get';
            if (saving) {
                show('saving');
            }
        }),
        router.on('success', () => {
            if (saving) {
                show('saved', 2000);
            }
        }),
        router.on('error', () => {
            if (saving) {
                show('failed', 5000);
            }
        }),
        router.on('httpException', () => {
            if (saving) {
                show('failed', 5000);
            }
        }),
        router.on('networkError', () => {
            if (saving) {
                show('failed', 5000);
            }
        }),
        router.on('finish', () => {
            if (saving && state.value === 'saving') {
                show('idle');
            }
            saving = false;
        }),
    );
});

onBeforeUnmount(() => {
    off.forEach((stop) => stop());
    if (hideTimer) {
        clearTimeout(hideTimer);
    }
});

const label: Record<State, string> = {
    idle: '',
    saving: 'Saving…',
    saved: 'Saved',
    failed: "Couldn't save. Check the message on the page and try again.",
};
</script>

<template>
    <div
        role="status"
        aria-live="polite"
        class="pointer-events-none fixed right-4 bottom-4 z-50 text-xs"
    >
        <span
            v-if="state !== 'idle'"
            class="rounded-full border px-3 py-1.5 shadow-sm"
            :class="{
                'bg-background text-muted-foreground': state === 'saving',
                'border-emerald-300 bg-emerald-50 text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-100':
                    state === 'saved',
                'border-rose-300 bg-rose-50 text-rose-900 dark:border-rose-800 dark:bg-rose-950 dark:text-rose-100':
                    state === 'failed',
            }"
            >{{ label[state] }}</span
        >
    </div>
</template>
