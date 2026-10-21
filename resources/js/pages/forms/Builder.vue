<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, GripVertical, Plus, Trash2 } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import FormController from '@/actions/App/Http/Controllers/Workspaces/FormController';
import FormRenderer from '@/components/forms/FormRenderer.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import WorkspaceHeader from '@/components/WorkspaceHeader.vue';
import type {
    Answers,
    FieldType,
    FormField,
    FormSchemaShape,
} from '@/lib/forms';
import { emptyField, validate } from '@/lib/forms';
import type { CustomField } from '@/types/work';
import type {
    MemberOption,
    WorkspaceHeader as Header,
} from '@/types/workspace';

/**
 * E-53 / E-54: build a form. Sections and fields (drag to reorder, or use the arrows),
 * conditions that show a section or field only for certain answers, a live preview that
 * validates exactly like the server, and what a submission does. Saving keeps a draft;
 * Publish freezes it as a new version (old submissions keep their own version).
 */
type Output = {
    type: string;
    title: string;
    department_id: number | null;
    priority: string;
    due_in_days: number | null;
    map: Record<string, string>;
    notify_user_ids: number[];
};

const props = defineProps<{
    workspace: Header;
    form: {
        id: number;
        name: string;
        description: string | null;
        draft: FormSchemaShape;
        output: Output;
        version: number | null;
        public_url: string | null;
        archived: boolean;
    };
    types: Record<FieldType, string>;
    outputTypes: Record<string, string>;
    departments: { id: number; name: string }[];
    customFields: CustomField[];
    members: MemberOption[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Form builder', href: '#' }] },
});

const args = { workspace: props.workspace.slug, form: props.form.id };
const meta = reactive({
    name: props.form.name,
    description: props.form.description ?? '',
});
const schema = reactive<FormSchemaShape>(
    JSON.parse(JSON.stringify(props.form.draft)),
);
const output = reactive<Output>({
    ...props.form.output,
    map: { ...props.form.output.map },
});
const selected = ref<{ s: number; f: number } | null>(null);
const errors = ref<Record<string, string>>({});
const saving = ref(false);

const current = computed<FormField | null>(() =>
    selected.value
        ? (schema.sections[selected.value.s]?.fields[selected.value.f] ?? null)
        : null,
);
/** Fields above a position (conditions may only look upwards). */
function fieldsAbove(s: number, f: number): FormField[] {
    const out: FormField[] = [];
    schema.sections.forEach((sec, si) =>
        sec.fields.forEach((fld, fi) => {
            if (si < s || (si === s && fi < f)) {
                out.push(fld);
            }
        }),
    );
    return out.filter((x) => x.key || x.label);
}
const allFields = computed(() => schema.sections.flatMap((s) => s.fields));
const keyOf = (f: FormField) =>
    f.key ||
    f.label
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_|_$/g, '');

function addSection(): void {
    schema.sections.push({
        id: `s${schema.sections.length + 1}`,
        title: 'New section',
        show_if: null,
        fields: [],
    });
}
function addField(si: number, type: FieldType): void {
    const field = emptyField(type);
    field.label = props.types[type];
    schema.sections[si].fields.push(field);
    selected.value = { s: si, f: schema.sections[si].fields.length - 1 };
}
function addCustom(si: number, c: CustomField): void {
    const map: Record<string, FieldType> = {
        text: 'text',
        number: 'number',
        date: 'date',
        select: 'select',
        checkbox: 'yesno',
        url: 'url',
    };
    const field = emptyField(map[c.type] ?? 'text');
    field.label = c.label;
    field.key = c.key;
    field.options = c.options ?? [];
    schema.sections[si].fields.push(field);
    output.map[`custom.${c.key}`] = c.key;
}
function move(si: number, fi: number, dir: -1 | 1): void {
    const list = schema.sections[si].fields;
    const j = fi + dir;
    if (j >= 0 && j < list.length) {
        [list[fi], list[j]] = [list[j], list[fi]];
        selected.value = { s: si, f: j };
    }
}
function remove(si: number, fi: number): void {
    schema.sections[si].fields.splice(fi, 1);
    selected.value = null;
}
// Drag and drop within and across sections.
const dragging = ref<{ s: number; f: number } | null>(null);
function drop(si: number, fi: number): void {
    const from = dragging.value;
    dragging.value = null;
    if (!from) {
        return;
    }
    const [field] = schema.sections[from.s].fields.splice(from.f, 1);
    const at = from.s === si && from.f < fi ? fi - 1 : fi;
    schema.sections[si].fields.splice(at, 0, field);
    selected.value = { s: si, f: at };
}
function setCondition(
    target: { show_if: FormField['show_if'] },
    field: string,
): void {
    target.show_if = field
        ? {
              field,
              op: target.show_if?.op ?? 'eq',
              value: target.show_if?.value ?? '',
          }
        : null;
}

// Preview
const answers = ref<Answers>({});
const files = ref<Record<string, File[]>>({});
const previewErrors = ref<Record<string, string>>({});
function check(): void {
    previewErrors.value = validate(
        schema,
        answers.value,
        Object.fromEntries(
            Object.entries(files.value).map(([k, v]) => [k, v.length]),
        ),
    );
}

function save(after?: () => void): void {
    saving.value = true;
    // Keys come from labels when left blank (the server does the same).
    schema.sections.forEach((s) => s.fields.forEach((f) => (f.key = keyOf(f))));
    router.put(
        FormController.update.url(args),
        {
            name: meta.name,
            description: meta.description || null,
            draft: schema,
            output,
        },
        {
            preserveScroll: true,
            onError: (e) => (errors.value = e),
            onSuccess: () => {
                errors.value = {};
                after?.();
            },
            onFinish: () => (saving.value = false),
        },
    );
}
function publish(): void {
    save(() =>
        router.post(
            FormController.publish.url(args),
            {},
            { preserveScroll: true },
        ),
    );
}
function toggleLink(): void {
    if (props.form.public_url) {
        router.delete(FormController.unlink.url(args), {
            preserveScroll: true,
        });
    } else {
        router.post(
            FormController.link.url(args),
            {},
            { preserveScroll: true },
        );
    }
}
const targets = computed(() => [
    { value: 'due_on', label: 'Task due date (date field)' },
    {
        value: 'priority',
        label: 'Task priority (low / normal / high / urgent)',
    },
    ...props.customFields.map((c) => ({
        value: `custom.${c.key}`,
        label: `Custom field: ${c.label}`,
    })),
    ...[
        'channel',
        'campaign',
        'deliverable_type',
        'target_url',
        'keyword',
        'question',
    ].map((k) => ({
        value: `work.${k}`,
        label: `Work detail: ${k.replace('_', ' ')}`,
    })),
]);
function setMap(target: string, key: string): void {
    const next = { ...output.map };
    if (key) {
        next[target] = key;
    } else {
        delete next[target];
    }
    output.map = next;
}
const OPS = {
    eq: 'is',
    neq: 'is not',
    filled: 'is answered',
    empty: 'is empty',
    includes: 'includes',
} as const;
</script>

<template>
    <Head :title="`Edit ${form.name}`" />
    <div class="flex flex-1 flex-col gap-4 p-4">
        <WorkspaceHeader :workspace="workspace" />

        <div class="flex flex-wrap items-end gap-2">
            <label class="grid gap-1">
                <span class="text-xs text-muted-foreground">Form name</span>
                <Input v-model="meta.name" class="w-72" maxlength="120" />
            </label>
            <label class="grid min-w-0 flex-1 gap-1">
                <span class="text-xs text-muted-foreground"
                    >Intro (shown at the top)</span
                >
                <Input v-model="meta.description" maxlength="1000" />
            </label>
            <Button variant="secondary" :disabled="saving" @click="save()"
                >Save draft</Button
            >
            <Button :disabled="saving" @click="publish"
                >Publish{{
                    form.version ? ` v${form.version + 1}` : ''
                }}</Button
            >
        </div>
        <p class="text-xs text-muted-foreground">
            <template v-if="form.version">Live: v{{ form.version }}. </template>
            <template v-else>Not published yet. </template>
            <Link :href="FormController.submissions.url(args)" class="underline"
                >Submissions</Link
            >
            ·
            <button
                v-if="form.version"
                type="button"
                class="underline"
                @click="toggleLink"
            >
                {{
                    form.public_url
                        ? 'Turn off client link'
                        : 'Create client link (no login)'
                }}
            </button>
            <template v-if="form.public_url">
                ·
                <code class="rounded bg-muted px-1">{{
                    form.public_url
                }}</code></template
            >
        </p>
        <p
            v-if="Object.keys(errors).length"
            class="rounded border border-rose-300 bg-rose-50 p-2 text-sm text-rose-800 dark:bg-rose-950 dark:text-rose-200"
            role="alert"
        >
            {{ Object.values(errors)[0] }}
        </p>

        <div class="grid gap-4 lg:grid-cols-2">
            <!-- Builder -->
            <Card>
                <CardHeader>
                    <CardTitle class="text-base">Fields</CardTitle>
                    <CardDescription
                        >Drag to reorder. Click a field to edit
                        it.</CardDescription
                    >
                </CardHeader>
                <CardContent class="space-y-4 text-sm">
                    <section
                        v-for="(sec, si) in schema.sections"
                        :key="si"
                        class="space-y-2 rounded border p-3"
                    >
                        <div class="flex flex-wrap items-center gap-2">
                            <Input
                                :model-value="sec.title ?? ''"
                                class="h-8 flex-1"
                                @update:model-value="
                                    (v) => (sec.title = String(v) || null)
                                "
                                placeholder="Section title (optional)"
                                aria-label="Section title"
                            />
                            <button
                                v-if="schema.sections.length > 1"
                                type="button"
                                aria-label="Remove section"
                                @click="schema.sections.splice(si, 1)"
                            >
                                <Trash2 class="size-4" />
                            </button>
                        </div>
                        <div
                            v-if="si > 0"
                            class="flex flex-wrap items-center gap-1 text-xs"
                        >
                            Show this section when
                            <NativeSelect
                                :model-value="sec.show_if?.field ?? ''"
                                class="h-7 w-40"
                                aria-label="Section condition field"
                                @update:model-value="
                                    (v) => setCondition(sec, String(v ?? ''))
                                "
                            >
                                <option value="">always</option>
                                <option
                                    v-for="f in fieldsAbove(si, 0)"
                                    :key="keyOf(f)"
                                    :value="keyOf(f)"
                                >
                                    {{ f.label }}
                                </option>
                            </NativeSelect>
                            <template v-if="sec.show_if">
                                <select
                                    v-model="sec.show_if.op"
                                    class="h-7 rounded border bg-background px-1"
                                    aria-label="Condition"
                                >
                                    <option
                                        v-for="(l, op) in OPS"
                                        :key="op"
                                        :value="op"
                                    >
                                        {{ l }}
                                    </option>
                                </select>
                                <input
                                    v-if="
                                        !['filled', 'empty'].includes(
                                            sec.show_if.op,
                                        )
                                    "
                                    v-model="sec.show_if.value"
                                    class="h-7 w-32 rounded border bg-background px-1"
                                    aria-label="Value"
                                />
                            </template>
                        </div>
                        <ol class="space-y-1">
                            <li
                                v-for="(f, fi) in sec.fields"
                                :key="`${si}-${fi}`"
                                draggable="true"
                                class="flex items-center gap-2 rounded border px-2 py-1"
                                :class="
                                    selected?.s === si && selected?.f === fi
                                        ? 'border-primary bg-primary/5'
                                        : ''
                                "
                                @dragstart="dragging = { s: si, f: fi }"
                                @dragover.prevent
                                @drop="drop(si, fi)"
                            >
                                <GripVertical
                                    class="size-4 cursor-grab text-muted-foreground"
                                />
                                <button
                                    type="button"
                                    class="min-w-0 flex-1 truncate text-left"
                                    @click="selected = { s: si, f: fi }"
                                >
                                    {{ f.label || '(no label)' }}
                                    <span class="text-xs text-muted-foreground"
                                        >· {{ types[f.type]
                                        }}{{ f.required ? ' · required' : ''
                                        }}{{
                                            f.show_if ? ' · conditional' : ''
                                        }}</span
                                    >
                                </button>
                                <button
                                    type="button"
                                    aria-label="Move up"
                                    @click="move(si, fi, -1)"
                                >
                                    <ArrowUp class="size-3.5" />
                                </button>
                                <button
                                    type="button"
                                    aria-label="Move down"
                                    @click="move(si, fi, 1)"
                                >
                                    <ArrowDown class="size-3.5" />
                                </button>
                                <button
                                    type="button"
                                    aria-label="Remove field"
                                    @click="remove(si, fi)"
                                >
                                    <Trash2 class="size-3.5" />
                                </button>
                            </li>
                            <li
                                class="rounded border border-dashed px-2 py-1 text-xs text-muted-foreground"
                                @dragover.prevent
                                @drop="drop(si, sec.fields.length)"
                            >
                                Drop here
                            </li>
                        </ol>
                        <div class="flex flex-wrap items-center gap-1 text-xs">
                            <span class="text-muted-foreground">Add:</span>
                            <button
                                v-for="(label, t) in types"
                                :key="t"
                                type="button"
                                class="rounded border px-1.5 py-0.5 hover:bg-muted"
                                @click="addField(si, t)"
                            >
                                {{ label }}
                            </button>
                            <template v-if="customFields.length">
                                <span class="ml-2 text-muted-foreground"
                                    >Client fields:</span
                                >
                                <button
                                    v-for="c in customFields"
                                    :key="c.key"
                                    type="button"
                                    class="rounded border border-sky-300 px-1.5 py-0.5 hover:bg-muted"
                                    @click="addCustom(si, c)"
                                >
                                    {{ c.label }}
                                </button>
                            </template>
                        </div>
                    </section>
                    <Button size="sm" variant="secondary" @click="addSection"
                        ><Plus /> Add section</Button
                    >

                    <!-- Field editor -->
                    <div
                        v-if="current && selected"
                        class="space-y-2 rounded border bg-muted/30 p-3"
                    >
                        <p class="font-medium">Edit field</p>
                        <div class="grid gap-2 sm:grid-cols-2">
                            <label class="grid gap-1"
                                ><span class="text-xs">Label</span
                                ><Input v-model="current.label" maxlength="120"
                            /></label>
                            <label class="grid gap-1"
                                ><span class="text-xs"
                                    >Key (for mapping; blank = from label)</span
                                ><Input v-model="current.key" maxlength="40"
                            /></label>
                            <label class="grid gap-1">
                                <span class="text-xs">Type</span>
                                <select
                                    v-model="current.type"
                                    class="h-9 rounded-md border bg-background px-2"
                                >
                                    <option
                                        v-for="(label, t) in types"
                                        :key="t"
                                        :value="t"
                                    >
                                        {{ label }}
                                    </option>
                                </select>
                            </label>
                            <label class="flex items-center gap-2 pt-5"
                                ><input
                                    v-model="current.required"
                                    type="checkbox"
                                    class="size-4"
                                />
                                Required</label
                            >
                            <label class="grid gap-1 sm:col-span-2"
                                ><span class="text-xs">Help text</span
                                ><Input
                                    :model-value="current.help ?? ''"
                                    maxlength="300"
                                    @update:model-value="
                                        (v) =>
                                            current &&
                                            (current.help = String(v) || null)
                                    "
                            /></label>
                            <label
                                v-if="
                                    current.type === 'select' ||
                                    current.type === 'multiselect'
                                "
                                class="grid gap-1 sm:col-span-2"
                            >
                                <span class="text-xs"
                                    >Options (one per line)</span
                                >
                                <textarea
                                    :value="current.options.join('\n')"
                                    rows="4"
                                    class="rounded-md border bg-background p-2"
                                    @input="
                                        current &&
                                        (current.options = (
                                            $event.target as HTMLTextAreaElement
                                        ).value.split('\n'))
                                    "
                                />
                            </label>
                            <label
                                v-if="current.type === 'file'"
                                class="grid gap-1"
                            >
                                <span class="text-xs">Max files (1-5)</span>
                                <input
                                    v-model.number="current.max_files"
                                    type="number"
                                    min="1"
                                    max="5"
                                    class="h-9 rounded-md border bg-background px-2"
                                />
                            </label>
                        </div>
                        <div class="flex flex-wrap items-center gap-1 text-xs">
                            Show this field when
                            <NativeSelect
                                :model-value="current.show_if?.field ?? ''"
                                class="h-7 w-40"
                                aria-label="Field condition"
                                @update:model-value="
                                    (v) =>
                                        current &&
                                        setCondition(current, String(v ?? ''))
                                "
                            >
                                <option value="">always</option>
                                <option
                                    v-for="f in fieldsAbove(
                                        selected.s,
                                        selected.f,
                                    )"
                                    :key="keyOf(f)"
                                    :value="keyOf(f)"
                                >
                                    {{ f.label }}
                                </option>
                            </NativeSelect>
                            <template v-if="current.show_if">
                                <select
                                    v-model="current.show_if.op"
                                    class="h-7 rounded border bg-background px-1"
                                    aria-label="Condition"
                                >
                                    <option
                                        v-for="(l, op) in OPS"
                                        :key="op"
                                        :value="op"
                                    >
                                        {{ l }}
                                    </option>
                                </select>
                                <input
                                    v-if="
                                        !['filled', 'empty'].includes(
                                            current.show_if.op,
                                        )
                                    "
                                    v-model="current.show_if.value"
                                    class="h-7 w-32 rounded border bg-background px-1"
                                    aria-label="Value"
                                />
                            </template>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <div class="space-y-4">
                <!-- Preview -->
                <Card>
                    <CardHeader>
                        <CardTitle class="text-base">Preview</CardTitle>
                        <CardDescription
                            >Try answers; conditions and checks work exactly as
                            on the live form.</CardDescription
                        >
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <FormRenderer
                            v-model:answers="answers"
                            v-model:files="files"
                            :schema="schema"
                            :errors="previewErrors"
                        />
                        <div class="flex items-center gap-2">
                            <Button size="sm" variant="secondary" @click="check"
                                >Check answers</Button
                            >
                            <span
                                v-if="
                                    Object.keys(previewErrors).length === 0 &&
                                    Object.keys(answers).length
                                "
                                class="text-xs text-emerald-700"
                                >Looks good</span
                            >
                        </div>
                    </CardContent>
                </Card>

                <!-- Output (E-54) -->
                <Card>
                    <CardHeader>
                        <CardTitle class="text-base"
                            >When someone submits</CardTitle
                        >
                        <CardDescription
                            >Each submission creates exactly one task (a retried
                            send never makes two), or is kept as a
                            record.</CardDescription
                        >
                    </CardHeader>
                    <CardContent class="space-y-2 text-sm">
                        <select
                            v-model="output.type"
                            class="h-9 rounded-md border bg-background px-2"
                            aria-label="Output"
                        >
                            <option
                                v-for="(label, k) in outputTypes"
                                :key="k"
                                :value="k"
                            >
                                {{ label }}
                            </option>
                        </select>
                        <template v-if="output.type === 'task'">
                            <label class="grid gap-1">
                                <span class="text-xs"
                                    >Task title (use
                                    {{ '{' + '{field_key}' + '}' }} or
                                    {{ '{' + '{reference}' + '}' }})</span
                                >
                                <Input v-model="output.title" maxlength="200" />
                            </label>
                            <div class="grid gap-2 sm:grid-cols-3">
                                <NativeSelect
                                    v-model="output.department_id"
                                    aria-label="Department"
                                >
                                    <option :value="null">
                                        Any department
                                    </option>
                                    <option
                                        v-for="d in departments"
                                        :key="d.id"
                                        :value="d.id"
                                    >
                                        {{ d.name }}
                                    </option>
                                </NativeSelect>
                                <select
                                    v-model="output.priority"
                                    class="h-9 rounded-md border bg-background px-2"
                                    aria-label="Priority"
                                >
                                    <option
                                        v-for="p in [
                                            'low',
                                            'normal',
                                            'high',
                                            'urgent',
                                        ]"
                                        :key="p"
                                        :value="p"
                                    >
                                        {{ p }} priority
                                    </option>
                                </select>
                                <label class="flex items-center gap-1 text-xs"
                                    >Due in
                                    <input
                                        v-model.number="output.due_in_days"
                                        type="number"
                                        min="0"
                                        max="365"
                                        class="h-8 w-16 rounded border bg-background px-1"
                                    />
                                    days</label
                                >
                            </div>
                            <p class="pt-2 text-xs font-medium">
                                Map answers to task fields
                            </p>
                            <div
                                v-for="t in targets"
                                :key="t.value"
                                class="flex items-center gap-2 text-xs"
                            >
                                <span class="w-60 shrink-0">{{ t.label }}</span>
                                <select
                                    :value="output.map[t.value] ?? ''"
                                    class="h-7 flex-1 rounded border bg-background px-1"
                                    :aria-label="t.label"
                                    @change="
                                        setMap(
                                            t.value,
                                            ($event.target as HTMLSelectElement)
                                                .value,
                                        )
                                    "
                                >
                                    <option value="">(not mapped)</option>
                                    <option
                                        v-for="f in allFields"
                                        :key="keyOf(f)"
                                        :value="keyOf(f)"
                                    >
                                        {{ f.label }}
                                    </option>
                                </select>
                            </div>
                            <p class="text-xs text-muted-foreground">
                                All answers also go into the task description;
                                uploaded files are attached to the task.
                            </p>
                        </template>
                        <label class="grid gap-1 pt-2">
                            <span class="text-xs"
                                >Alert these people (blank = the client's
                                leads)</span
                            >
                            <select
                                v-model="output.notify_user_ids"
                                multiple
                                size="4"
                                class="rounded-md border bg-background p-1"
                            >
                                <option
                                    v-for="m in members"
                                    :key="m.id"
                                    :value="m.id"
                                >
                                    {{ m.name }}
                                </option>
                            </select>
                        </label>
                    </CardContent>
                </Card>
            </div>
        </div>
    </div>
</template>
