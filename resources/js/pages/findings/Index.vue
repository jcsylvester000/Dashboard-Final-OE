<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { Lightbulb, Plus, Trash2, X } from '@lucide/vue';
import { reactive, ref, watch } from 'vue';
import FindingController from '@/actions/App/Http/Controllers/Work/FindingController';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import TaskPicker from '@/components/work/TaskPicker.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { confirmDialog } from '@/composables/useConfirm';
import { timeAgo } from '@/lib/format';
import { index as findingsIndex } from '@/routes/findings';

/**
 * E-31: research findings across every client you can see. Search, filter by tag,
 * confidence and client; open the source; link a finding to the tasks it informs.
 */
type Row = {
    id: number;
    title: string;
    body: string | null;
    tags: string[];
    confidence: 'low' | 'medium' | 'high';
    source_type: string;
    quote: string | null;
    page: number | null;
    workspace: { id: number; name: string } | null;
    source: { key: string; title: string; url: string | null } | null;
    linked: { id: number; key: string; title: string; url: string | null }[];
    author: string;
    created_at: string | null;
    can_edit: boolean;
    can_link: boolean;
};

const props = defineProps<{
    findings: {
        data: Row[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
    };
    filters: {
        q: string | null;
        tag: string | null;
        confidence: string | null;
        workspace: number | string | null;
    };
    tags: string[];
    workspaces: { id: number; name: string }[];
    allWorkspaces: { id: number; name: string }[];
    confidence: Record<string, string>;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Findings', href: findingsIndex() }] },
});

const f = reactive({
    q: props.filters.q ?? '',
    tag: props.filters.tag ?? '',
    confidence: props.filters.confidence ?? '',
    workspace: props.filters.workspace ? String(props.filters.workspace) : '',
});
const apply = useDebounceFn(() => {
    router.get(
        findingsIndex.url({
            query: Object.fromEntries(
                Object.entries(f).filter(([, v]) => v !== ''),
            ),
        }),
        {},
        { preserveState: true, preserveScroll: true, replace: true },
    );
}, 300);
watch(f, apply);

const adding = ref(false);
const form = useForm({
    source_type: 'manual',
    workspace_id: props.workspaces[0]?.id ?? null,
    title: '',
    body: '',
    tags: '',
    confidence: 'medium',
});
function add(): void {
    form.transform((d) => ({
        ...d,
        tags: d.tags
            .split(',')
            .map((t) => t.trim())
            .filter(Boolean),
    })).post(FindingController.store.url(), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('title', 'body', 'tags');
            adding.value = false;
        },
    });
}

const editing = ref<number | null>(null);
const edit = useForm({ title: '', body: '', tags: '', confidence: 'medium' });
function startEdit(r: Row): void {
    editing.value = r.id;
    edit.title = r.title;
    edit.body = r.body ?? '';
    edit.tags = r.tags.join(', ');
    edit.confidence = r.confidence;
}
function saveEdit(r: Row): void {
    edit.transform((d) => ({
        ...d,
        tags: d.tags
            .split(',')
            .map((t) => t.trim())
            .filter(Boolean),
    })).put(FindingController.update.url(r.id), {
        preserveScroll: true,
        onSuccess: () => (editing.value = null),
    });
}
async function remove(r: Row): Promise<void> {
    if (await confirmDialog(`Delete the finding "${r.title}"?`)) {
        router.delete(FindingController.destroy.url(r.id), {
            preserveScroll: true,
        });
    }
}
const linking = ref<number | null>(null);
function link(r: Row, taskId: number): void {
    router.post(
        FindingController.link.url(r.id),
        { task_id: taskId },
        { preserveScroll: true, onSuccess: () => (linking.value = null) },
    );
}
function unlink(r: Row, taskId: number): void {
    router.delete(
        FindingController.unlink.url({ finding: r.id, task: taskId }),
        { preserveScroll: true },
    );
}
const pageLabel = (label: string) =>
    label.replace('&laquo;', '«').replace('&raquo;', '»');
const badge: Record<string, string> = {
    high: 'bg-emerald-100 text-emerald-900 dark:bg-emerald-950 dark:text-emerald-200',
    medium: 'bg-sky-100 text-sky-900 dark:bg-sky-950 dark:text-sky-200',
    low: 'bg-muted text-muted-foreground',
};
</script>

<template>
    <Head title="Findings" />
    <div class="flex max-w-5xl flex-1 flex-col gap-4 p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1
                    class="flex items-center gap-2 text-xl font-semibold tracking-tight"
                >
                    <Lightbulb class="size-5" /> Findings
                </h1>
                <p class="text-sm text-muted-foreground">
                    Research worth reusing: saved from comments, PDF highlights
                    and pins, or written here.
                    {{ findings.total }} finding{{
                        findings.total === 1 ? '' : 's'
                    }}.
                </p>
            </div>
            <Button v-if="workspaces.length" @click="adding = !adding"
                ><Plus /> New finding</Button
            >
        </div>

        <Card v-if="adding">
            <CardContent class="grid gap-2 pt-6 sm:grid-cols-4">
                <NativeSelect
                    v-model="form.workspace_id"
                    aria-label="Client"
                    class="sm:col-span-1"
                >
                    <option v-for="w in workspaces" :key="w.id" :value="w.id">
                        {{ w.name }}
                    </option>
                </NativeSelect>
                <Input
                    v-model="form.title"
                    class="sm:col-span-3"
                    placeholder="What did we learn?"
                    maxlength="200"
                    aria-label="Title"
                />
                <textarea
                    v-model="form.body"
                    rows="3"
                    class="rounded-md border bg-background p-2 text-sm sm:col-span-4"
                    placeholder="Evidence, context, source"
                    aria-label="Details"
                />
                <Input
                    v-model="form.tags"
                    class="sm:col-span-2"
                    placeholder="Tags, comma separated"
                    aria-label="Tags"
                />
                <NativeSelect v-model="form.confidence" aria-label="Confidence">
                    <option
                        v-for="(label, key) in confidence"
                        :key="key"
                        :value="key"
                    >
                        {{ label }} confidence
                    </option>
                </NativeSelect>
                <Button
                    :disabled="form.processing || !form.title.trim()"
                    @click="add"
                    >Save finding</Button
                >
                <InputError
                    :message="form.errors.title ?? form.errors.workspace_id"
                    class="sm:col-span-4"
                />
            </CardContent>
        </Card>

        <div class="flex flex-wrap items-center gap-2">
            <Input
                v-model="f.q"
                type="search"
                class="w-60"
                placeholder="Search findings"
                aria-label="Search findings"
            />
            <NativeSelect v-model="f.tag" class="w-40" aria-label="Tag">
                <option value="">Any tag</option>
                <option v-for="t in tags" :key="t" :value="t">#{{ t }}</option>
            </NativeSelect>
            <NativeSelect
                v-model="f.confidence"
                class="w-40"
                aria-label="Confidence"
            >
                <option value="">Any confidence</option>
                <option
                    v-for="(label, key) in confidence"
                    :key="key"
                    :value="key"
                >
                    {{ label }}
                </option>
            </NativeSelect>
            <NativeSelect
                v-model="f.workspace"
                class="w-48"
                aria-label="Client"
            >
                <option value="">All clients</option>
                <option
                    v-for="w in allWorkspaces"
                    :key="w.id"
                    :value="String(w.id)"
                >
                    {{ w.name }}
                </option>
            </NativeSelect>
        </div>

        <p
            v-if="!findings.data.length"
            class="rounded border border-dashed p-6 text-center text-sm text-muted-foreground"
        >
            No findings yet. On a task, use "Save as finding" under a comment,
            or in the file viewer on a highlight or pin.
        </p>

        <ul class="space-y-3">
            <li
                v-for="r in findings.data"
                :key="r.id"
                class="rounded-lg border p-4"
            >
                <template v-if="editing === r.id">
                    <div class="grid gap-2">
                        <Input
                            v-model="edit.title"
                            maxlength="200"
                            aria-label="Title"
                        />
                        <textarea
                            v-model="edit.body"
                            rows="3"
                            class="rounded-md border bg-background p-2 text-sm"
                            aria-label="Details"
                        />
                        <div class="flex flex-wrap gap-2">
                            <Input
                                v-model="edit.tags"
                                class="w-64"
                                aria-label="Tags"
                            />
                            <NativeSelect
                                v-model="edit.confidence"
                                class="w-40"
                                aria-label="Confidence"
                            >
                                <option
                                    v-for="(label, key) in confidence"
                                    :key="key"
                                    :value="key"
                                >
                                    {{ label }}
                                </option>
                            </NativeSelect>
                            <Button
                                size="sm"
                                :disabled="edit.processing"
                                @click="saveEdit(r)"
                                >Save</Button
                            >
                            <Button
                                size="sm"
                                variant="ghost"
                                @click="editing = null"
                                >Cancel</Button
                            >
                        </div>
                    </div>
                </template>
                <template v-else>
                    <div class="flex flex-wrap items-start gap-2">
                        <h2 class="min-w-0 flex-1 font-medium">
                            {{ r.title }}
                        </h2>
                        <span
                            class="rounded px-1.5 text-xs"
                            :class="badge[r.confidence]"
                            >{{ confidence[r.confidence] }} confidence</span
                        >
                        <button
                            v-if="r.can_edit"
                            type="button"
                            class="text-xs hover:underline"
                            @click="startEdit(r)"
                        >
                            Edit
                        </button>
                        <button
                            v-if="r.can_edit"
                            type="button"
                            class="text-muted-foreground"
                            :aria-label="`Delete ${r.title}`"
                            @click="remove(r)"
                        >
                            <Trash2 class="size-4" />
                        </button>
                    </div>
                    <blockquote
                        v-if="r.quote"
                        class="mt-2 border-l-2 border-yellow-400 pl-3 text-sm text-muted-foreground italic"
                    >
                        <template v-if="r.page">p. {{ r.page }} · </template>“{{
                            r.quote
                        }}”
                    </blockquote>
                    <p v-if="r.body" class="mt-2 text-sm whitespace-pre-line">
                        {{ r.body }}
                    </p>
                    <div class="mt-2 flex flex-wrap items-center gap-1 text-xs">
                        <button
                            v-for="t in r.tags"
                            :key="t"
                            type="button"
                            class="rounded bg-muted px-1.5 hover:underline"
                            @click="f.tag = t"
                        >
                            #{{ t }}
                        </button>
                    </div>
                    <p class="mt-2 text-xs text-muted-foreground">
                        {{ r.workspace?.name }}
                        <template v-if="r.source">
                            · from
                            <Link
                                v-if="r.source.url"
                                :href="r.source.url"
                                class="hover:underline"
                                >{{ r.source.key }} {{ r.source.title }}</Link
                            ></template
                        >
                        · {{ r.author }} · {{ timeAgo(r.created_at) }}
                    </p>
                    <div class="mt-2 flex flex-wrap items-center gap-1 text-xs">
                        <span class="text-muted-foreground">Informs:</span>
                        <span
                            v-for="t in r.linked"
                            :key="t.id"
                            class="inline-flex items-center gap-1 rounded border px-1.5"
                        >
                            <Link
                                v-if="t.url"
                                :href="t.url"
                                class="hover:underline"
                                >{{ t.key }} {{ t.title }}</Link
                            >
                            <button
                                v-if="r.can_link"
                                type="button"
                                :aria-label="`Unlink ${t.key}`"
                                @click="unlink(r, t.id)"
                            >
                                <X class="size-3" />
                            </button>
                        </span>
                        <span
                            v-if="!r.linked.length"
                            class="text-muted-foreground"
                            >nothing yet</span
                        >
                        <template v-if="r.can_link && r.workspace">
                            <div v-if="linking === r.id" class="w-72">
                                <TaskPicker
                                    :workspace-id="r.workspace.id"
                                    placeholder="Link a task or decision (title or key)"
                                    @pick="(id: number) => link(r, id)"
                                />
                            </div>
                            <button
                                v-else
                                type="button"
                                class="hover:underline"
                                @click="linking = r.id"
                            >
                                + link task
                            </button>
                        </template>
                    </div>
                </template>
            </li>
        </ul>

        <nav
            v-if="findings.links.length > 3"
            class="flex flex-wrap gap-1 text-sm"
            aria-label="Pages"
        >
            <Link
                v-for="(l, i) in findings.links"
                :key="i"
                :href="l.url ?? ''"
                class="rounded border px-2 py-1"
                :class="[
                    l.active ? 'bg-primary text-primary-foreground' : '',
                    !l.url ? 'pointer-events-none opacity-40' : '',
                ]"
                preserve-scroll
                >{{ pageLabel(l.label) }}</Link
            >
        </nav>
    </div>
</template>
