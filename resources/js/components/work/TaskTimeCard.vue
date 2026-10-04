<script setup lang="ts">
import { Form, router } from '@inertiajs/vue3';
import { Lock, Play, Square, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import TimeController from '@/actions/App/Http/Controllers/Work/TimeController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { formatDate, formatMinutes } from '@/lib/format';
import type { TaskTime } from '@/types/time';

/**
 * Time on a task: start/stop timer, log time manually, see who logged what.
 */
const props = defineProps<{
    workspaceSlug: string;
    taskId: number;
    time: TaskTime;
    canLog: boolean;
}>();

const logging = ref(false);
const today = new Date().toISOString().slice(0, 10);

function start(): void {
    router.post(TimeController.start.url({ workspace: props.workspaceSlug, task: props.taskId }), {}, { preserveScroll: true });
}

function stop(): void {
    router.post(TimeController.stop.url(), {}, { preserveScroll: true });
}

function remove(id: number): void {
    if (window.confirm('Delete this time entry?')) {
        router.delete(TimeController.destroy.url(id), { preserveScroll: true });
    }
}
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle class="text-base">Time</CardTitle>
            <CardDescription>
                {{ formatMinutes(time.total) }} logged
                <template v-if="time.estimate"> of {{ formatMinutes(time.estimate) }} estimated</template>
                · {{ formatMinutes(time.billable) }} billable · you {{ formatMinutes(time.mine) }}
            </CardDescription>
        </CardHeader>
        <CardContent class="space-y-3 text-sm">
            <div v-if="canLog" class="flex flex-wrap gap-2">
                <Button v-if="time.running_here" size="sm" variant="destructive" @click="stop"><Square /> Stop timer</Button>
                <Button v-else size="sm" @click="start"><Play /> Start timer</Button>
                <Button size="sm" variant="outline" @click="logging = !logging">Log time</Button>
            </div>

            <Form
                v-if="logging"
                v-bind="TimeController.log.form({ workspace: workspaceSlug, task: taskId })"
                v-slot="{ errors, processing }"
                class="grid grid-cols-3 gap-2"
                preserve-scroll
                reset-on-success
                @success="logging = false"
            >
                <Input name="entry_date" type="date" :default-value="today" :max="today" class="col-span-3" aria-label="Date" />
                <Input name="hours" type="number" min="0" max="12" placeholder="h" aria-label="Hours" />
                <Input name="minutes" type="number" min="0" max="59" placeholder="min" aria-label="Minutes" />
                <label class="flex items-center gap-1 text-xs">
                    <input type="hidden" name="is_billable" value="0" />
                    <input type="checkbox" name="is_billable" value="1" checked class="size-4" /> Billable
                </label>
                <Input name="note" placeholder="What did you do? (optional)" class="col-span-3" aria-label="Note" />
                <InputError class="col-span-3" :message="errors.minutes ?? errors.entry_date" />
                <Button type="submit" size="sm" class="col-span-3" :disabled="processing">Save</Button>
            </Form>

            <ul v-if="time.entries.length" class="divide-y">
                <li v-for="e in time.entries" :key="e.id" class="flex items-start justify-between gap-2 py-1.5">
                    <div class="min-w-0">
                        <div>
                            <span class="font-medium">{{ e.running ? 'running…' : formatMinutes(e.minutes) }}</span>
                            <span class="text-xs text-muted-foreground"> · {{ e.user }} · {{ formatDate(e.entry_date) }}</span>
                            <span v-if="!e.is_billable" class="text-xs text-muted-foreground"> · non-billable</span>
                        </div>
                        <div v-if="e.note" class="truncate text-xs text-muted-foreground">{{ e.note }}</div>
                    </div>
                    <Lock v-if="e.locked" class="mt-1 size-3.5 shrink-0 text-muted-foreground" aria-label="Approved" />
                    <button
                        v-else-if="e.is_mine && !e.running"
                        type="button"
                        class="rounded p-1 hover:bg-accent"
                        aria-label="Delete entry"
                        @click="remove(e.id)"
                    >
                        <Trash2 class="size-3.5" />
                    </button>
                </li>
            </ul>
            <p v-else class="text-muted-foreground">No time logged yet.</p>
        </CardContent>
    </Card>
</template>
