<script setup lang="ts">
import { computed } from 'vue';
import { formatDate } from '@/lib/format';

/**
 * Sprint burndown: remaining work per day (solid) against the ideal line (dashed).
 * Plain SVG, scales to its container; values listed in a visually-hidden table for screen readers.
 */
const props = defineProps<{
    data: {
        unit: string;
        scope: number;
        days: { date: string; ideal: number; remaining: number | null }[];
    };
}>();

const W = 600;
const H = 220;
const pad = { l: 36, r: 12, t: 12, b: 28 };

const max = computed(() =>
    Math.max(
        1,
        props.data.scope,
        ...props.data.days.map((d) => d.remaining ?? 0),
    ),
);
const x = (i: number) =>
    pad.l + (i * (W - pad.l - pad.r)) / Math.max(1, props.data.days.length - 1);
const y = (v: number) => pad.t + (1 - v / max.value) * (H - pad.t - pad.b);

const ideal = computed(() =>
    props.data.days.map((d, i) => `${x(i)},${y(d.ideal)}`).join(' '),
);
const actual = computed(() =>
    props.data.days
        .map((d, i) =>
            d.remaining === null ? null : `${x(i)},${y(d.remaining)}`,
        )
        .filter((p): p is string => p !== null)
        .join(' '),
);
const last = computed(
    () =>
        [...props.data.days].reverse().find((d) => d.remaining !== null) ??
        null,
);
const lastIndex = computed(() =>
    last.value ? props.data.days.indexOf(last.value) : -1,
);
const behind = computed(() =>
    last.value ? (last.value.remaining ?? 0) - last.value.ideal : 0,
);
const ticks = computed(() => {
    const step = Math.max(1, Math.ceil(props.data.days.length / 7));

    return props.data.days
        .map((d, i) => ({ i, label: formatDate(d.date) }))
        .filter((t) => t.i % step === 0 || t.i === props.data.days.length - 1);
});
</script>

<template>
    <figure class="space-y-2">
        <figcaption
            class="flex flex-wrap items-baseline justify-between gap-2 text-sm"
        >
            <span class="font-medium"
                >Burndown
                <span class="font-normal text-muted-foreground"
                    >({{ data.unit }})</span
                ></span
            >
            <span
                v-if="last"
                :class="
                    behind > 0.5
                        ? 'text-amber-700 dark:text-amber-300'
                        : 'text-emerald-700 dark:text-emerald-300'
                "
            >
                {{ last.remaining }} of {{ data.scope }} {{ data.unit }} left ·
                {{
                    behind > 0.5
                        ? `${Math.round(behind)} behind the ideal line`
                        : 'on track'
                }}
            </span>
        </figcaption>
        <svg
            :viewBox="`0 0 ${W} ${H}`"
            class="h-auto w-full"
            role="img"
            :aria-label="`Burndown chart, ${data.scope} ${data.unit} in scope`"
        >
            <line
                v-for="v in [0, max / 2, max]"
                :key="v"
                :x1="pad.l"
                :x2="W - pad.r"
                :y1="y(v)"
                :y2="y(v)"
                class="stroke-border"
                stroke-width="1"
            />
            <text
                v-for="v in [0, Math.round(max / 2), max]"
                :key="'t' + v"
                :x="pad.l - 6"
                :y="y(v) + 4"
                text-anchor="end"
                class="fill-muted-foreground text-[11px]"
            >
                {{ v }}
            </text>
            <text
                v-for="t in ticks"
                :key="'d' + t.i"
                :x="x(t.i)"
                :y="H - 8"
                text-anchor="middle"
                class="fill-muted-foreground text-[11px]"
            >
                {{ t.label }}
            </text>
            <polyline
                :points="ideal"
                fill="none"
                class="stroke-muted-foreground"
                stroke-width="1.5"
                stroke-dasharray="5 4"
            />
            <polyline
                v-if="actual"
                :points="actual"
                fill="none"
                class="stroke-primary"
                stroke-width="2.5"
                stroke-linejoin="round"
            />
            <circle
                v-if="last"
                :cx="x(lastIndex)"
                :cy="y(last.remaining ?? 0)"
                r="4"
                class="fill-primary"
            />
        </svg>
        <div class="flex gap-4 text-xs text-muted-foreground">
            <span class="inline-flex items-center gap-1"
                ><span class="inline-block h-0.5 w-4 bg-primary" />
                Remaining</span
            >
            <span class="inline-flex items-center gap-1"
                ><span
                    class="inline-block w-4 border-t border-dashed border-muted-foreground"
                />
                Ideal</span
            >
        </div>
        <table class="sr-only">
            <caption>
                Remaining
                {{
                    data.unit
                }}
                per day
            </caption>
            <tr v-for="d in data.days" :key="d.date">
                <th scope="row">{{ d.date }}</th>
                <td>{{ d.remaining ?? '—' }}</td>
                <td>ideal {{ d.ideal }}</td>
            </tr>
        </table>
    </figure>
</template>
