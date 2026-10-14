<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import RichText from '@/components/RichText.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import WorkspaceHeader from '@/components/WorkspaceHeader.vue';
import { confirmDialog } from '@/composables/useConfirm';
import { index as sprintsIndex } from '@/routes/workspaces/sprints';
import {
    pdf,
    redraft,
    share,
    unshare,
    update,
} from '@/routes/workspaces/sprints/release-notes';
import type { WorkspaceHeader as Header } from '@/types/workspace';

/**
 * E-27: release notes drafted from the sprint's Done tasks; edit, download, share.
 */
const props = defineProps<{
    workspace: Header;
    sprint: {
        id: number;
        name: string;
        status: string;
        closed_at: string | null;
    };
    notes: string;
    html: string;
    saved: boolean;
    shareUrl: string | null;
    canEdit: boolean;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Release notes', href: '#' }] },
});

const args = { workspace: props.workspace.slug, sprint: props.sprint.id };
const form = useForm({ notes: props.notes });
const copied = ref(false);
const opts = { preserveScroll: true };

async function draftAgain(): Promise<void> {
    if (
        await confirmDialog(
            'Draft again from the Done tasks? Your edits are replaced.',
        )
    ) {
        router.post(
            redraft.url(args),
            {},
            { ...opts, onSuccess: () => (form.notes = props.notes) },
        );
    }
}

async function copyLink(): Promise<void> {
    if (props.shareUrl) {
        await navigator.clipboard.writeText(props.shareUrl);
        copied.value = true;
        setTimeout(() => (copied.value = false), 2000);
    }
}
</script>

<template>
    <Head :title="`${sprint.name} release notes`" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <WorkspaceHeader :workspace="workspace" />

        <Card>
            <CardHeader
                class="flex flex-row flex-wrap items-start justify-between gap-2"
            >
                <div>
                    <CardTitle class="text-base"
                        >{{ sprint.name }}: release notes</CardTitle
                    >
                    <CardDescription
                        >Drafted from the tasks finished in this sprint, grouped
                        into new, fixed and other work. Edit them, then download
                        or share.
                        <a
                            :href="sprintsIndex.url(workspace.slug)"
                            class="underline"
                            >Back to sprints</a
                        ></CardDescription
                    >
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button variant="outline" size="sm" as-child
                        ><a :href="pdf.url(args)">PDF</a></Button
                    >
                    <template v-if="canEdit">
                        <Button
                            v-if="!shareUrl"
                            variant="outline"
                            size="sm"
                            @click="router.post(share.url(args), {}, opts)"
                            >Share link</Button
                        >
                        <template v-else>
                            <Button
                                variant="outline"
                                size="sm"
                                @click="copyLink"
                                >{{
                                    copied ? 'Copied' : 'Copy share link'
                                }}</Button
                            >
                            <Button
                                variant="ghost"
                                size="sm"
                                @click="router.delete(unshare.url(args), opts)"
                                >Turn off link</Button
                            >
                        </template>
                    </template>
                </div>
            </CardHeader>
            <CardContent class="grid gap-4 lg:grid-cols-2">
                <form
                    v-if="canEdit"
                    class="space-y-2"
                    @submit.prevent="form.put(update.url(args), opts)"
                >
                    <textarea
                        v-model="form.notes"
                        rows="18"
                        aria-label="Release notes (Markdown)"
                        class="w-full rounded-md border bg-transparent p-2 font-mono text-sm"
                    />
                    <InputError :message="form.errors.notes" />
                    <div class="flex gap-2">
                        <Button
                            type="submit"
                            size="sm"
                            :disabled="form.processing"
                            >Save</Button
                        >
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            @click="draftAgain"
                            >Draft again</Button
                        >
                    </div>
                </form>
                <RichText :html="html" />
            </CardContent>
        </Card>
    </div>
</template>
