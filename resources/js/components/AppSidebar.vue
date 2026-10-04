<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Building2,
    History,
    LayoutGrid,
    ShieldCheck,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { index as activityIndex } from '@/routes/admin/activity';
import { index as departmentsIndex } from '@/routes/admin/departments';
import { index as rolesIndex } from '@/routes/admin/roles';
import { index as usersIndex } from '@/routes/admin/users';
import type { NavItem } from '@/types';

const page = usePage();
const can = computed(() => page.props.auth.can ?? {});

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
];

// Admin links show only when the member holds the permission (server re-checks).
const adminNavItems = computed<NavItem[]>(() => {
    const items: NavItem[] = [];

    if (can.value['users.view']) {
        items.push({ title: 'Team members', href: usersIndex(), icon: Users });
    }

    if (can.value['departments.manage']) {
        items.push({
            title: 'Departments',
            href: departmentsIndex(),
            icon: Building2,
        });
    }

    if (can.value['roles.manage']) {
        items.push({
            title: 'Roles & access',
            href: rolesIndex(),
            icon: ShieldCheck,
        });
    }

    if (can.value['activity.view']) {
        items.push({
            title: 'Activity log',
            href: activityIndex(),
            icon: History,
        });
    }

    return items;
});
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" label="Workspace" />
            <NavMain :items="adminNavItems" label="Admin" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
