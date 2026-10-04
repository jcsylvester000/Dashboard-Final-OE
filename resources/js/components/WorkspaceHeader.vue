<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { departmentDot, roleLabel } from '@/lib/format';
import { edit, show } from '@/routes/workspaces';
import { index as membersIndex } from '@/routes/workspaces/members';
import { index as projectsIndex } from '@/routes/workspaces/projects';
import type { WorkspaceHeader } from '@/types/workspace';

const props = defineProps<{ workspace: WorkspaceHeader }>();

const { isCurrentUrl } = useCurrentUrl();

const tabs = computed(() => {
    const slug = props.workspace.slug;
    const items = [
        { title: 'Overview', href: show(slug) },
        { title: 'Projects', href: projectsIndex(slug) },
        { title: 'Members', href: membersIndex(slug) },
    ];

    if (props.workspace.can.settings) {
        items.push({ title: 'Settings', href: edit(slug) });
    }

    return items;
});
</script>

<template>
    <div class="space-y-3">
        <div class="flex flex-wrap items-center gap-3">
            <span
                class="size-3 rounded-full"
                :class="departmentDot[workspace.color] ?? 'bg-slate-500'"
            />
            <h1 class="text-xl font-semibold tracking-tight">
                {{ workspace.name }}
            </h1>
            <Badge v-if="workspace.status !== 'active'" variant="outline">
                {{ workspace.status }}
            </Badge>
            <span class="text-sm text-muted-foreground">
                {{ workspace.industry }}
                <template v-if="workspace.myRole">
                    · You: {{ roleLabel(workspace.myRole) }}</template
                >
            </span>
        </div>
        <nav class="flex gap-1 border-b text-sm" aria-label="Workspace sections">
            <Link
                v-for="tab in tabs"
                :key="tab.title"
                :href="tab.href"
                class="-mb-px border-b-2 px-3 py-2"
                :class="
                    isCurrentUrl(tab.href)
                        ? 'border-primary font-medium'
                        : 'border-transparent text-muted-foreground hover:text-foreground'
                "
            >
                {{ tab.title }}
            </Link>
        </nav>
    </div>
</template>
