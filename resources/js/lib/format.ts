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
