<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { confirmState, settleConfirm } from '@/composables/useConfirm';

/** Renders confirmDialog() requests (S3-13). Closing with Esc or outside click = Cancel. */
function onOpenChange(open: boolean): void {
    if (!open) {
        settleConfirm(false);
    }
}
</script>

<template>
    <Dialog :open="confirmState.open" @update:open="onOpenChange">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{ confirmState.title }}</DialogTitle>
                <DialogDescription
                    class="text-sm whitespace-pre-line text-foreground"
                    >{{ confirmState.message }}</DialogDescription
                >
            </DialogHeader>
            <DialogFooter class="gap-2">
                <Button variant="outline" @click="settleConfirm(false)">{{
                    confirmState.cancelLabel
                }}</Button>
                <Button
                    v-focus
                    :variant="confirmState.danger ? 'destructive' : 'default'"
                    @click="settleConfirm(true)"
                    >{{ confirmState.confirmLabel }}</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
