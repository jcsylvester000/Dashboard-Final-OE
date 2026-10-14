<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { CheckCircle2, Circle } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { hide } from '@/routes/getting-started';
import type { GettingStartedStep } from '@/types/admin';

/**
 * E-59: a few first steps for this person's job; they tick themselves off.
 */
defineProps<{ steps: GettingStartedStep[]; done: number; total: number }>();

function hideCard(): void {
    router.post(hide.url(), {}, { preserveScroll: true });
}
</script>

<template>
    <Card>
        <CardHeader class="flex flex-row items-start justify-between gap-2">
            <div>
                <CardTitle class="text-base">Getting started</CardTitle>
                <CardDescription
                    >{{ done }} of {{ total }} done. Each step ticks itself off
                    when you've done it.</CardDescription
                >
            </div>
            <Button variant="ghost" size="sm" @click="hideCard">Hide</Button>
        </CardHeader>
        <CardContent>
            <ul class="space-y-2 text-sm">
                <li
                    v-for="s in steps"
                    :key="s.key"
                    class="flex items-start gap-2"
                >
                    <CheckCircle2
                        v-if="s.done"
                        class="mt-0.5 size-4 shrink-0 text-emerald-600"
                        aria-hidden="true"
                    />
                    <Circle
                        v-else
                        class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <span>
                        <Link
                            :href="s.href"
                            class="font-medium hover:underline"
                            :class="
                                s.done
                                    ? 'text-muted-foreground line-through'
                                    : ''
                            "
                            >{{ s.label }}</Link
                        ><span class="sr-only">{{
                            s.done ? ' (done)' : ' (to do)'
                        }}</span>
                        <span class="block text-xs text-muted-foreground">{{
                            s.hint
                        }}</span>
                    </span>
                </li>
            </ul>
        </CardContent>
    </Card>
</template>
