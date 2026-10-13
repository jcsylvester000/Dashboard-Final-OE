<script setup lang="ts">
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import MentionTextarea from '@/components/MentionTextarea.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { chipColor } from '@/lib/format';
import type {
    LabelOption,
    MemberOption,
    ProjectDetail,
} from '@/types/workspace';

/**
 * Shared create / edit fields. Plain named inputs for the parent Inertia <Form>.
 */
const props = defineProps<{
    project?: ProjectDetail;
    types: Record<string, string>;
    statuses: Record<string, string>;
    labels: LabelOption[];
    members: MemberOption[];
    departments?: { id: number; name: string }[];
    errors: Record<string, string>;
}>();

// S3-02: departments involved; ticking one adds that department's people on this client.
const pickedDepts = ref<number[]>([...(props.project?.department_ids ?? [])]);
const pickedMembers = ref<number[]>(
    (props.project?.members ?? []).map((m) => m.id),
);
function toggleDept(id: number, on: boolean): void {
    pickedDepts.value = on
        ? [...pickedDepts.value, id]
        : pickedDepts.value.filter((d) => d !== id);
    if (on) {
        const add = props.members
            .filter((m) => m.department_id === id)
            .map((m) => m.id);
        pickedMembers.value = [...new Set([...pickedMembers.value, ...add])];
    }
}

/** Errors arrive as member_ids.0, label_ids.2 … show the first one. */
function firstError(field: string): string | undefined {
    return (
        props.errors[field] ??
        Object.entries(props.errors).find(([key]) =>
            key.startsWith(`${field}.`),
        )?.[1]
    );
}
</script>

<template>
    <div class="grid gap-4 sm:grid-cols-2">
        <div class="grid gap-2 sm:col-span-2">
            <Label for="p-name">Name</Label>
            <Input
                id="p-name"
                name="name"
                :default-value="project?.name"
                required
            />
            <InputError :message="errors.name" />
        </div>

        <div class="grid gap-2">
            <Label for="p-type">Type</Label>
            <NativeSelect
                id="p-type"
                name="type"
                :model-value="project?.type ?? 'project'"
            >
                <option v-for="(label, key) in types" :key="key" :value="key">
                    {{ label }}
                </option>
            </NativeSelect>
            <InputError :message="errors.type" />
        </div>

        <div class="grid gap-2">
            <Label for="p-status">Status</Label>
            <NativeSelect
                id="p-status"
                name="status"
                :model-value="project?.status ?? 'planning'"
            >
                <option
                    v-for="(label, key) in statuses"
                    :key="key"
                    :value="key"
                >
                    {{ label }}
                </option>
            </NativeSelect>
            <InputError :message="errors.status" />
        </div>

        <div class="grid gap-2">
            <Label for="p-lead">Lead</Label>
            <NativeSelect
                id="p-lead"
                name="lead_user_id"
                :model-value="project?.lead_user_id ?? ''"
            >
                <option value="">No lead</option>
                <option v-for="m in members" :key="m.id" :value="m.id">
                    {{ m.name }}
                </option>
            </NativeSelect>
            <InputError :message="errors.lead_user_id" />
        </div>

        <div class="grid grid-cols-2 gap-2">
            <div class="grid gap-2">
                <Label for="p-start">Start</Label>
                <Input
                    id="p-start"
                    name="start_on"
                    type="date"
                    :default-value="project?.start_on ?? ''"
                />
                <InputError :message="errors.start_on" />
            </div>
            <div class="grid gap-2">
                <Label for="p-due">Due</Label>
                <Input
                    id="p-due"
                    name="due_on"
                    type="date"
                    :default-value="project?.due_on ?? ''"
                />
                <InputError :message="errors.due_on" />
            </div>
        </div>

        <fieldset v-if="departments?.length" class="grid gap-2 sm:col-span-2">
            <legend class="mb-1 text-sm font-medium">
                Departments involved
            </legend>
            <input type="hidden" name="department_ids[]" value="" />
            <div class="flex flex-wrap gap-x-4 gap-y-2">
                <label
                    v-for="d in departments"
                    :key="d.id"
                    class="inline-flex items-center gap-2 text-sm"
                >
                    <input
                        type="checkbox"
                        name="department_ids[]"
                        :value="d.id"
                        :checked="pickedDepts.includes(d.id)"
                        class="size-4"
                        @change="
                            (e: Event) =>
                                toggleDept(
                                    d.id,
                                    (e.target as HTMLInputElement).checked,
                                )
                        "
                    />
                    {{ d.name }}
                </label>
            </div>
            <p class="text-xs text-muted-foreground">
                Ticking a department also ticks its people on this client below;
                untick anyone who isn't on it.
            </p>
            <InputError :message="firstError('department_ids')" />
        </fieldset>

        <fieldset class="grid gap-2 sm:col-span-2">
            <legend class="mb-1 text-sm font-medium">
                Team on this project
            </legend>
            <div class="flex flex-wrap gap-x-4 gap-y-2">
                <label
                    v-for="m in members"
                    :key="m.id"
                    class="inline-flex items-center gap-2 text-sm"
                >
                    <input
                        v-model="pickedMembers"
                        type="checkbox"
                        name="member_ids[]"
                        :value="m.id"
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

        <label class="flex items-start gap-2 text-sm sm:col-span-2">
            <input type="hidden" name="review_required" value="0" />
            <input
                id="p-review"
                type="checkbox"
                name="review_required"
                value="1"
                class="mt-0.5 size-4"
                :checked="project?.review_required ?? false"
            />
            <span>
                <span class="font-medium">Needs sign-off before Done</span>
                <span class="block text-xs text-muted-foreground">
                    Team members move finished work to In Review; only a lead
                    (client, project or department) can mark it Done.
                </span>
            </span>
        </label>

        <div class="grid gap-2 sm:col-span-2">
            <Label for="p-desc">Brief</Label>
            <MentionTextarea
                id="p-desc"
                name="description"
                :members="members"
                :departments="departments ?? []"
                :default-value="project?.description"
                :rows="6"
                placeholder="Goals, scope, links… Type @ to tag a team member or department."
            />
            <InputError :message="errors.description" />
        </div>
    </div>
</template>
