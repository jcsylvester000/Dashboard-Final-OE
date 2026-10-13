export type UseInitialsReturn = {
    getInitials: (fullName?: string) => string;
    avatarColor: (fullName?: string) => string;
};

/**
 * S2-08: a stable colour per person so avatars with similar initials still differ.
 * Tailwind classes are listed in full so the compiler keeps them.
 */
const AVATAR_COLORS = [
    'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-200',
    'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
    'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200',
    'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-200',
    'bg-violet-100 text-violet-800 dark:bg-violet-950 dark:text-violet-200',
    'bg-teal-100 text-teal-800 dark:bg-teal-950 dark:text-teal-200',
    'bg-orange-100 text-orange-800 dark:bg-orange-950 dark:text-orange-200',
    'bg-indigo-100 text-indigo-800 dark:bg-indigo-950 dark:text-indigo-200',
    'bg-lime-100 text-lime-800 dark:bg-lime-950 dark:text-lime-200',
    'bg-fuchsia-100 text-fuchsia-800 dark:bg-fuchsia-950 dark:text-fuchsia-200',
] as const;

export function avatarColor(fullName?: string): string {
    const name = (fullName ?? '').trim().toLowerCase();
    let hash = 0;
    for (const ch of name) {
        hash = (hash * 31 + (ch.codePointAt(0) ?? 0)) >>> 0;
    }

    return AVATAR_COLORS[hash % AVATAR_COLORS.length];
}

function getInitial(name: string): string {
    return Array.from(name)[0] ?? '';
}

export function getInitials(fullName?: string): string {
    if (!fullName) {
        return '';
    }

    const names = fullName.trim().split(/\s+/u).filter(Boolean);

    if (names.length === 0) {
        return '';
    }

    if (names.length === 1) {
        return getInitial(names[0]).toUpperCase();
    }

    return `${getInitial(names[0])}${getInitial(names[names.length - 1])}`.toUpperCase();
}

export function useInitials(): UseInitialsReturn {
    return { getInitials, avatarColor };
}
