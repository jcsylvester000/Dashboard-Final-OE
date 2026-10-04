<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { FolderKanban, Plus, Users, X } from '@lucide/vue';
import { ref } from 'vue';
import WorkspaceController from '@/actions/App/Http/Controllers/Workspaces/WorkspaceController';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { departmentDot, roleLabel } from '@/lib/format';
import { index, show } from '@/routes/workspaces';

type Row = {
    id: number;
    name: string;
    slug: string;
    industry: string | null;
    status: string;
    color: string;
    members: number;
    openProjects: number;
    myRole: string | null;
};

defineProps<{
    workspaces: Row[];
    showArchived: boolean;
    canCreate: boolean;
    colors: string[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Workspaces', href: index() }],
    },
});

const creating = ref(false);
</script>

<template>
    <Head title="Workspaces" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">
                    Client workspaces
                </h1>
                <p class="text-sm text-muted-foreground">
                    One workspace per client. You see the ones you belong to.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <Link
                    :href="index({ query: showArchived ? {} : { archived: '1' } })"
                    class="text-sm text-muted-foreground underline underline-offset-4"
                >
                    {{ showArchived ? 'Hide archived' : 'Show archived' }}
                </Link>
                <Button v-if="canCreate && !creating" @click="creating = true">
                    <Plus /> New workspace
                </Button>
            </div>
        </div>

        <Card v-if="creating">
            <CardHeader>
                <CardTitle class="text-base">New client workspace</CardTitle>
            </CardHeader>
            <CardContent>
                <Form
                    v-bind="WorkspaceController.store.form()"
                    v-slot="{ errors, processing }"
                    class="grid gap-4 sm:grid-cols-2"
                >
                    <div class="grid gap-2">
                        <Label for="ws-name">Client name</Label>
                        <Input id="ws-name" name="name" required autofocus />
                        <InputError :message="errors.name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="ws-industry">Industry</Label>
                        <Input id="ws-industry" name="industry" placeholder="e.g. Real estate" />
                        <InputError :message="errors.industry" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="ws-website">Website</Label>
                        <Input id="ws-website" name="website" type="url" placeholder="https://" />
                        <InputError :message="errors.website" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="ws-color">Colour</Label>
                        <NativeSelect id="ws-color" name="color" model-value="blue">
                            <option v-for="c in colors" :key="c" :value="c">{{ c }}</option>
                        </NativeSelect>
                    </div>
                    <div class="grid gap-2">
                        <Label for="ws-contact">Primary contact (name)</Label>
                        <Input id="ws-contact" name="primary_contact_name" />
                        <InputError :message="errors.primary_contact_name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="ws-desc">Short description</Label>
                        <Input id="ws-desc" name="description" />
                        <InputError :message="errors.description" />
                    </div>
                    <div class="flex gap-2 sm:col-span-2">
                        <Button type="submit" :disabled="processing">
                            <Spinner v-if="processing" /> Create workspace
                        </Button>
                        <Button type="button" variant="ghost" @click="creating = false">
                            <X /> Cancel
                        </Button>
                    </div>
                </Form>
            </CardContent>
        </Card>

        <p
            v-if="workspaces.length === 0"
            class="rounded-lg border p-8 text-center text-sm text-muted-foreground"
        >
            No workspaces yet.
            <template v-if="!canCreate">Ask an admin to add you to a client workspace.</template>
        </p>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <Link
                v-for="w in workspaces"
                :key="w.id"
                :href="show(w.slug)"
                class="rounded-xl border p-4 transition hover:border-primary/40 hover:shadow-sm"
            >
                <div class="flex items-start justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <span
                            class="size-3 rounded-full"
                            :class="departmentDot[w.color] ?? 'bg-slate-500'"
                        />
                        <span class="font-medium">{{ w.name }}</span>
                    </div>
                    <Badge v-if="w.status !== 'active'" variant="outline">{{ w.status }}</Badge>
                </div>
                <p class="mt-1 text-xs text-muted-foreground">
                    {{ w.industry || 'No industry set' }}
                    <template v-if="w.myRole"> · {{ roleLabel(w.myRole) }}</template>
                </p>
                <div class="mt-4 flex gap-4 text-sm text-muted-foreground">
                    <span class="inline-flex items-center gap-1">
                        <FolderKanban class="size-4" /> {{ w.openProjects }} open
                    </span>
                    <span class="inline-flex items-center gap-1">
                        <Users class="size-4" /> {{ w.members }}
                    </span>
                </div>
            </Link>
        </div>
    </div>
</template>
