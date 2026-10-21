<?php

namespace App\Domain\Notifications;

/**
 * Every in-app alert type. Each member chooses per kind:
 *  realtime = inbox + bell count + live toast
 *  digest   = inbox and the dashboard digest only (quiet)
 *  off      = not recorded
 */
enum NotificationKind: string
{
    case Assigned = 'assigned';
    case Mentioned = 'mentioned';
    case Handoff = 'handoff';
    case Comment = 'comment';
    case Status = 'status';
    case Review = 'review';
    case Timesheet = 'timesheet';
    case Leave = 'leave';
    case DueSoon = 'due_soon';
    case Overdue = 'overdue';
    case Escalation = 'escalation';
    case InvoiceOverdue = 'invoice_overdue';
    case FileShared = 'file_shared';
    case BillingDraft = 'billing_draft';
    case System = 'system';
    case Budget = 'budget';
    case Preview = 'preview';
    case Intake = 'intake';

    public const MODE_REALTIME = 'realtime';

    public const MODE_DIGEST = 'digest';

    public const MODE_OFF = 'off';

    /** @var list<string> */
    public const MODES = [self::MODE_REALTIME, self::MODE_DIGEST, self::MODE_OFF];

    public function label(): string
    {
        return match ($this) {
            self::Assigned => 'Assigned to me',
            self::Mentioned => '@mentioned',
            self::Handoff => 'Handoffs and "ready to start"',
            self::Comment => 'Comments on tasks I follow',
            self::Status => 'Status changes on tasks I follow',
            self::Review => 'Work waiting for my review',
            self::Timesheet => 'My time returned for changes',
            self::Leave => 'Leave requests and decisions',
            self::DueSoon => 'Due within 24 hours',
            self::Overdue => 'Overdue',
            self::Escalation => 'Overdue escalations (leads)',
            self::InvoiceOverdue => 'Client invoice overdue (Finance)',
            self::FileShared => 'Files shared with me',
            self::BillingDraft => 'Retainer drafts ready to check (Finance)',
            self::System => 'System notices: health, holidays, access ending (admins)',
            self::Budget => 'Project budget burn at 75 / 90 / 100% (leads, Finance)',
            self::Preview => 'Preview link on my task stopped working',
            self::Intake => 'New requests from client forms (leads)',
        };
    }

    public function defaultMode(): string
    {
        return match ($this) {
            // Team-wide shares reach many people: quiet by default.
            self::Status, self::FileShared => self::MODE_DIGEST,
            default => self::MODE_REALTIME,
        };
    }

    /** Kinds listed under "Needs my attention" even after being read. */
    public function needsAction(): bool
    {
        return in_array($this, [self::Assigned, self::Mentioned, self::Handoff, self::Review, self::Timesheet, self::Leave, self::Overdue, self::Escalation, self::InvoiceOverdue, self::BillingDraft, self::System, self::Budget, self::Preview, self::Intake], true);
    }
}
