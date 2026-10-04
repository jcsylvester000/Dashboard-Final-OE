<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ExternalLink } from '@lucide/vue';
import LabelBadge from '@/components/LabelBadge.vue';
import ReferencePanel from '@/components/ReferencePanel.vue';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import WorkspaceHeader from '@/components/WorkspaceHeader.vue';
import {
    formatDate,
    isOverdue,
    projectStatusColor,
    roleLabel,
} from '@/lib/format';
import { index as workspacesIndex } from '@/routes/workspaces';
import { index as membersIndex } from '@/routes/workspaces/members';
import {
    index as projectsIndex,
    show as projectShow,
} from '@/routes/workspaces/projects';
import type { LinkRow, WorkspaceHeader as Header } from '@/types/workspace';

const props = defineProps<{
    workspace: Header;
    stats: {
        byStatus: Record<string, number>;
        byType: Record<string, number>;
    };
    upcoming: {
        id: number;
        name: string;
        type: string;
        status: string;
        lead: string | null;
        due_on: string | null;
    }[];
    members: { id: number; name: string; title: string | null; role: string }[];
    links: LinkRow[];
    projectTypes: Record<string, string>;
    projectStatuses: Record<string, string>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Workspaces', href: workspacesIndex() }],
    },
});

function count(map: Record<string, number>, key: string): number {
    return Number(map[key] ?? 0);
}

const openCount = () =>
    count(props.stats.byStatus, 'planning') +
    count(props.stats.byStatus, 'active') +
    count(props.stats.byStatus, 'on_hold');
</script>

<template>
    <Head :title="workspace.name" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <WorkspaceHeader :workspace="workspace" />

        <div class="grid gap-4 md:grid-cols-4">
            <Card>
                <CardHeader>
                    <CardDescription>Open work</CardDescription>
                    <CardTitle class="text-2xl">{{ openCount() }}</CardTitle>
                </CardHeader>
            </Card>
            <Card v-for="key in ['active', 'on_hold', 'completed']" :key="key">
                <CardHeader>
                    <CardDescription>{{ projectStatuses[key] }}</CardDescription>
                    <CardTitle class="text-2xl">{{ count(stats.byStatus, key) }}</CardTitle>
                </CardHeader>
            </Card>
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <Card class="lg:col-span-2">
                <CardHeader>
                    <CardTitle class="text-base">Coming up</CardTitle>
                    <CardDescription>
                        Open projects and campaigns by due date ·
                        <Link
                            :href="projectsIndex(workspace.slug)"
                            class="underline underline-offset-4"
                            >All projects</Link
                        >
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <p v-if="upcoming.length === 0" class="text-sm text-muted-foreground">
                        Nothing open. Create the first project from the Projects tab.
                    </p>
                    <ul v-else class="divide-y text-sm">
                        <li
                            v-for="p in upcoming"
                            :key="p.id"
                            class="flex items-center justify-between gap-3 py-2"
                        >
                            <div class="min-w-0">
                                <Link
                                    :href="projectShow({ workspace: workspace.slug, project: p.id })"
                                    class="font-medium hover:underline"
                                    >{{ p.name }}</Link
                                >
                                <div class="text-xs text-muted-foreground">
                                    {{ projectTypes[p.type] ?? p.type }} · Lead:
                                    {{ p.lead ?? '—' }}
                                </div>
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                <LabelBadge
                                    :name="projectStatuses[p.status] ?? p.status"
                                    :color="projectStatusColor[p.status] ?? 'slate'"
                                />
                                <span
                                    class="text-xs"
                                    :class="isOverdue(p.due_on) ? 'font-medium text-rose-600' : 'text-muted-foreground'"
                                    >{{ formatDate(p.due_on) }}</span
                                >
                            </div>
                        </li>
                    </ul>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle class="text-base">About</CardTitle>
                </CardHeader>
                <CardContent class="space-y-2 text-sm">
                    <p v-if="workspace.description">{{ workspace.description }}</p>
                    <p v-if="workspace.primaryContact">
                        <span class="text-muted-foreground">Contact:</span>
                        {{ workspace.primaryContact }}
                    </p>
                    <a
                        v-if="workspace.website"
                        :href="workspace.website"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex items-center gap-1 underline underline-offset-4"
                    >
                        {{ workspace.website }} <ExternalLink class="size-3" />
                    </a>
                    <div class="pt-2">
                        <div class="mb-1 text-xs text-muted-foreground">Work by type</div>
                        <div class="flex flex-wrap gap-1">
                            <LabelBadge
                                v-for="(label, key) in projectTypes"
                                :key="key"
                                :name="`${label}: ${count(stats.byType, String(key))}`"
                                color="slate"
                            />
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <Card>
                <CardHeader>
                    <CardTitle class="text-base">Team on this client</CardTitle>
                    <CardDescription>
                        <Link
                            :href="membersIndex(workspace.slug)"
                            class="underline underline-offset-4"
                            >Manage members</Link
                        >
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <ul class="grid gap-2 text-sm sm:grid-cols-2">
                        <li v-for="m in members" :key="m.id">
                            <span class="font-medium">{{ m.name }}</span>
                            <span class="text-xs text-muted-foreground">
                                · {{ roleLabel(m.role) }}</span
                            >
                        </li>
                    </ul>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle class="text-base">References</CardTitle>
                    <CardDescription>
                        Link this client to related projects or other workspaces.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <ReferencePanel
                        source-type="workspace"
                        :source-id="workspace.id"
                        :links="links"
                        :can-edit="workspace.can.contribute"
                    />
                </CardContent>
            </Card>
        </div>
    </div>
</template>
