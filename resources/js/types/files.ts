export type AttachmentRow = {
    id: number;
    name: string;
    mime: string;
    size: number;
    uploader: string | null;
    created_at: string | null;
    url: string;
    download_url: string;
    thumb_url: string | null;
    previewable: boolean;
    can_delete: boolean;
    approved: boolean;
    approved_by: string | null;
    approved_at: string | null;
    can_approve: boolean;
    /** Set on roll-ups (project page): the task the file belongs to. */
    task?: { id: number; title: string } | null;
    from_drive?: boolean;
    /** E-02 / E-06 */
    version?: number;
    review_status?: 'approved' | 'changes' | null;
    review_note?: string | null;
    versions?: {
        id: number;
        version: number;
        name: string;
        mime: string;
        uploader: string | null;
        created_at: string | null;
        url: string;
        download_url: string;
        approved: boolean;
        review_status?: 'approved' | 'changes' | null;
        review_note?: string | null;
    }[];
};

export type FileLimits = { max_mb: number; extensions: string[] };
