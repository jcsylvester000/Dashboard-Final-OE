export type RunningTimer = {
    id: number;
    started_at: string;
    task: { id: number; title: string } | null;
    workspace_slug: string;
};

export type TimeRow = {
    id: number;
    entry_date: string;
    minutes: number;
    note: string | null;
    is_billable: boolean;
    running: boolean;
    approved: boolean;
    locked: boolean;
    task: { id: number; title: string } | null;
    workspace: { id: number; name: string; slug: string; color: string };
};

export type TaskTimeEntry = {
    id: number;
    user: string;
    is_mine: boolean;
    entry_date: string;
    minutes: number;
    note: string | null;
    is_billable: boolean;
    running: boolean;
    locked: boolean;
};

export type TaskTime = {
    total: number;
    billable: number;
    mine: number;
    estimate: number | null;
    running_here: boolean;
    entries: TaskTimeEntry[];
};
