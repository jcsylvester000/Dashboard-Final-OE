<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { Eye } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { viewAs } from '@/routes/admin/users';

/**
 * E-90: super admins only. See the app as this person, read-only, for 30 minutes.
 * The reason goes in the activity log.
 */
const props = defineProps<{ userId: number; name: string }>();

const open = ref(false);
const page = usePage();
// Refusals (e.g. another super admin) come back as the view_as error.
const refused = computed(
    () => (page.props.errors as Record<string, string> | undefined)?.view_as,
);
const form = useForm({ reason: '' });

function start(): void {
    form.post(viewAs.url(props.userId));
}
</script>

<template>
    <div class="rounded-lg border p-3 text-sm">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <p>
                <strong>View as {{ name }}</strong>
                <span class="text-muted-foreground">
                    · see exactly what they see, read-only, up to 30 minutes.
                    Logged with your reason.</span
                >
            </p>
            <Button
                v-if="!open"
                variant="outline"
                size="sm"
                @click="open = true"
                ><Eye /> View as…</Button
            >
        </div>
        <form
            v-if="open"
            class="mt-2 flex flex-wrap items-start gap-2"
            @submit.prevent="start"
        >
            <div class="grid min-w-64 flex-1 gap-1">
                <Input
                    v-model="form.reason"
                    placeholder="Why? e.g. they can't see the Ads project"
                    aria-label="Reason for viewing as"
                />
                <InputError :message="form.errors.reason || refused" />
            </div>
            <Button type="submit" size="sm" :disabled="form.processing"
                >Start</Button
            >
            <Button
                type="button"
                variant="ghost"
                size="sm"
                @click="open = false"
                >Cancel</Button
            >
        </form>
    </div>
</template>
