<script setup lang="ts">
import DeliverySpecsCard from '@/components/workspaces/DeliverySpecsCard.vue';
import IntegrationsCard from '@/components/workspaces/IntegrationsCard.vue';
import type {
    ChatChannelRow,
    RepositoryRow,
} from '@/components/workspaces/IntegrationsCard.vue';
import type { DeliverySpecRow } from '@/components/workspaces/DeliverySpecsCard.vue';
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
import { update as checklistsUpdate } from '@/routes/workspaces/checklists';
import {
    destroy as destroyRule,
    store as storeRule,
} from '@/routes/workspaces/review-rules';
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
    checklistTemplates?: Record<string, string>;
    taskTypes?: Record<string, string>;
    // E-25 / E-34
    checklistDefaults?: Record<string, string>;
    // E-24 / E-41
    repositories?: RepositoryRow[];
    githubWebhook?: { url: string; configured: boolean };
    chatChannels?: ChatChannelRow[];
    chatEvents?: Record<string, string>;
    // E-18
    deliverySpecs?: DeliverySpecRow[];
    channelOptions?: string[];
    reviewRules?: {
        id: number;
        project: string | null;
        deliverable_type: string | null;
        task_type: string | null;
        mode: string;
        reviewers: string[];
    }[];
    reviewModes?: Record<string, string>;
    deliverableTypes?: string[];
    projectOptions?: { id: number; name: string }[];
    memberOptions?: { id: number; name: string; title: string | null }[];
}>();

// E-34: default reviewers when work goes to review with nobody named.
const ruleForm = useForm<{
    project_id: string;
    deliverable_type: string;
    task_type: string;
    user_ids: number[];
    mode: string;
}>({
    project_id: '',
    deliverable_type: '',
    task_type: '',
    user_ids: [],
    mode: 'all',
});

function toggleReviewer(id: number): void {
    ruleForm.user_ids = ruleForm.user_ids.includes(id)
        ? ruleForm.user_ids.filter((u) => u !== id)
        : [...ruleForm.user_ids, id];
}

function addRule(): void {
    ruleForm
        .transform((d) => ({
            ...d,
            project_id: d.project_id === '' ? null : Number(d.project_id),
            deliverable_type: d.deliverable_type || null,
            task_type: d.task_type || null,
        }))
        .post(storeRule.url(props.workspace.slug), {
            preserveScroll: true,
            onSuccess: () => ruleForm.reset(),
        });
}

async function removeRule(id: number): Promise<void> {
    if (
        await confirmDialog(
            'Remove this review rule? Tasks already in review keep their reviewers.',
        )
    ) {
        router.delete(
            destroyRule.url({
                workspace: props.workspace.slug,
                reviewRule: id,
            }),
            { preserveScroll: true },
        );
    }
}

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

// E-36: checklist lines added to new tasks of each type (one line per row).
const checklistForm = useForm<{ templates: Record<string, string> }>({
    templates: { ...props.checklistTemplates },
});
function saveChecklists(): void {
    checklistForm.put(checklistsUpdate.url(props.workspace.slug), {
        preserveScroll: true,
    });
}

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
        <Card v-if="workspace.can.lead && taskTypes">
            <CardHeader>
                <CardTitle class="text-base">Checklists</CardTitle>
                <CardDescription
                    >Lines added to every new task of that type, one per row.
                    Each line must be ticked before Done (members can mark extra
                    lines optional).</CardDescription
                >
            </CardHeader>
            <CardContent>
                <form
                    class="grid gap-3 text-sm sm:grid-cols-2"
                    @submit.prevent="saveChecklists"
                >
                    <label
                        v-for="(label, type) in taskTypes"
                        :key="type"
                        class="grid gap-1"
                    >
                        <span class="text-xs text-muted-foreground">{{
                            label
                        }}</span>
                        <textarea
                            v-model="checklistForm.templates[type]"
                            rows="4"
                            class="rounded-md border bg-transparent px-2 py-1 text-sm"
                            :placeholder="
                                checklistDefaults?.[type]
                                    ? `Empty = agency default:\n${checklistDefaults[type]}`
                                    : ''
                            "
                        />
                    </label>
                    <div class="sm:col-span-2">
                        <Button
                            type="submit"
                            size="sm"
                            :disabled="checklistForm.processing"
                            >Save checklists</Button
                        >
                    </div>
                </form>
            </CardContent>
        </Card>

        <!-- E-34: review rules -->
        <Card v-if="workspace.can.lead && reviewRules">
            <CardHeader>
                <CardTitle class="text-base">Review rules</CardTitle>
                <CardDescription
                    >Who reviews by default when work goes to In Review with
                    nobody named. The most specific rule wins (project, then
                    deliverable, then task type). The person submitting can
                    still change the reviewers. Changes apply to the next
                    submissions.</CardDescription
                >
            </CardHeader>
            <CardContent class="space-y-4 text-sm">
                <p
                    v-if="reviewRules.length === 0"
                    class="text-muted-foreground"
                >
                    No rules yet.
                </p>
                <ul v-else class="divide-y rounded-md border">
                    <li
                        v-for="r in reviewRules"
                        :key="r.id"
                        class="flex items-start justify-between gap-2 p-2"
                    >
                        <span>
                            <strong>{{
                                [
                                    r.project,
                                    r.deliverable_type,
                                    r.task_type
                                        ? (taskTypes?.[r.task_type] ??
                                          r.task_type)
                                        : null,
                                ]
                                    .filter(Boolean)
                                    .join(' · ') || 'All work'
                            }}</strong>
                            → {{ r.reviewers.join(', ') }}
                            <span class="text-xs text-muted-foreground"
                                >({{ reviewModes?.[r.mode] ?? r.mode }})</span
                            >
                        </span>
                        <Button
                            variant="ghost"
                            size="icon"
                            :aria-label="`Remove rule ${r.id}`"
                            @click="removeRule(r.id)"
                            ><Trash2 class="size-4"
                        /></Button>
                    </li>
                </ul>
                <form
                    class="grid gap-2 rounded-md border p-3 sm:grid-cols-4"
                    @submit.prevent="addRule"
                >
                    <NativeSelect
                        v-model="ruleForm.project_id"
                        aria-label="Project"
                    >
                        <option value="">Any project</option>
                        <option
                            v-for="p in projectOptions"
                            :key="p.id"
                            :value="String(p.id)"
                        >
                            {{ p.name }}
                        </option>
                    </NativeSelect>
                    <NativeSelect
                        v-model="ruleForm.deliverable_type"
                        aria-label="Deliverable"
                    >
                        <option value="">Any deliverable</option>
                        <option
                            v-for="d in deliverableTypes"
                            :key="d"
                            :value="d"
                        >
                            {{ d }}
                        </option>
                    </NativeSelect>
                    <NativeSelect
                        v-model="ruleForm.task_type"
                        aria-label="Task type"
                    >
                        <option value="">Any task type</option>
                        <option
                            v-for="(label, type) in taskTypes"
                            :key="type"
                            :value="type"
                        >
                            {{ label }}
                        </option>
                    </NativeSelect>
                    <NativeSelect
                        v-model="ruleForm.mode"
                        aria-label="How they review"
                    >
                        <option
                            v-for="(label, key) in reviewModes"
                            :key="key"
                            :value="key"
                        >
                            {{ label }}
                        </option>
                    </NativeSelect>
                    <fieldset class="sm:col-span-4">
                        <legend class="mb-1 text-xs text-muted-foreground">
                            Reviewers
                        </legend>
                        <div class="flex flex-wrap gap-x-4 gap-y-1">
                            <label
                                v-for="m in memberOptions"
                                :key="m.id"
                                class="flex items-center gap-1.5"
                            >
                                <input
                                    type="checkbox"
                                    :checked="ruleForm.user_ids.includes(m.id)"
                                    @change="toggleReviewer(m.id)"
                                />
                                {{ m.name }}
                            </label>
                        </div>
                        <InputError :message="ruleForm.errors.user_ids" />
                    </fieldset>
                    <div class="sm:col-span-4">
                        <Button
                            type="submit"
                            size="sm"
                            :disabled="
                                ruleForm.processing ||
                                ruleForm.user_ids.length === 0
                            "
                            >Add rule</Button
                        >
                    </div>
                </form>
            </CardContent>
        </Card>

        <DeliverySpecsCard
            v-if="workspace.can.lead && deliverySpecs"
            :workspace-slug="workspace.slug"
            :specs="deliverySpecs"
            :channels="channelOptions ?? []"
            :deliverables="deliverableTypes ?? []"
        />

        <IntegrationsCard
            v-if="
                workspace.can.lead &&
                repositories &&
                githubWebhook &&
                chatChannels &&
                chatEvents
            "
            :workspace-slug="workspace.slug"
            :task-key="details.key"
            :repositories="repositories"
            :webhook="githubWebhook"
            :channels="chatChannels"
            :events="chatEvents"
        />
    </div>
</template>
