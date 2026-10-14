import type { DepartmentOption } from '@/types/admin';
import type {
    LabelOption,
    MemberOption,
    WorkspaceHeader,
} from '@/types/workspace';

export type TaskStatus = {
    id: number;
    name: string;
    slug: string;
    category: 'open' | 'active' | 'blocked' | 'done';
    color: string;
};

export type TaskType = 'task' | 'story' | 'bug' | 'chore' | 'milestone';

export type TaskRow = {
    id: number;
    /** "BRW-24" (client key + number), or "#id" when the client has no key. */
    key: string;
    type: TaskType;
    title: string;
    priority: 'low' | 'normal' | 'high' | 'urgent';
    status: TaskStatus;
    assignee: { id: number; name: string } | null;
    department: {
        id: number;
        name: string;
        slug?: string;
        color: string;
    } | null;
    project: { id: number; name: string } | null;
    /** How many tasks/records this one is linked to (references, waiting on/blocks, parent/subtasks). */
    linked?: number;
    labels: LabelOption[];
    workspace: { id: number; name: string; slug: string; color: string };
    due_on: string | null;
    start_on?: string | null;
    estimate_minutes?: number | null;
    position: number;
    waiting_on: number;
    is_handoff: boolean;
    story_points?: number | null;
    sprint?: { id: number; name: string; status: string } | null;
    sprint_id?: number | null;
    recurrence?: string | null;
    bug_report_missing?: boolean;
    checklist?: { done: number; total: number } | null;
};

export type TaskComment = {
    id: number;
    body: string;
    body_html?: string;
    author: { id: number; name: string } | null;
    created_at: string;
    edited_at: string | null;
    can_edit: boolean;
    can_delete: boolean;
};

/** E-21: structured bug report on bug tasks. */
export type BugReport = {
    steps?: string;
    expected?: string;
    actual?: string;
    environment?: string | null;
    severity?: 'critical' | 'high' | 'medium' | 'low' | null;
};

/** B-07: one custom task field defined for a client. */
export type CustomField = {
    key: string;
    label: string;
    type: 'text' | 'number' | 'date' | 'select' | 'checkbox' | 'url';
    options?: string[];
};

export type TaskDetail = TaskRow & {
    description: string | null;
    description_html?: string;
    bug_report?: BugReport;
    review_rule?: 'all' | 'any';
    reviewers?: {
        user_id: number;
        name: string;
        decision: 'approved' | 'changes' | null;
        note: string | null;
        decided_at: string | null;
    }[];
    checklist_items?: {
        id: number;
        label: string;
        required: boolean;
        done: boolean;
        done_by: string | null;
    }[];
    work_details: Record<string, string | null>;
    custom_values?: Record<string, string | number | boolean | null>;
    estimate_minutes: number | null;
    reporter: { id: number; name: string } | null;
    completed_at: string | null;
    created_at: string | null;
    updated_at?: string | null;
    recurrence?: 'weekly' | 'biweekly' | 'monthly' | null;
    recurrence_until?: string | null;
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
    sprintOptions: { id: number; name: string; status: string }[];
    views: SavedViewRow[];
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
    type?: string;
    /** sprint id, 'active' or 'backlog' */
    sprint?: string;
    group?: TaskGroup;
    /** E-28: research work details */
    method?: string;
    confidence?: string;
};

export type TaskGroup =
    | 'status'
    | 'assignee'
    | 'project'
    | 'department'
    | 'sprint'
    | 'priority';

export type SavedViewRow = {
    id: number;
    name: string;
    page: 'list' | 'board';
    filters: Record<string, string>;
    shared: boolean;
    owner: string;
    can_delete: boolean;
};
