import { reactive } from 'vue';

/**
 * Styled confirm dialog (S3-13) instead of the browser's native confirm():
 *   if (!(await confirmDialog('Delete this entry?', { danger: true }))) return;
 * One <ConfirmDialog /> is mounted in the app layout and renders this state.
 */
export type ConfirmOptions = {
    title?: string;
    confirmLabel?: string;
    cancelLabel?: string;
    danger?: boolean;
};

export const confirmState = reactive({
    open: false,
    message: '',
    title: 'Are you sure?',
    confirmLabel: 'Confirm',
    cancelLabel: 'Cancel',
    danger: false,
    resolve: null as ((value: boolean) => void) | null,
});

export function confirmDialog(
    message: string,
    options: ConfirmOptions = {},
): Promise<boolean> {
    // A second request while one is open cancels the first.
    confirmState.resolve?.(false);

    const danger =
        options.danger ??
        /\b(delete|remove|discard|erase|revoke|cancel this)\b/i.test(message);
    Object.assign(confirmState, {
        open: true,
        message,
        title: options.title ?? (danger ? 'Please confirm' : 'Are you sure?'),
        confirmLabel:
            options.confirmLabel ?? (danger ? 'Yes, continue' : 'Confirm'),
        cancelLabel: options.cancelLabel ?? 'Cancel',
        danger,
    });

    return new Promise<boolean>((resolve) => {
        confirmState.resolve = resolve;
    });
}

export function settleConfirm(value: boolean): void {
    const resolve = confirmState.resolve;
    confirmState.resolve = null;
    confirmState.open = false;
    resolve?.(value);
}
