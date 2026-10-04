<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit, update } from '@/routes/admin/files';

const props = defineProps<{
    maxMb: number;
    groups: { key: string; label: string; ext: string[] }[];
    enabled: string[];
    driver: string;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'File settings', href: edit() }],
    },
});

const form = useForm({ max_mb: props.maxMb, groups: [...props.enabled] });
</script>

<template>
    <Head title="File settings" />

    <div class="flex max-w-2xl flex-1 flex-col gap-4 p-4">
        <Heading title="File settings" :description="`Attachments on tasks, projects and comments. Storage: ${driver}. Files are private; links expire after a few minutes.`" />

        <form class="space-y-4" @submit.prevent="form.put(update.url(), { preserveScroll: true })">
            <div class="grid max-w-xs gap-1">
                <Label for="max-mb">Largest upload (MB)</Label>
                <Input id="max-mb" v-model="form.max_mb" type="number" min="1" max="200" />
                <InputError :message="form.errors.max_mb" />
            </div>

            <fieldset class="space-y-2">
                <legend class="text-sm font-medium">Allowed file types</legend>
                <label v-for="g in groups" :key="g.key" class="flex items-start gap-2 text-sm">
                    <input v-model="form.groups" type="checkbox" class="mt-0.5 size-4" :value="g.key" />
                    <span>{{ g.label }} <span class="text-xs text-muted-foreground">({{ g.ext.join(', ') }})</span></span>
                </label>
                <InputError :message="form.errors.groups" />
            </fieldset>

            <Button type="submit" :disabled="form.processing">Save</Button>
        </form>
    </div>
</template>
