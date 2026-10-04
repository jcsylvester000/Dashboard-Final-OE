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
};

export type FileLimits = { max_mb: number; extensions: string[] };
