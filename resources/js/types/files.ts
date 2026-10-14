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
    /** E-11 / E-13: video processing state and details (null for other files). */
    video?: {
        status: 'processing' | 'ready' | 'failed' | null;
        duration_ms: number | null;
        width: number | null;
        height: number | null;
        fps: number | null;
        error: string | null;
    } | null;
    /** E-23: same-origin plain-text link for logs, JSON, CSV, Markdown. */
    text_url?: string | null;
    can_delete: boolean;
    approved: boolean;
    approved_by: string | null;
    approved_at: string | null;
    can_approve: boolean;
    /** Set on roll-ups (project page): the task the file belongs to. */
    task?: { id: number; title: string } | null;
    from_drive?: boolean;
    /** E-04: open (unresolved) pins on the latest version. */
    open_pins?: number;
    /** E-08: client review link (approvers only) and the client's name on their decision */
    client_link?: {
        url: string;
        expires_at: string | null;
        views: number;
    } | null;
    client_decision_by?: string | null;
    /** E-09 */
    feedback_pdf_url?: string | null;
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

export type FileLimits = {
    max_mb: number;
    extensions: string[];
    /** E-11: direct-to-storage video uploads (tasks and projects). */
    video?: { extensions: string[]; max_mb: number };
};

/** E-05: one vector shape; points are fractions (0..1) of the image. */
export type AnnotationShape = {
    type: 'pen' | 'arrow' | 'rect' | 'ellipse' | 'text';
    color: string;
    width?: number;
    points: [number, number][];
    text?: string;
};

/** E-04: a numbered pin and its thread. */
export type AnnotationPin = {
    id: number;
    number: number;
    x: number | null;
    y: number | null;
    color: string;
    shapes: AnnotationShape[];
    body: string;
    author: string;
    author_id: number | null;
    /** E-08: written by the client on the review link */
    is_client?: boolean;
    at: string | null;
    resolved: boolean;
    replies: {
        id: number;
        body: string;
        author: string;
        author_id: number | null;
        is_client?: boolean;
        at: string | null;
    }[];
};

/** Okabe-Ito palette: tells colours apart with common colour blindness (E-60). */
export const ANNOTATION_COLORS = [
    '#E69F00',
    '#56B4E9',
    '#009E73',
    '#F0E442',
    '#0072B2',
    '#D55E00',
    '#CC79A7',
    '#000000',
    '#FFFFFF',
];
