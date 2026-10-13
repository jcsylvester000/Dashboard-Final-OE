<script setup lang="ts">
import { Form, Head, router, useForm } from '@inertiajs/vue3';
import { Trash2 } from '@lucide/vue';
import LabelController from '@/actions/App/Http/Controllers/Workspaces/LabelController';
import WorkspaceController from '@/actions/App/Http/Controllers/Workspaces/WorkspaceController';
import InputError from '@/components/InputError.vue';
import LabelBadge from '@/components/LabelBadge.vue';
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
import { Label } from '@/components/ui/label';
import WorkspaceHeader from '@/components/WorkspaceHeader.vue';
import { confirmDialog } from '@/composables/useConfirm';
import { index as workspacesIndex } from '@/routes/workspaces';
import { update as statusLabelsUpdate } from '@/routes/workspaces/status-labels';
import { update as taskFieldsUpdate } from '@/routes/workspaces/task-fields';
import type { CustomField } from '@/types/work';
import type { WorkspaceHeader as Header } from '@/types/workspace';

const props = defineProps<{
    workspace: Header;
    details: {
        name: string;
        industry: string | null;
        website: string | null;
        status: string;
        color: string;
        description: string | null;
        primary_contact_name: string | null;
        key: string | null;
    };
    labels: { id: number; name: string; color: string; uses: number }[];
    colors: string[];
    statuses: string[];
    customFields?: CustomField[];
    fieldTypes?: Record<string, string>;
    maxFields?: number;
    taskStatuses?: { id: number; name: string; label: string }[];
}>();

// B-07: custom task fields (options typed one per line for "Pick one").
type FieldRow = {
    key: string | null;
    label: string;
    type: CustomField['type'];
    optionsText: string;
};
const fieldsForm = useForm<{ rows: FieldRow[] }>({
    rows: (props.customFields ?? []).map((f) => ({
        key: f.key,
        label: f.label,
        type: f.type,
        optionsText: (f.options ?? []).join('\n'),
    })),
});
function addField(): void {
    fieldsForm.rows.push({
        key: null,
        label: '',
        type: 'text',
        optionsText: '',
    });
}
function saveFields(): void {
    fieldsForm
        .transform((d) => ({
            fields: d.rows.map((r) => ({
                key: r.key,
                label: r.label,
                type: r.type,
                options:
                    r.type === 'select'
                        ? r.optionsText
                              .split('\n')
                              .map((o) => o.trim())
                              .filter(Boolean)
                        : null,
            })),
        }))
        .put(taskFieldsUpdate.url(props.workspace.slug), {
            preserveScroll: true,
        });
}
const fieldError = (i: number, name: string): string | undefined =>
    (fieldsForm.errors as Record<string, string>)[`fields.${i}.${name}`];

// B-07: this client's names for the shared statuses (blank = default name).
const labelsForm = useForm<{ labels: Record<string, string> }>({
    labels: Object.fromEntries(
        (props.taskStatuses ?? []).map((s) => [String(s.id), s.label]),
    ),
});
function saveLabels(): void {
    labelsForm.put(statusLabelsUpdate.url(props.workspace.slug), {
        preserveScroll: true,
    });
}

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Workspaces', href: workspacesIndex() }],
    },
});

async function removeLabel(label: {
    id: number;
    name: string;
    uses: number;
}): Promise<void> {
    const note = label.uses ? ` It is used on ${label.uses} project(s).` : '';
    if (!(await confirmDialog(`Delete label "${label.name}"?${note}`))) {
        return;
    }

    router.delete(
        LabelController.destroy.url({
            workspace: props.workspace.slug,
            label: label.id,
        }),
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head :title="`${workspace.name} · Settings`" />

    <div class="flex max-w-4xl flex-1 flex-col gap-4 p-4">
        <WorkspaceHeader :workspace="workspace" />

        <Card v-if="workspace.can.own">
            <CardHeader>
                <CardTitle class="text-base">Client details</CardTitle>
                <CardDescription>
                    Set status to <strong>archived</strong> to make the
                    workspace read-only and hide it from the switcher. You can
                    restore it here.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Form
                    v-bind="WorkspaceController.update.form(workspace.slug)"
                    v-slot="{ errors, processing }"
                    class="grid gap-4 sm:grid-cols-2"
                    :options="{ preserveScroll: true }"
                >
                    <div class="grid gap-2">
                        <Label for="s-name">Client name</Label>
                        <Input
                            id="s-name"
                            name="name"
                            :default-value="details.name"
                            required
                        />
                        <InputError :message="errors.name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="s-industry">Industry</Label>
                        <Input
                            id="s-industry"
                            name="industry"
                            :default-value="details.industry ?? ''"
                        />
                        <InputError :message="errors.industry" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="s-website">Website</Label>
                        <Input
                            id="s-website"
                            name="website"
                            type="url"
                            :default-value="details.website ?? ''"
                        />
                        <InputError :message="errors.website" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="s-key">Task key</Label>
                        <Input
                            id="s-key"
                            name="key"
                            maxlength="6"
                            class="uppercase"
                            :default-value="details.key ?? ''"
                            placeholder="e.g. BRW"
                        />
                        <p class="text-xs text-muted-foreground">
                            Tasks show as {{ (details.key ?? 'KEY') + '-24' }}.
                            Changing it renames every task key; old short links
                            stop working.
                        </p>
                        <InputError :message="errors.key" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="s-contact">Primary contact (name)</Label>
                        <Input
                            id="s-contact"
                            name="primary_contact_name"
                            :default-value="details.primary_contact_name ?? ''"
                        />
                        <InputError :message="errors.primary_contact_name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="s-status">Status</Label>
                        <NativeSelect
                            id="s-status"
                            name="status"
                            :model-value="details.status"
                        >
                            <option v-for="s in statuses" :key="s" :value="s">
                                {{ s }}
                            </option>
                        </NativeSelect>
                        <InputError :message="errors.status" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="s-color">Colour</Label>
                        <NativeSelect
                            id="s-color"
                            name="color"
                            :model-value="details.color"
                        >
                            <option v-for="c in colors" :key="c" :value="c">
                                {{ c }}
                            </option>
                        </NativeSelect>
                    </div>
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="s-desc">Description</Label>
                        <Input
                            id="s-desc"
                            name="description"
                            :default-value="details.description ?? ''"
                        />
                        <InputError :message="errors.description" />
                    </div>
                    <div class="sm:col-span-2">
                        <Button type="submit" :disabled="processing"
                            >Save details</Button
                        >
                    </div>
                </Form>
            </CardContent>
        </Card>

        <Card v-if="workspace.can.lead">
            <CardHeader>
                <CardTitle class="text-base">Labels</CardTitle>
                <CardDescription
                    >Colour tags for projects and tasks in this
                    workspace.</CardDescription
                >
            </CardHeader>
            <CardContent class="space-y-4">
                <ul v-if="labels.length" class="flex flex-wrap gap-2">
                    <li
                        v-for="l in labels"
                        :key="l.id"
                        class="inline-flex items-center gap-1 rounded-full border py-0.5 pr-1 pl-0.5"
                    >
                        <LabelBadge :name="l.name" :color="l.color" />
                        <span class="text-xs text-muted-foreground">{{
                            l.uses
                        }}</span>
                        <button
                            type="button"
                            class="rounded-full p-0.5 text-muted-foreground hover:bg-accent"
                            :aria-label="`Delete label ${l.name}`"
                            @click="removeLabel(l)"
                        >
                            <Trash2 class="size-3.5" />
                        </button>
                    </li>
                </ul>
                <p v-else class="text-sm text-muted-foreground">
                    No labels yet.
                </p>

                <Form
                    v-bind="LabelController.store.form(workspace.slug)"
                    v-slot="{ errors, processing }"
                    class="flex flex-wrap items-end gap-2"
                    :options="{ preserveScroll: true }"
                    reset-on-success
                >
                    <div class="grid gap-1">
                        <Label for="l-name">New label</Label>
                        <Input
                            id="l-name"
                            name="name"
                            class="w-48"
                            placeholder="e.g. Urgent"
                            required
                        />
                    </div>
                    <NativeSelect
                        name="color"
                        model-value="slate"
                        class="w-32"
                        aria-label="Label colour"
                    >
                        <option v-for="c in colors" :key="c" :value="c">
                            {{ c }}
                        </option>
                    </NativeSelect>
                    <Button
                        type="submit"
                        variant="outline"
                        :disabled="processing"
                        >Add label</Button
                    >
                    <InputError class="w-full" :message="errors.name" />
                </Form>
            </CardContent>
        </Card>

        <Card v-if="workspace.can.lead">
            <CardHeader>
                <CardTitle class="text-base">Task fields</CardTitle>
                <CardDescription>
                    Extra fields on every task for this client (up to
                    {{ maxFields ?? 12 }}), e.g. "Ad budget", "Approval link",
                    "Store branch". Renaming keeps saved values; removing a
                    field hides it.
                </CardDescription>
            </CardHeader>
            <CardContent class="space-y-3 text-sm">
                <p
                    v-if="fieldsForm.rows.length === 0"
                    class="text-muted-foreground"
                >
                    No custom fields yet.
                </p>
                <div
                    v-for="(row, i) in fieldsForm.rows"
                    :key="row.key ?? `new-${i}`"
                    class="grid gap-2 rounded-md border p-2 sm:grid-cols-[1fr_10rem_auto]"
                >
                    <div class="grid gap-1">
                        <Input
                            v-model="row.label"
                            maxlength="40"
                            placeholder="Field name"
                            :aria-label="`Field ${i + 1} name`"
                        />
                        <InputError :message="fieldError(i, 'label')" />
                    </div>
                    <NativeSelect
                        v-model="row.type"
                        :aria-label="`Field ${i + 1} type`"
                    >
                        <option
                            v-for="(label, key) in fieldTypes ?? {}"
                            :key="key"
                            :value="key"
                        >
                            {{ label }}
                        </option>
                    </NativeSelect>
                    <Button
                        type="button"
                        size="icon"
                        variant="ghost"
                        :aria-label="`Remove ${row.label || 'field'}`"
                        @click="fieldsForm.rows.splice(i, 1)"
                        ><Trash2 class="size-4"
                    /></Button>
                    <div
                        v-if="row.type === 'select'"
                        class="grid gap-1 sm:col-span-3"
                    >
                        <textarea
                            v-model="row.optionsText"
                            rows="3"
                            class="rounded-md border bg-transparent px-2 py-1 text-sm"
                            placeholder="One option per line"
                            :aria-label="`Options for ${row.label || 'field'}`"
                        />
                        <InputError :message="fieldError(i, 'options')" />
                    </div>
                </div>
                <InputError
                    :message="
                        (fieldsForm.errors as Record<string, string>).fields
                    "
                />
                <div class="flex flex-wrap gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="fieldsForm.rows.length >= (maxFields ?? 12)"
                        @click="addField"
                        >Add field</Button
                    >
                    <Button
                        type="button"
                        size="sm"
                        :disabled="fieldsForm.processing"
                        @click="saveFields"
                        >Save fields</Button
                    >
                </div>
            </CardContent>
        </Card>

        <Card v-if="workspace.can.lead && taskStatuses?.length">
            <CardHeader>
                <CardTitle class="text-base">Status names</CardTitle>
                <CardDescription
                    >Use this client's words on the board and task pages, e.g.
                    "In Review" → "With client". Leave blank for the
                    default.</CardDescription
                >
            </CardHeader>
            <CardContent>
                <form
                    class="grid gap-2 text-sm sm:grid-cols-2"
                    @submit.prevent="saveLabels"
                >
                    <label
                        v-for="s in taskStatuses"
                        :key="s.id"
                        class="grid gap-1"
                    >
                        <span class="text-xs text-muted-foreground">{{
                            s.name
                        }}</span>
                        <Input
                            v-model="labelsForm.labels[String(s.id)]"
                            maxlength="30"
                            :placeholder="s.name"
                        />
                    </label>
                    <div class="sm:col-span-2">
                        <Button
                            type="submit"
                            size="sm"
                            :disabled="labelsForm.processing"
                            >Save status names</Button
                        >
                    </div>
                </form>
            </CardContent>
        </Card>
    </div>
</template>
