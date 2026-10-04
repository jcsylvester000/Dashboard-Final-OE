/** Small display helpers shared by admin and dashboard pages. */

export function formatDateTime(iso: string | null | undefined): string {
    if (!iso) {
        return '—';
    }

    return new Date(iso).toLocaleString(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

export function timeAgo(iso: string | null | undefined): string {
    if (!iso) {
        return 'Never';
    }

    const seconds = Math.round((Date.now() - new Date(iso).getTime()) / 1000);
    const rtf = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });
    const steps: [number, Intl.RelativeTimeFormatUnit][] = [
        [60, 'second'],
        [60, 'minute'],
        [24, 'hour'],
        [7, 'day'],
        [4.35, 'week'],
        [12, 'month'],
        [Number.POSITIVE_INFINITY, 'year'],
    ];

    let value = seconds;
    for (const [size, unit] of steps) {
        if (Math.abs(value) < size) {
            return rtf.format(-Math.round(value), unit);
        }
        value /= size;
    }

    return formatDateTime(iso);
}

/** Tailwind classes for a department colour dot (classes listed in full so Tailwind keeps them). */
export const departmentDot: Record<string, string> = {
    slate: 'bg-slate-500',
    blue: 'bg-blue-500',
    violet: 'bg-violet-500',
    emerald: 'bg-emerald-500',
    amber: 'bg-amber-500',
    rose: 'bg-rose-500',
    cyan: 'bg-cyan-500',
    orange: 'bg-orange-500',
};

export function roleLabel(role: string | null | undefined): string {
    if (!role) {
        return '—';
    }

    return role
        .split('-')
        .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
        .join(' ');
}

/** Soft background + text classes for label / status chips. */
export const chipColor: Record<string, string> = {
    slate: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200',
    blue: 'bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-200',
    violet: 'bg-violet-100 text-violet-700 dark:bg-violet-950 dark:text-violet-200',
    emerald: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-200',
    amber: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
    rose: 'bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-200',
    cyan: 'bg-cyan-100 text-cyan-700 dark:bg-cyan-950 dark:text-cyan-200',
    orange: 'bg-orange-100 text-orange-700 dark:bg-orange-950 dark:text-orange-200',
};

export const projectStatusColor: Record<string, string> = {
    planning: 'slate',
    active: 'blue',
    on_hold: 'amber',
    completed: 'emerald',
    archived: 'slate',
};

export function formatDate(date: string | null | undefined): string {
    if (!date) {
        return '—';
    }

    // Plain YYYY-MM-DD: format without timezone shifts.
    const [y, m, d] = date.split('-').map(Number);

    return new Date(y, m - 1, d).toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
}

export function isOverdue(date: string | null | undefined): boolean {
    if (!date) {
        return false;
    }

    return date < new Date().toISOString().slice(0, 10);
}

/** 95 -> "1h 35m", 45 -> "45m", 0 -> "0m" */
export function formatMinutes(minutes: number | null | undefined): string {
    const m = Math.max(0, Math.round(minutes ?? 0));
    const h = Math.floor(m / 60);
    const rest = m % 60;

    if (h === 0) {
        return `${rest}m`;
    }

    return rest === 0 ? `${h}h` : `${h}h ${rest}m`;
}

/** Add days to a YYYY-MM-DD date string (timezone-safe). */
export function addDays(date: string, days: number): string {
    const [y, m, d] = date.split('-').map(Number);
    const dt = new Date(Date.UTC(y, m - 1, d + days));

    return dt.toISOString().slice(0, 10);
}
