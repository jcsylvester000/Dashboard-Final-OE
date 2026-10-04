<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import { Trash2 } from '@lucide/vue';
import LabelController from '@/actions/App/Http/Controllers/Workspaces/LabelController';
import WorkspaceController from '@/actions/App/Http/Controllers/Workspaces/WorkspaceController';
import InputError from '@/components/InputError.vue';
import LabelBadge from '@/components/LabelBadge.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import WorkspaceHeader from '@/components/WorkspaceHeader.vue';
import { index as workspacesIndex } from '@/routes/workspaces';
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
    };
    labels: { id: number; name: string; color: string; uses: number }[];
    colors: string[];
    statuses: string[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Workspaces', href: workspacesIndex() }],
    },
});

function removeLabel(label: { id: number; name: string; uses: number }): void {
    const note = label.uses ? ` It is used on ${label.uses} project(s).` : '';
    if (!window.confirm(`Delete label "${label.name}"?${note}`)) {
        return;
    }

    router.delete(
        LabelController.destroy.url({ workspace: props.workspace.slug, label: label.id }),
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
                    Set status to <strong>archived</strong> to make the workspace read-only and hide it
                    from the switcher. You can restore it here.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Form
                    v-bind="WorkspaceController.update.form(workspace.slug)"
                    v-slot="{ errors, processing }"
                    class="grid gap-4 sm:grid-cols-2"
                    preserve-scroll
                >
                    <div class="grid gap-2">
                        <Label for="s-name">Client name</Label>
                        <Input id="s-name" name="name" :default-value="details.name" required />
                        <InputError :message="errors.name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="s-industry">Industry</Label>
                        <Input id="s-industry" name="industry" :default-value="details.industry ?? ''" />
                        <InputError :message="errors.industry" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="s-website">Website</Label>
                        <Input id="s-website" name="website" type="url" :default-value="details.website ?? ''" />
                        <InputError :message="errors.website" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="s-contact">Primary contact (name)</Label>
                        <Input id="s-contact" name="primary_contact_name" :default-value="details.primary_contact_name ?? ''" />
                        <InputError :message="errors.primary_contact_name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="s-status">Status</Label>
                        <NativeSelect id="s-status" name="status" :model-value="details.status">
                            <option v-for="s in statuses" :key="s" :value="s">{{ s }}</option>
                        </NativeSelect>
                        <InputError :message="errors.status" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="s-color">Colour</Label>
                        <NativeSelect id="s-color" name="color" :model-value="details.color">
                            <option v-for="c in colors" :key="c" :value="c">{{ c }}</option>
                        </NativeSelect>
                    </div>
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="s-desc">Description</Label>
                        <Input id="s-desc" name="description" :default-value="details.description ?? ''" />
                        <InputError :message="errors.description" />
                    </div>
                    <div class="sm:col-span-2">
                        <Button type="submit" :disabled="processing">Save details</Button>
                    </div>
                </Form>
            </CardContent>
        </Card>

        <Card v-if="workspace.can.lead">
            <CardHeader>
                <CardTitle class="text-base">Labels</CardTitle>
                <CardDescription>Colour tags for projects (and tasks, from P3) in this workspace.</CardDescription>
            </CardHeader>
            <CardContent class="space-y-4">
                <ul v-if="labels.length" class="flex flex-wrap gap-2">
                    <li
                        v-for="l in labels"
                        :key="l.id"
                        class="inline-flex items-center gap-1 rounded-full border py-0.5 pr-1 pl-0.5"
                    >
                        <LabelBadge :name="l.name" :color="l.color" />
                        <span class="text-xs text-muted-foreground">{{ l.uses }}</span>
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
                <p v-else class="text-sm text-muted-foreground">No labels yet.</p>

                <Form
                    v-bind="LabelController.store.form(workspace.slug)"
                    v-slot="{ errors, processing }"
                    class="flex flex-wrap items-end gap-2"
                    preserve-scroll
                    reset-on-success
                >
                    <div class="grid gap-1">
                        <Label for="l-name">New label</Label>
                        <Input id="l-name" name="name" class="w-48" placeholder="e.g. Urgent" required />
                    </div>
                    <NativeSelect name="color" model-value="slate" class="w-32" aria-label="Label colour">
                        <option v-for="c in colors" :key="c" :value="c">{{ c }}</option>
                    </NativeSelect>
                    <Button type="submit" variant="outline" :disabled="processing">Add label</Button>
                    <InputError class="w-full" :message="errors.name" />
                </Form>
            </CardContent>
        </Card>
    </div>
</template>
