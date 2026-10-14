<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { acknowledge } from '@/routes/privacy';

/**
 * E-99: when a new version of the staff privacy notice is published, everyone reads
 * and acknowledges it once. The acknowledgement is stored with the date.
 */
const page = usePage();
const notice = computed(() => page.props.privacy?.notice ?? null);
const busy = ref(false);
const button = ref<{ $el: HTMLElement } | null>(null);

watch(
    notice,
    async (n) => {
        if (n) {
            await nextTick();
            button.value?.$el.focus();
        }
    },
    { immediate: true },
);

function accept(): void {
    if (!notice.value) {
        return;
    }
    busy.value = true;
    router.post(
        acknowledge.url(),
        { version: notice.value.version },
        { preserveScroll: true, onFinish: () => (busy.value = false) },
    );
}
</script>

<template>
    <div
        v-if="notice"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
    >
        <div
            role="dialog"
            aria-modal="true"
            aria-labelledby="privacy-notice-title"
            class="flex max-h-[85vh] w-full max-w-2xl flex-col gap-3 rounded-lg border bg-background p-5 shadow-lg"
        >
            <h2 id="privacy-notice-title" class="text-lg font-semibold">
                {{ notice.title }}
            </h2>
            <p class="text-sm text-muted-foreground">
                Please read this notice about how the agency handles your
                personal data. Version {{ notice.version }}.
            </p>
            <div
                class="min-h-0 flex-1 overflow-y-auto rounded-md border p-3 text-sm whitespace-pre-line"
                tabindex="0"
            >
                {{ notice.body }}
            </div>
            <div class="flex justify-end">
                <Button ref="button" :disabled="busy" @click="accept"
                    >I have read and understood this notice</Button
                >
            </div>
        </div>
    </div>
</template>
