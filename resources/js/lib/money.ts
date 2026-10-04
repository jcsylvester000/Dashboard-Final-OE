/**
 * Money arrives from the server as integer minor units (centavos / cents).
 * Format only; never do money arithmetic in floats on the client.
 */
const symbols: Record<string, string> = { PHP: '₱', USD: '$' };

export function formatMoney(minor: number | null | undefined, currency = 'PHP'): string {
    const value = minor ?? 0;
    const sign = value < 0 ? '-' : '';
    const abs = Math.abs(value);
    const whole = Math.floor(abs / 100).toLocaleString('en-US');
    const cents = String(abs % 100).padStart(2, '0');

    return `${sign}${symbols[currency] ?? `${currency} `}${whole}.${cents}`;
}

export const invoiceStatusLabel: Record<string, string> = {
    draft: 'Draft',
    finalized: 'Open',
    partially_paid: 'Partially paid',
    paid: 'Paid',
    void: 'Void',
};

export const invoiceStatusClass: Record<string, string> = {
    draft: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
    finalized: 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300',
    partially_paid: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
    paid: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    void: 'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300',
};
