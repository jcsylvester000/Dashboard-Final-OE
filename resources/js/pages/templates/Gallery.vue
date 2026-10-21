<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    Copy,
    FileText,
    ListChecks,
    ClipboardList,
    BarChart3,
} from '@lucide/vue';
import { ref } from 'vue';
import TemplateGalleryController from '@/actions/App/Http/Controllers/Work/TemplateGalleryController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { timeAgo } from '@/lib/format';
import { index as galleryIndex } from '@/routes/gallery';

/**
 * E-55: every reusable template in one place: workflows, document templates (agency),
 * forms and report packs (per client). Duplicate, archive, see how often each is used.
 */
type Doc = {
    id: number;
    name: string;
    kind: string;
    body: string;
    version: number;
    archived: boolean;
    uses: number;
    updated_at: string | null;
};

const props = defineProps<{
    canManage: boolean;
    workflows: {
        id: number;
        name: string;
        description: string | null;
        active: boolean;
        uses: number;
        steps: number;
    }[];
    documents: Doc[];
    forms: {
        id: number;
        name: string;
        client: string;
        archived: boolean;
        uses: number;
        published: boolean;
    }[];
    packs: {
        id: number;
        name: string;
        client: string;
        enabled: boolean;
        uses: number;
        frequency: string;
    }[];
    clients: { id: number; name: string; slug: string }[];
    documentKinds: Record<string, string>;
    mergeFields: Record<string, string>;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Templates', href: galleryIndex() }] },
});

const opts = { preserveScroll: true };
const editing = ref<number | 'new' | null>(null);
const doc = useForm({ name: '', kind: 'document', body: '' });
function editDoc(d: Doc | null): void {
    editing.value = d ? d.id : 'new';
    doc.name = d?.name ?? '';
    doc.kind = d?.kind ?? 'proposal';
    doc.body =
        d?.body ??
        '# Proposal for {{client.name}}\n\nPrepared by {{prepared_by}} on {{today}}.\n\n## Project\n{{project.name}}: {{project.description}}\n\nDue {{project.due_on}}.\n\n## Open work\n{{tasks_table}}\n';
}
function saveDoc(): void {
    const done = { ...opts, onSuccess: () => (editing.value = null) };
    if (editing.value === 'new') {
        doc.post(TemplateGalleryController.storeDocument.url(), done);
    } else if (typeof editing.value === 'number') {
        doc.put(
            TemplateGalleryController.updateDocument.url(editing.value),
            done,
        );
    }
}
function insert(field: string): void {
    doc.body += `{{${field}}}`;
}
const copyTarget = ref<Record<string, number | ''>>({});
function copyTo(kind: 'form' | 'pack', id: number): void {
    const target = copyTarget.value[`${kind}${id}`];
    if (!target) {
        return;
    }
    router.post(
        kind === 'form'
            ? TemplateGalleryController.duplicateForm.url(id)
            : TemplateGalleryController.duplicatePack.url(id),
        { workspace_id: target },
        opts,
    );
}
</script>

<template>
    <Head title="Templates" />
    <div class="flex max-w-5xl flex-1 flex-col gap-4 p-4">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Templates</h1>
            <p class="text-sm text-muted-foreground">
                Everything reusable in one place. Duplicate one to start a new
                client fast.
            </p>
        </div>

        <Card>
            <CardHeader>
                <CardTitle class="flex items-center gap-2 text-base"
                    ><ListChecks class="size-4" /> Workflows</CardTitle
                >
                <CardDescription
                    >Cross-department task chains (edit them under Admin >
                    Workflow templates).</CardDescription
                >
            </CardHeader>
            <CardContent>
                <ul class="divide-y rounded border text-sm">
                    <li
                        v-for="w in workflows"
                        :key="w.id"
                        class="flex flex-wrap items-center gap-2 px-3 py-2"
                        :class="w.active ? '' : 'opacity-60'"
                    >
                        <span class="min-w-0 flex-1"
                            ><span class="font-medium">{{ w.name }}</span
                            ><span class="text-xs text-muted-foreground">
                                · {{ w.steps }} steps · used {{ w.uses }}×{{
                                    w.active ? '' : ' · archived'
                                }}</span
                            ></span
                        >
                        <template v-if="canManage">
                            <Button
                                size="sm"
                                variant="ghost"
                                @click="
                                    router.post(
                                        TemplateGalleryController.duplicateWorkflow.url(
                                            w.id,
                                        ),
                                        {},
                                        opts,
                                    )
                                "
                                ><Copy class="size-3.5" /> Duplicate</Button
                            >
                            <Button
                                size="sm"
                                variant="ghost"
                                @click="
                                    router.post(
                                        TemplateGalleryController.archiveWorkflow.url(
                                            w.id,
                                        ),
                                        {},
                                        opts,
                                    )
                                "
                                >{{ w.active ? 'Archive' : 'Restore' }}</Button
                            >
                        </template>
                    </li>
                </ul>
            </CardContent>
        </Card>

        <Card>
            <CardHeader
                class="flex flex-row flex-wrap items-start justify-between gap-2"
            >
                <div>
                    <CardTitle class="flex items-center gap-2 text-base"
                        ><FileText class="size-4" /> Document
                        templates</CardTitle
                    >
                    <CardDescription
                        >Proposals, SOWs, briefs and reports with merge fields.
                        Make a PDF from a project page.</CardDescription
                    >
                </div>
                <Button
                    v-if="canManage && editing === null"
                    size="sm"
                    @click="editDoc(null)"
                    >New document template</Button
                >
            </CardHeader>
            <CardContent class="space-y-3 text-sm">
                <div
                    v-if="editing !== null"
                    class="grid gap-2 rounded border p-3"
                >
                    <div class="flex flex-wrap gap-2">
                        <Input
                            v-model="doc.name"
                            class="w-72"
                            placeholder="Template name"
                            maxlength="120"
                            aria-label="Template name"
                        />
                        <select
                            v-model="doc.kind"
                            class="h-9 rounded-md border bg-background px-2"
                            aria-label="Kind"
                        >
                            <option
                                v-for="(label, k) in documentKinds"
                                :key="k"
                                :value="k"
                            >
                                {{ label }}
                            </option>
                        </select>
                    </div>
                    <textarea
                        v-model="doc.body"
                        rows="14"
                        class="rounded-md border bg-background p-2 font-mono text-xs"
                        aria-label="Template text (Markdown)"
                    />
                    <div class="flex flex-wrap gap-1 text-xs">
                        <span class="text-muted-foreground">Insert:</span>
                        <button
                            v-for="(label, f) in mergeFields"
                            :key="f"
                            type="button"
                            class="rounded border px-1.5 hover:bg-muted"
                            :title="label"
                            @click="insert(String(f))"
                        >
                            {{ f }}
                        </button>
                    </div>
                    <div class="flex gap-2">
                        <Button
                            size="sm"
                            :disabled="doc.processing || !doc.name.trim()"
                            @click="saveDoc"
                            >Save{{
                                typeof editing === 'number'
                                    ? ' (new version)'
                                    : ''
                            }}</Button
                        >
                        <Button
                            size="sm"
                            variant="ghost"
                            @click="editing = null"
                            >Cancel</Button
                        >
                    </div>
                    <InputError :message="doc.errors.body ?? doc.errors.name" />
                </div>
                <ul class="divide-y rounded border">
                    <li
                        v-for="d in documents"
                        :key="d.id"
                        class="flex flex-wrap items-center gap-2 px-3 py-2"
                        :class="d.archived ? 'opacity-60' : ''"
                    >
                        <span class="min-w-0 flex-1"
                            ><span class="font-medium">{{ d.name }}</span
                            ><span class="text-xs text-muted-foreground">
                                · {{ documentKinds[d.kind] }} · v{{
                                    d.version
                                }}
                                · used {{ d.uses }}×<template
                                    v-if="d.updated_at"
                                >
                                    · edited
                                    {{ timeAgo(d.updated_at) }}</template
                                >{{ d.archived ? ' · archived' : '' }}</span
                            ></span
                        >
                        <template v-if="canManage">
                            <Button
                                size="sm"
                                variant="ghost"
                                @click="editDoc(d)"
                                >Edit</Button
                            >
                            <Button
                                size="sm"
                                variant="ghost"
                                @click="
                                    router.post(
                                        TemplateGalleryController.duplicateDocument.url(
                                            d.id,
                                        ),
                                        {},
                                        opts,
                                    )
                                "
                                ><Copy class="size-3.5" /> Duplicate</Button
                            >
                            <Button
                                size="sm"
                                variant="ghost"
                                @click="
                                    router.post(
                                        TemplateGalleryController.archiveDocument.url(
                                            d.id,
                                        ),
                                        {},
                                        opts,
                                    )
                                "
                                >{{
                                    d.archived ? 'Restore' : 'Archive'
                                }}</Button
                            >
                        </template>
                    </li>
                    <li
                        v-if="!documents.length"
                        class="px-3 py-2 text-muted-foreground"
                    >
                        No document templates yet.
                    </li>
                </ul>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle class="flex items-center gap-2 text-base"
                    ><ClipboardList class="size-4" /> Forms (clients you
                    lead)</CardTitle
                >
                <CardDescription
                    >Copy a form to another client as a draft; client field
                    mappings are cleared there.</CardDescription
                >
            </CardHeader>
            <CardContent>
                <ul class="divide-y rounded border text-sm">
                    <li
                        v-for="f in forms"
                        :key="f.id"
                        class="flex flex-wrap items-center gap-2 px-3 py-2"
                        :class="f.archived ? 'opacity-60' : ''"
                    >
                        <span class="min-w-0 flex-1"
                            ><span class="font-medium">{{ f.name }}</span
                            ><span class="text-xs text-muted-foreground">
                                · {{ f.client }} · {{ f.uses }} submissions{{
                                    f.published ? '' : ' · draft'
                                }}</span
                            ></span
                        >
                        <select
                            v-model="copyTarget[`form${f.id}`]"
                            class="h-8 rounded border bg-background px-2 text-xs"
                            aria-label="Copy to client"
                        >
                            <option value="">Copy to…</option>
                            <option
                                v-for="c in clients"
                                :key="c.id"
                                :value="c.id"
                            >
                                {{ c.name }}
                            </option>
                        </select>
                        <Button
                            size="sm"
                            variant="ghost"
                            :disabled="!copyTarget[`form${f.id}`]"
                            @click="copyTo('form', f.id)"
                            ><Copy class="size-3.5" /> Duplicate</Button
                        >
                    </li>
                    <li
                        v-if="!forms.length"
                        class="px-3 py-2 text-muted-foreground"
                    >
                        No forms on clients you lead.
                    </li>
                </ul>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle class="flex items-center gap-2 text-base"
                    ><BarChart3 class="size-4" /> Report packs (clients you
                    lead)</CardTitle
                >
                <CardDescription
                    >Copies start switched off so nothing is sent before you
                    check it.</CardDescription
                >
            </CardHeader>
            <CardContent>
                <ul class="divide-y rounded border text-sm">
                    <li
                        v-for="p in packs"
                        :key="p.id"
                        class="flex flex-wrap items-center gap-2 px-3 py-2"
                        :class="p.enabled ? '' : 'opacity-60'"
                    >
                        <span class="min-w-0 flex-1"
                            ><span class="font-medium">{{ p.name }}</span
                            ><span class="text-xs text-muted-foreground">
                                · {{ p.client }} · {{ p.frequency }} · made
                                {{ p.uses }}×</span
                            ></span
                        >
                        <select
                            v-model="copyTarget[`pack${p.id}`]"
                            class="h-8 rounded border bg-background px-2 text-xs"
                            aria-label="Copy to client"
                        >
                            <option value="">Copy to…</option>
                            <option
                                v-for="c in clients"
                                :key="c.id"
                                :value="c.id"
                            >
                                {{ c.name }}
                            </option>
                        </select>
                        <Button
                            size="sm"
                            variant="ghost"
                            :disabled="!copyTarget[`pack${p.id}`]"
                            @click="copyTo('pack', p.id)"
                            ><Copy class="size-3.5" /> Duplicate</Button
                        >
                    </li>
                    <li
                        v-if="!packs.length"
                        class="px-3 py-2 text-muted-foreground"
                    >
                        No report packs on clients you lead.
                    </li>
                </ul>
            </CardContent>
        </Card>
    </div>
</template>
