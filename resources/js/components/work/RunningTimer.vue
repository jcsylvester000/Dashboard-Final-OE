<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Square, Timer } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import TimeController from '@/actions/App/Http/Controllers/Work/TimeController';
import { show } from '@/routes/workspaces/tasks';

/**
 * Header chip showing the running timer (if any) with a live clock and Stop.
 */
const page = usePage();
const timer = computed(() => page.props.runningTimer);

const now = ref(Date.now());
let tick: ReturnType<typeof setInterval> | undefined;
onMounted(() => (tick = setInterval(() => (now.value = Date.now()), 1000)));
onBeforeUnmount(() => tick && clearInterval(tick));

const elapsed = computed(() => {
    if (!timer.value) {
        return '';
    }
    const seconds = Math.max(0, Math.floor((now.value - new Date(timer.value.started_at).getTime()) / 1000));
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = seconds % 60;

    return `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
});

function stop(): void {
    router.post(TimeController.stop.url(), {}, { preserveScroll: true });
}
</script>

<template>
    <div
        v-if="timer"
        class="flex items-center gap-2 rounded-full border border-emerald-300 bg-emerald-50 py-1 pr-1 pl-3 text-xs dark:border-emerald-900 dark:bg-emerald-950/40"
        role="status"
    >
        <Timer class="size-3.5 text-emerald-700" />
        <span class="font-mono tabular-nums">{{ elapsed }}</span>
        <Link
            v-if="timer.task"
            :href="show({ workspace: timer.workspace_slug, task: timer.task.id })"
            class="max-w-48 truncate hover:underline"
            >{{ timer.task.title }}</Link
        >
        <button
            type="button"
            class="inline-flex items-center gap-1 rounded-full bg-emerald-700 px-2 py-0.5 text-white hover:bg-emerald-800"
            aria-label="Stop timer"
            @click="stop"
        >
            <Square class="size-3" /> Stop
        </button>
    </div>
</template>
