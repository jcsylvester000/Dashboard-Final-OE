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
    case DueSoon = 'due_soon';
    case Overdue = 'overdue';
    case Escalation = 'escalation';

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
            self::DueSoon => 'Due within 24 hours',
            self::Overdue => 'Overdue',
            self::Escalation => 'Overdue escalations (leads)',
        };
    }

    public function defaultMode(): string
    {
        return match ($this) {
            self::Status => self::MODE_DIGEST,
            default => self::MODE_REALTIME,
        };
    }

    /** Kinds listed under "Needs my attention" even after being read. */
    public function needsAction(): bool
    {
        return in_array($this, [self::Assigned, self::Mentioned, self::Handoff, self::Overdue, self::Escalation], true);
    }
}
