export type NotificationKind =
    | 'assigned'
    | 'mentioned'
    | 'handoff'
    | 'comment'
    | 'status'
    | 'due_soon'
    | 'overdue'
    | 'escalation'
    | 'invoice_overdue';

export type NotificationMode = 'realtime' | 'digest' | 'off';

/** Shared on every page: the bell badge. */
export type NotificationsShared = { unread: number };

export type InboxItem = {
    id: string;
    kind: NotificationKind;
    title: string;
    body: string | null;
    workspace: string | null;
    actor?: string | null;
    read: boolean;
    quiet: boolean;
    done?: boolean;
    snoozed_until?: string | null;
    created_at: string;
};

/** Payload pushed over Reverb (WorkNotification::toBroadcast). */
export type PushedNotification = {
    id?: string;
    kind: NotificationKind;
    title: string;
    body: string | null;
    url: string;
};
