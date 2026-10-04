<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    BarChart3,
    Bell,
    Gauge,
    Briefcase,
    AlertTriangle,
    Building2,
    CheckSquare,
    CalendarDays,
    ClipboardCheck,
    Clock,
    Columns3,
    Inbox,
    Workflow,
    FolderKanban,
    History,
    LayoutDashboard,
    LayoutGrid,
    ListTodo,
    ShieldCheck,
    Users,
    UsersRound,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import WorkspaceSwitcher from '@/components/WorkspaceSwitcher.vue';
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
import { index as failedJobsIndex } from '@/routes/admin/failed-jobs';
import { index as inboxIndex } from '@/routes/inbox';
import { agency, index as reportsIndex } from '@/routes/reports';
import { index as departmentsIndex } from '@/routes/admin/departments';
import { index as rolesIndex } from '@/routes/admin/roles';
import { index as templatesIndex } from '@/routes/admin/templates';
import { index as usersIndex } from '@/routes/admin/users';
import { queue as departmentQueue } from '@/routes/departments';
import { mine as myTasks } from '@/routes/tasks';
import { index as workspacesIndex, show as workspaceShow } from '@/routes/workspaces';
import { index as wsMembers } from '@/routes/workspaces/members';
import { index as wsProjects } from '@/routes/workspaces/projects';
import { approvals, timesheet } from '@/routes/time';
import { board as wsBoard, calendar as wsCalendar, index as wsTasks } from '@/routes/workspaces/tasks';
import type { NavItem } from '@/types';

const page = usePage();
const can = computed(() => page.props.auth.can ?? {});

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Inbox',
        href: inboxIndex(),
        icon: Bell,
    },
    {
        title: 'My tasks',
        href: myTasks(),
        icon: CheckSquare,
    },
    {
        title: 'All workspaces',
        href: workspacesIndex(),
        icon: Briefcase,
    },
];

const agencyNavItems = computed<NavItem[]>(() => {
    const items = [...mainNavItems];
    items.splice(3, 0, { title: 'My timesheet', href: timesheet(), icon: Clock });
    if (page.props.auth.leads) {
        items.splice(4, 0, { title: 'Time approvals', href: approvals(), icon: ClipboardCheck });
    }
    if (page.props.auth.leads || can.value['reports.view-all']) {
        items.push({ title: 'Reports', href: reportsIndex(), icon: BarChart3 });
    }
    if (can.value['reports.view-all']) {
        items.push({ title: 'Agency overview', href: agency(), icon: Gauge });
    }
    const dept = page.props.auth.department;
    if (dept) {
        items.splice(3, 0, { title: `${dept.name} queue`, href: departmentQueue(dept.slug), icon: Inbox });
    }

    return items;
});

// Links for the workspace currently in focus (from the switcher).
const currentWorkspace = computed(() => page.props.workspaceNav?.current ?? null);
const workspaceNavItems = computed<NavItem[]>(() => {
    const ws = currentWorkspace.value;
    if (!ws) {
        return [];
    }

    return [
        { title: 'Overview', href: workspaceShow(ws.slug), icon: LayoutDashboard },
        { title: 'Tasks', href: wsTasks(ws.slug), icon: ListTodo },
        { title: 'Board', href: wsBoard(ws.slug), icon: Columns3 },
        { title: 'Calendar', href: wsCalendar(ws.slug), icon: CalendarDays },
        { title: 'Projects', href: wsProjects(ws.slug), icon: FolderKanban },
        { title: 'Members', href: wsMembers(ws.slug), icon: UsersRound },
    ];
});

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

    if (can.value['workspaces.manage']) {
        items.push({
            title: 'Workflow templates',
            href: templatesIndex(),
            icon: Workflow,
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

    if (can.value['system.manage']) {
        items.push({
            title: 'Failed jobs',
            href: failedJobsIndex(),
            icon: AlertTriangle,
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
            <WorkspaceSwitcher />
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="agencyNavItems" label="Agency" />
            <NavMain
                :items="workspaceNavItems"
                :label="currentWorkspace?.name ?? 'Workspace'"
            />
            <NavMain :items="adminNavItems" label="Admin" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
