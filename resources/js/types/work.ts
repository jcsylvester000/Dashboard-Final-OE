import type { DepartmentOption } from '@/types/admin';
import type { LabelOption, MemberOption, WorkspaceHeader } from '@/types/workspace';

export type TaskStatus = {
    id: number;
    name: string;
    slug: string;
    category: 'open' | 'active' | 'blocked' | 'done';
    color: string;
};

export type TaskRow = {
    id: number;
    title: string;
    priority: 'low' | 'normal' | 'high' | 'urgent';
    status: TaskStatus;
    assignee: { id: number; name: string } | null;
    department: { id: number; name: string; slug?: string; color: string } | null;
    project: { id: number; name: string } | null;
    labels: LabelOption[];
    workspace: { id: number; name: string; slug: string; color: string };
    due_on: string | null;
    position: number;
    waiting_on: number;
    is_handoff: boolean;
};

export type TaskComment = {
    id: number;
    body: string;
    author: { id: number; name: string } | null;
    created_at: string;
    edited_at: string | null;
    can_edit: boolean;
    can_delete: boolean;
};

export type TaskDetail = TaskRow & {
    description: string | null;
    work_details: Record<string, string | null>;
    estimate_minutes: number | null;
    reporter: { id: number; name: string } | null;
    completed_at: string | null;
    created_at: string | null;
    parent_id: number | null;
    handoff_from: { id: number; title: string } | null;
    subtasks: TaskRow[];
    dependencies: (TaskRow & { link_type: string })[];
    dependents: (TaskRow & { link_type: string })[];
    watchers: { id: number; name: string }[];
    watching: boolean;
    comments: TaskComment[];
};

export type TimelineEntry = {
    id: number;
    action: string;
    actor: string | null;
    properties: Record<string, unknown> | null;
    at: string;
};

/** Props shared by every task page inside a workspace. */
export type TaskPageShared = {
    workspace: WorkspaceHeader;
    members: MemberOption[];
    departments: DepartmentOption[];
    templates: { id: number; name: string }[];
    statuses: TaskStatus[];
    priorities: Record<string, string>;
    projects: { id: number; name: string }[];
    labels: LabelOption[];
    doneStatusIds: number[];
};

export type TaskFilters = {
    search?: string;
    status?: number | string;
    department?: number | string;
    project?: number | string;
    label?: number | string;
    assignee?: string;
    due?: 'overdue' | 'week';
    include_done?: boolean | string;
};
