<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Check, ChevronsUpDown, LayoutList } from '@lucide/vue';
import { computed } from 'vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { departmentDot } from '@/lib/format';
import { index, show } from '@/routes/workspaces';

const page = usePage();
const nav = computed(() => page.props.workspaceNav);
</script>

<template>
    <SidebarMenu>
        <SidebarMenuItem>
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <SidebarMenuButton
                        class="border data-[state=open]:bg-sidebar-accent"
                        :tooltip="nav?.current?.name ?? 'Choose a workspace'"
                    >
                        <span
                            class="size-2.5 shrink-0 rounded-full"
                            :class="
                                nav?.current
                                    ? (departmentDot[nav.current.color] ?? 'bg-slate-500')
                                    : 'bg-slate-300'
                            "
                        />
                        <span class="truncate">{{
                            nav?.current?.name ?? 'Choose a workspace'
                        }}</span>
                        <ChevronsUpDown class="ml-auto size-4" />
                    </SidebarMenuButton>
                </DropdownMenuTrigger>
                <DropdownMenuContent class="w-64" align="start">
                    <DropdownMenuLabel class="text-xs text-muted-foreground">
                        Client workspaces
                    </DropdownMenuLabel>
                    <p
                        v-if="!nav || nav.items.length === 0"
                        class="px-2 py-1.5 text-sm text-muted-foreground"
                    >
                        You are not in any workspace yet.
                    </p>
                    <DropdownMenuItem
                        v-for="w in nav?.items ?? []"
                        :key="w.id"
                        as-child
                    >
                        <Link :href="show(w.slug)" class="flex w-full items-center gap-2">
                            <span
                                class="size-2 rounded-full"
                                :class="departmentDot[w.color] ?? 'bg-slate-500'"
                            />
                            <span class="truncate">{{ w.name }}</span>
                            <Check
                                v-if="nav?.current?.id === w.id"
                                class="ml-auto size-4"
                            />
                        </Link>
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem as-child>
                        <Link :href="index()" class="flex w-full items-center gap-2">
                            <LayoutList class="size-4" /> All workspaces
                        </Link>
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </SidebarMenuItem>
    </SidebarMenu>
</template>
