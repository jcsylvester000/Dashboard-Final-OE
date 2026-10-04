export type ReportColumn = {
    key: string;
    label: string;
    type: 'text' | 'number' | 'days' | 'hours' | 'percent';
};

export type ReportResult = {
    columns: ReportColumn[];
    rows: Record<string, string | number | null>[];
    summary: Record<string, number>;
};

export type ReportFilters = {
    workspace: number | null;
    department: number | null;
    from: string;
    to: string;
};

export type WorkspaceHealth = 'on_track' | 'at_risk' | 'overdue';
