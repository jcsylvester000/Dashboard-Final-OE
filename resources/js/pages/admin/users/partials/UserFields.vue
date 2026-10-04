<script setup lang="ts">
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { DepartmentOption, MemberForm, Option } from '@/types/admin';

/**
 * Shared fields for Create / Edit member. Plain inputs with `name`
 * attributes so the parent Inertia <Form> submits them.
 */
const props = defineProps<{
    member?: MemberForm;
    roles: Option[];
    departments: DepartmentOption[];
    errors: Record<string, string>;
}>();

const role = ref<string>(props.member?.role ?? 'member');
const primaryDepartment = ref<string | number>(
    props.member?.primary_department_id ?? '',
);
</script>

<template>
    <div class="grid gap-4 sm:grid-cols-2">
        <div class="grid gap-2">
            <Label for="name">Full name</Label>
            <Input
                id="name"
                name="name"
                :default-value="member?.name"
                required
                autocomplete="off"
            />
            <InputError :message="errors.name" />
        </div>

        <div class="grid gap-2">
            <Label for="email">Agency email</Label>
            <Input
                id="email"
                name="email"
                type="email"
                :default-value="member?.email"
                required
                autocomplete="off"
            />
            <InputError :message="errors.email" />
        </div>

        <div class="grid gap-2">
            <Label for="title">Job title</Label>
            <Input
                id="title"
                name="title"
                :default-value="member?.title ?? ''"
                placeholder="e.g. Marketing Lead"
            />
            <InputError :message="errors.title" />
        </div>

        <div class="grid gap-2">
            <Label for="role">Access role</Label>
            <NativeSelect
                id="role"
                name="role"
                required
                v-model="role"
            >
                <option v-for="r in roles" :key="r.value" :value="r.value">
                    {{ r.label }}
                </option>
            </NativeSelect>
            <InputError :message="errors.role" />
        </div>

        <div class="grid gap-2">
            <Label for="primary_department_id">Primary department</Label>
            <NativeSelect
                id="primary_department_id"
                name="primary_department_id"
                v-model="primaryDepartment"
            >
                <option value="">None</option>
                <option v-for="d in departments" :key="d.id" :value="d.id">
                    {{ d.name }}
                </option>
            </NativeSelect>
            <InputError :message="errors.primary_department_id" />
        </div>

        <fieldset class="grid gap-2">
            <legend class="mb-2 text-sm font-medium">
                Also works with
            </legend>
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
                        :checked="member?.department_ids?.includes(d.id)"
                        class="size-4 rounded border-input"
                    />
                    {{ d.name }}
                </label>
            </div>
            <InputError :message="errors.department_ids" />
        </fieldset>
    </div>
</template>
