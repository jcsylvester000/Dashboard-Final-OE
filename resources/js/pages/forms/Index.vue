<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import FormController from '@/actions/App/Http/Controllers/Workspaces/FormController';
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
import WorkspaceHeader from '@/components/WorkspaceHeader.vue';
import { timeAgo } from '@/lib/format';
import type { WorkspaceHeader as Header } from '@/types/workspace';

/**
 * E-53 / E-42: a client's forms: intake, QA, feedback. Leads build and publish; anyone
 * on the client can fill one in and read the submissions.
 */
const props = defineProps<{
    workspace: Header;
    forms: {
        id: number;
        name: string;
        description: string | null;
        archived: boolean;
        version: number | null;
        published_at: string | null;
        submissions: number;
        public_url: string | null;
    }[];
    canBuild: boolean;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Forms', href: '#' }] } });

const args = (id: number) => ({ workspace: props.workspace.slug, form: id });
const create = useForm({ name: '' });
const copied = ref<number | null>(null);

function add(): void {
    create.post(FormController.store.url({ workspace: props.workspace.slug }));
}
async function copy(id: number, url: string): Promise<void> {
    await navigator.clipboard.writeText(url);
    copied.value = id;
    setTimeout(() => (copied.value = null), 1500);
}
</script>

<template>
    <Head :title="`Forms · ${workspace.name}`" />
    <div class="flex flex-1 flex-col gap-4 p-4">
        <WorkspaceHeader :workspace="workspace" />
        <Card>
            <CardHeader>
                <CardTitle class="text-base">Forms</CardTitle>
                <CardDescription>
                    Intake, QA and feedback forms for this client. A submission
                    can create a task with the answers mapped to its fields.
                    Clients can use a public link (no login).
                </CardDescription>
            </CardHeader>
            <CardContent class="space-y-4 text-sm">
                <form
                    v-if="canBuild"
                    class="flex flex-wrap gap-2"
                    @submit.prevent="add"
                >
                    <Input
                        v-model="create.name"
                        class="w-72"
                        placeholder="New form name (e.g. Creative request)"
                        maxlength="120"
                        aria-label="New form name"
                    />
                    <Button
                        type="submit"
                        :disabled="create.processing || !create.name.trim()"
                        >Create form</Button
                    >
                    <InputError :message="create.errors.name" class="w-full" />
                </form>
                <p v-if="!forms.length" class="text-muted-foreground">
                    No forms yet.
                </p>
                <ul class="divide-y rounded border">
                    <li
                        v-for="f in forms"
                        :key="f.id"
                        class="flex flex-wrap items-center gap-3 px-3 py-2"
                        :class="f.archived ? 'opacity-60' : ''"
                    >
                        <div class="min-w-0 flex-1">
                            <p class="font-medium">
                                {{ f.name }}
                                <span v-if="f.archived" class="text-xs"
                                    >(archived)</span
                                >
                            </p>
                            <p class="text-xs text-muted-foreground">
                                <template v-if="f.version"
                                    >v{{ f.version }} · published
                                    {{ timeAgo(f.published_at) }}</template
                                >
                                <template v-else>Draft, not published</template>
                                · {{ f.submissions }} submission{{
                                    f.submissions === 1 ? '' : 's'
                                }}
                            </p>
                        </div>
                        <button
                            v-if="f.public_url"
                            type="button"
                            class="text-xs underline"
                            @click="copy(f.id, f.public_url)"
                        >
                            {{
                                copied === f.id ? 'Copied' : 'Copy client link'
                            }}
                        </button>
                        <Link
                            v-if="f.version && !f.archived"
                            :href="FormController.fill.url(args(f.id))"
                            class="text-xs underline"
                            >Fill in</Link
                        >
                        <Link
                            :href="FormController.submissions.url(args(f.id))"
                            class="text-xs underline"
                            >Submissions</Link
                        >
                        <Link
                            v-if="canBuild"
                            :href="FormController.edit.url(args(f.id))"
                            class="text-xs underline"
                            >Edit</Link
                        >
                        <button
                            v-if="canBuild"
                            type="button"
                            class="text-xs underline"
                            @click="
                                router.post(
                                    FormController.archive.url(args(f.id)),
                                    {},
                                    { preserveScroll: true },
                                )
                            "
                        >
                            {{ f.archived ? 'Restore' : 'Archive' }}
                        </button>
                    </li>
                </ul>
            </CardContent>
        </Card>
    </div>
</template>
