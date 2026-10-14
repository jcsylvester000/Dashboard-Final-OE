export type WorkspaceRole = 'owner' | 'lead' | 'member' | 'guest';

export type WorkspaceHeader = {
    id: number;
    name: string;
    slug: string;
    industry: string | null;
    website: string | null;
    status: 'active' | 'paused' | 'archived';
    color: string;
    description: string | null;
    primaryContact: string | null;
    myRole: WorkspaceRole | null;
    can: {
        contribute: boolean;
        lead: boolean;
        own: boolean;
        manageMembers: boolean;
        settings: boolean;
        reports: boolean;
        workSummary: boolean;
    };
};

export type WorkspaceNavItem = {
    id: number;
    name: string;
    slug: string;
    color: string;
};

export type WorkspaceNav = {
    current: WorkspaceNavItem | null;
    items: WorkspaceNavItem[];
};

export type LabelOption = { id: number; name: string; color: string };

export type MemberOption = {
    id: number;
    name: string;
    title: string | null;
    department_id?: number | null;
};

export type ProjectRow = {
    id: number;
    name: string;
    type: string;
    status: string;
    lead: string | null;
    members: number;
    tasks_total: number;
    tasks_done: number;
    labels: LabelOption[];
    start_on: string | null;
    due_on: string | null;
};

export type ProjectDetail = {
    id: number;
    name: string;
    type: string;
    status: string;
    description: string | null;
    lead_user_id: number | null;
    review_required?: boolean;
    review_sla_days?: number;
    department_ids?: number[];
    status_link?: {
        url: string;
        expires_at: string | null;
        views: number;
        detail?: 'simple' | 'detailed';
    } | null;
    can_share_status?: boolean;
    involved_departments?: { id: number; name: string; color: string }[];
    lead: string | null;
    start_on: string | null;
    due_on: string | null;
    members: MemberOption[];
    labels: LabelOption[];
    mentioned: string[];
    created_at: string | null;
    updated_at: string | null;
};

export type ReferenceItem = {
    type: string;
    record_id: number;
    label: string;
    context: string | null;
    url: string;
};

export type LinkRow = ReferenceItem & {
    id: number;
    direction: 'outgoing' | 'incoming';
    relation?: string;
    relation_label?: string;
};
