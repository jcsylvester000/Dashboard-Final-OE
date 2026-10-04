<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import MentionTextarea from '@/components/MentionTextarea.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { chipColor } from '@/lib/format';
import type { LabelOption, MemberOption, ProjectDetail } from '@/types/workspace';

/**
 * Shared create / edit fields. Plain named inputs for the parent Inertia <Form>.
 */
const props = defineProps<{
    project?: ProjectDetail;
    types: Record<string, string>;
    statuses: Record<string, string>;
    labels: LabelOption[];
    members: MemberOption[];
    errors: Record<string, string>;
}>();

/** Errors arrive as member_ids.0, label_ids.2 … show the first one. */
function firstError(field: string): string | undefined {
    return (
        props.errors[field] ??
        Object.entries(props.errors).find(([key]) => key.startsWith(`${field}.`))?.[1]
    );
}
</script>

<template>
    <div class="grid gap-4 sm:grid-cols-2">
        <div class="grid gap-2 sm:col-span-2">
            <Label for="p-name">Name</Label>
            <Input id="p-name" name="name" :default-value="project?.name" required />
            <InputError :message="errors.name" />
        </div>

        <div class="grid gap-2">
            <Label for="p-type">Type</Label>
            <NativeSelect id="p-type" name="type" :model-value="project?.type ?? 'project'">
                <option v-for="(label, key) in types" :key="key" :value="key">{{ label }}</option>
            </NativeSelect>
            <InputError :message="errors.type" />
        </div>

        <div class="grid gap-2">
            <Label for="p-status">Status</Label>
            <NativeSelect id="p-status" name="status" :model-value="project?.status ?? 'planning'">
                <option v-for="(label, key) in statuses" :key="key" :value="key">{{ label }}</option>
            </NativeSelect>
            <InputError :message="errors.status" />
        </div>

        <div class="grid gap-2">
            <Label for="p-lead">Lead</Label>
            <NativeSelect id="p-lead" name="lead_user_id" :model-value="project?.lead_user_id ?? ''">
                <option value="">No lead</option>
                <option v-for="m in members" :key="m.id" :value="m.id">{{ m.name }}</option>
            </NativeSelect>
            <InputError :message="errors.lead_user_id" />
        </div>

        <div class="grid grid-cols-2 gap-2">
            <div class="grid gap-2">
                <Label for="p-start">Start</Label>
                <Input id="p-start" name="start_on" type="date" :default-value="project?.start_on ?? ''" />
                <InputError :message="errors.start_on" />
            </div>
            <div class="grid gap-2">
                <Label for="p-due">Due</Label>
                <Input id="p-due" name="due_on" type="date" :default-value="project?.due_on ?? ''" />
                <InputError :message="errors.due_on" />
            </div>
        </div>

        <fieldset class="grid gap-2 sm:col-span-2">
            <legend class="mb-1 text-sm font-medium">Team on this project</legend>
            <div class="flex flex-wrap gap-x-4 gap-y-2">
                <label v-for="m in members" :key="m.id" class="inline-flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        name="member_ids[]"
                        :value="m.id"
                        :checked="project?.members.some((pm) => pm.id === m.id)"
                        class="size-4"
                    />
                    {{ m.name }}
                </label>
            </div>
            <InputError :message="firstError('member_ids')" />
        </fieldset>

        <fieldset v-if="labels.length" class="grid gap-2 sm:col-span-2">
            <legend class="mb-1 text-sm font-medium">Labels</legend>
            <div class="flex flex-wrap gap-2">
                <label
                    v-for="l in labels"
                    :key="l.id"
                    class="inline-flex cursor-pointer items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium"
                    :class="chipColor[l.color] ?? chipColor.slate"
                >
                    <input
                        type="checkbox"
                        name="label_ids[]"
                        :value="l.id"
                        :checked="project?.labels.some((pl) => pl.id === l.id)"
                        class="size-3"
                    />
                    {{ l.name }}
                </label>
            </div>
            <InputError :message="firstError('label_ids')" />
        </fieldset>

        <div class="grid gap-2 sm:col-span-2">
            <Label for="p-desc">Brief</Label>
            <MentionTextarea
                id="p-desc"
                name="description"
                :members="members"
                :default-value="project?.description"
                :rows="6"
                placeholder="Goals, scope, links… Type @ to tag a team member."
            />
            <InputError :message="errors.description" />
        </div>
    </div>
</template>
