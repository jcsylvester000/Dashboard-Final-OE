<script setup lang="ts">
import { ChevronDown, ChevronUp, Download, WrapText, X } from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';

/**
 * E-23: read logs, JSON, CSV and text files without downloading them.
 * Line numbers, wrap toggle, search with highlight, ERROR/WARN colouring,
 * pretty JSON, long stack traces folded, and lines rendered in chunks.
 * Text is shown as text (never as HTML).
 */
const props = defineProps<{
    name: string;
    url: string;
    downloadUrl: string;
}>();
const emit = defineEmits<{ close: [] }>();

const CHUNK = 3000;
const FOLD_MIN = 6;
const FRAME = /^\s*(at\s|#\d+\s|\.\.\.\s\d+\smore|File ".*", line \d+)/;
const ERROR = /\b(ERROR|FATAL|CRITICAL|EMERGENCY|ALERT|Exception|Traceback)\b/;
const WARN = /\b(WARN|WARNING|NOTICE|DEPRECATED)\b/;

const raw = ref('');
const loading = ref(true);
const failed = ref<string | null>(null);
const truncated = ref(false);
const wrap = ref(true);
const pretty = ref(true);
const query = ref('');
const limit = ref(CHUNK);
const expanded = ref<Set<number>>(new Set());
const current = ref(0);
const searchInput = ref<HTMLInputElement | null>(null);

const isJson = computed(() => props.name.toLowerCase().endsWith('.json'));

const text = computed(() => {
    if (isJson.value && pretty.value) {
        try {
            return JSON.stringify(JSON.parse(raw.value), null, 2);
        } catch {
            return raw.value;
        }
    }

    return raw.value;
});
const lines = computed(() => text.value.replace(/\r\n?/g, '\n').split('\n'));

type Row =
    | { kind: 'line'; n: number; text: string; level: 'error' | 'warn' | null }
    | { kind: 'fold'; id: number; count: number };

function level(line: string): 'error' | 'warn' | null {
    return ERROR.test(line) ? 'error' : WARN.test(line) ? 'warn' : null;
}

const rows = computed<Row[]>(() => {
    const out: Row[] = [];
    const all = lines.value;
    const folding = query.value.trim() === '';
    let i = 0;
    while (i < all.length) {
        if (folding && FRAME.test(all[i])) {
            let j = i;
            while (j < all.length && FRAME.test(all[j])) {
                j++;
            }
            const run = j - i;
            if (run >= FOLD_MIN && !expanded.value.has(i)) {
                for (let k = i; k < i + 2; k++) {
                    out.push({
                        kind: 'line',
                        n: k + 1,
                        text: all[k],
                        level: null,
                    });
                }
                out.push({ kind: 'fold', id: i, count: run - 2 });
                i = j;
                continue;
            }
        }
        out.push({
            kind: 'line',
            n: i + 1,
            text: all[i],
            level: level(all[i]),
        });
        i++;
    }

    return out;
});
const visible = computed(() => rows.value.slice(0, limit.value));

const needle = computed(() => query.value.trim().toLowerCase());
const matches = computed(() =>
    needle.value === ''
        ? []
        : rows.value.flatMap((r, idx) =>
              r.kind === 'line' && r.text.toLowerCase().includes(needle.value)
                  ? [idx]
                  : [],
          ),
);
const counts = computed(() => ({
    errors: rows.value.filter((r) => r.kind === 'line' && r.level === 'error')
        .length,
    warnings: rows.value.filter((r) => r.kind === 'line' && r.level === 'warn')
        .length,
}));

/** Split a line around the search term so matches can be marked without v-html. */
function parts(line: string): { t: string; hit: boolean }[] {
    if (needle.value === '') {
        return [{ t: line, hit: false }];
    }
    const out: { t: string; hit: boolean }[] = [];
    const lower = line.toLowerCase();
    let from = 0;
    let at = lower.indexOf(needle.value);
    while (at !== -1) {
        if (at > from) {
            out.push({ t: line.slice(from, at), hit: false });
        }
        out.push({ t: line.slice(at, at + needle.value.length), hit: true });
        from = at + needle.value.length;
        at = lower.indexOf(needle.value, from);
    }
    if (from < line.length) {
        out.push({ t: line.slice(from), hit: false });
    }

    return out;
}

async function jump(step: number): Promise<void> {
    if (!matches.value.length) {
        return;
    }
    current.value =
        (current.value + step + matches.value.length) % matches.value.length;
    const idx = matches.value[current.value];
    if (idx >= limit.value) {
        limit.value = Math.ceil((idx + 1) / CHUNK) * CHUNK;
    }
    await nextTick();
    document
        .getElementById(`tv-row-${idx}`)
        ?.scrollIntoView({ block: 'center' });
}

watch(needle, () => {
    current.value = -1;
    void jump(1);
});

function onKey(e: KeyboardEvent): void {
    if (e.key === 'Escape') {
        emit('close');
    }
}

onMounted(async () => {
    document.addEventListener('keydown', onKey);
    searchInput.value?.focus();
    try {
        const res = await fetch(props.url, {
            credentials: 'same-origin',
            headers: { Accept: 'text/plain' },
        });
        if (!res.ok) {
            failed.value =
                res.status === 403
                    ? 'The link expired. Reload the page and try again.'
                    : 'Could not open this file.';

            return;
        }
        truncated.value = res.headers.get('X-Truncated') === '1';
        raw.value = await res.text();
    } catch {
        failed.value = 'Could not open this file.';
    } finally {
        loading.value = false;
    }
});
onBeforeUnmount(() => document.removeEventListener('keydown', onKey));
</script>

<template>
    <div
        class="fixed inset-0 z-50 flex flex-col bg-background"
        role="dialog"
        aria-modal="true"
        :aria-label="`View ${name}`"
    >
        <header
            class="flex flex-wrap items-center gap-2 border-b px-4 py-2 text-sm"
        >
            <h2 class="min-w-0 flex-1 truncate font-medium" :title="name">
                {{ name }}
            </h2>
            <span
                v-if="counts.errors"
                class="rounded bg-red-100 px-1.5 text-xs text-red-800 dark:bg-red-950 dark:text-red-200"
                >{{ counts.errors }} error lines</span
            >
            <span
                v-if="counts.warnings"
                class="rounded bg-amber-100 px-1.5 text-xs text-amber-900 dark:bg-amber-950 dark:text-amber-200"
                >{{ counts.warnings }} warnings</span
            >
            <input
                ref="searchInput"
                v-model="query"
                type="search"
                placeholder="Search"
                aria-label="Search in file"
                class="h-8 w-44 rounded-md border bg-transparent px-2 text-sm"
                @keydown.enter.prevent="jump($event.shiftKey ? -1 : 1)"
            />
            <span
                v-if="needle"
                class="text-xs text-muted-foreground"
                aria-live="polite"
                >{{ matches.length ? current + 1 : 0 }} of
                {{ matches.length }}</span
            >
            <button
                type="button"
                class="rounded p-1 hover:bg-muted"
                aria-label="Previous match"
                @click="jump(-1)"
            >
                <ChevronUp class="size-4" />
            </button>
            <button
                type="button"
                class="rounded p-1 hover:bg-muted"
                aria-label="Next match"
                @click="jump(1)"
            >
                <ChevronDown class="size-4" />
            </button>
            <button
                type="button"
                class="flex items-center gap-1 rounded px-2 py-1 text-xs hover:bg-muted"
                :aria-pressed="wrap"
                @click="wrap = !wrap"
            >
                <WrapText class="size-4" /> Wrap
            </button>
            <button
                v-if="isJson"
                type="button"
                class="rounded px-2 py-1 text-xs hover:bg-muted"
                :aria-pressed="pretty"
                @click="pretty = !pretty"
            >
                Pretty JSON
            </button>
            <a
                :href="downloadUrl"
                class="rounded p-1 hover:bg-muted"
                :aria-label="`Download ${name}`"
                ><Download class="size-4"
            /></a>
            <button
                type="button"
                class="rounded p-1 hover:bg-muted"
                aria-label="Close"
                @click="emit('close')"
            >
                <X class="size-4" />
            </button>
        </header>
        <p
            v-if="truncated"
            class="border-b bg-amber-50 px-4 py-1 text-xs text-amber-900 dark:bg-amber-950 dark:text-amber-200"
        >
            Showing the first 2 MB. Download the file to see all of it.
        </p>
        <div class="flex-1 overflow-auto font-mono text-xs leading-5">
            <p v-if="loading" class="p-4 text-muted-foreground">Loading…</p>
            <p v-else-if="failed" class="p-4 text-destructive">{{ failed }}</p>
            <table v-else class="w-full border-collapse">
                <tbody>
                    <tr
                        v-for="(r, idx) in visible"
                        :id="`tv-row-${idx}`"
                        :key="r.kind === 'line' ? `l${r.n}` : `f${r.id}`"
                        :class="{
                            'bg-red-50 dark:bg-red-950/40':
                                r.kind === 'line' && r.level === 'error',
                            'bg-amber-50 dark:bg-amber-950/40':
                                r.kind === 'line' && r.level === 'warn',
                            'outline outline-1 outline-primary':
                                matches[current] === idx,
                        }"
                    >
                        <template v-if="r.kind === 'line'">
                            <td
                                class="w-px border-r px-2 text-right align-top text-muted-foreground select-none"
                            >
                                {{ r.n }}
                            </td>
                            <td
                                class="px-2 align-top"
                                :class="
                                    wrap
                                        ? 'break-all whitespace-pre-wrap'
                                        : 'whitespace-pre'
                                "
                            >
                                <template
                                    v-for="(p, i) in parts(r.text)"
                                    :key="i"
                                    ><mark
                                        v-if="p.hit"
                                        class="rounded-sm bg-yellow-300 text-black"
                                        >{{ p.t }}</mark
                                    ><template v-else>{{
                                        p.t
                                    }}</template></template
                                >
                            </td>
                        </template>
                        <td v-else colspan="2" class="px-2 py-0.5">
                            <button
                                type="button"
                                class="rounded bg-muted px-2 text-muted-foreground hover:underline"
                                @click="expanded = new Set([...expanded, r.id])"
                            >
                                … {{ r.count }} more stack frames
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
            <div
                v-if="!loading && !failed && rows.length > limit"
                class="p-3 text-center"
            >
                <button
                    type="button"
                    class="rounded border px-3 py-1 text-xs hover:bg-muted"
                    @click="limit += CHUNK"
                >
                    Show {{ Math.min(CHUNK, rows.length - limit) }} more lines
                    ({{ rows.length - limit }} left)
                </button>
            </div>
        </div>
    </div>
</template>
