<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { Pencil, Plus, X } from '@lucide/vue';
import { ref } from 'vue';
import DepartmentController from '@/actions/App/Http/Controllers/Admin/DepartmentController';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { departmentDot } from '@/lib/format';
import { index } from '@/routes/admin/departments';

type DepartmentRow = {
    id: number;
    name: string;
    slug: string;
    color: string;
    description: string | null;
    lead_user_id: number | null;
    lead: string | null;
    members: number;
};

defineProps<{
    departments: DepartmentRow[];
    leads: { id: number; name: string }[];
    colors: string[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Departments', href: index() }],
    },
});

/** id of the row being edited, 0 = new department form, null = none. */
const editing = ref<number | null>(null);
</script>

<template>
    <Head title="Departments" />

    <div class="flex max-w-4xl flex-1 flex-col gap-4 p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">
                    Departments
                </h1>
                <p class="text-sm text-muted-foreground">
                    Every task belongs to a department so work can be handed
                    off between teams.
                </p>
            </div>
            <Button v-if="editing !== 0" @click="editing = 0">
                <Plus /> Add department
            </Button>
        </div>

        <template v-for="d in [null, ...departments]" :key="d?.id ?? 'new'">
            <Card v-if="(d === null && editing === 0) || (d && editing === d.id)">
                <CardHeader>
                    <CardTitle class="text-base">
                        {{ d ? `Edit ${d.name}` : 'New department' }}
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <Form
                        v-bind="
                            d
                                ? DepartmentController.update.form(d.id)
                                : DepartmentController.store.form()
                        "
                        v-slot="{ errors, processing }"
                        class="grid gap-4 sm:grid-cols-2"
                        preserve-scroll
                        @success="editing = null"
                    >
                        <div class="grid gap-2">
                            <Label :for="`name-${d?.id ?? 'new'}`">Name</Label>
                            <Input
                                :id="`name-${d?.id ?? 'new'}`"
                                name="name"
                                :default-value="d?.name"
                                required
                            />
                            <InputError :message="errors.name" />
                        </div>
                        <div class="grid gap-2">
                            <Label :for="`color-${d?.id ?? 'new'}`">Colour</Label>
                            <NativeSelect
                                :id="`color-${d?.id ?? 'new'}`"
                                name="color"
                                :model-value="d?.color ?? 'slate'"
                            >
                                <option v-for="c in colors" :key="c" :value="c">
                                    {{ c }}
                                </option>
                            </NativeSelect>
                            <InputError :message="errors.color" />
                        </div>
                        <div class="grid gap-2">
                            <Label :for="`lead-${d?.id ?? 'new'}`">Lead</Label>
                            <NativeSelect
                                :id="`lead-${d?.id ?? 'new'}`"
                                name="lead_user_id"
                                :model-value="d?.lead_user_id ?? ''"
                            >
                                <option value="">No lead</option>
                                <option
                                    v-for="u in leads"
                                    :key="u.id"
                                    :value="u.id"
                                >
                                    {{ u.name }}
                                </option>
                            </NativeSelect>
                            <InputError :message="errors.lead_user_id" />
                        </div>
                        <div class="grid gap-2">
                            <Label :for="`desc-${d?.id ?? 'new'}`"
                                >Description</Label
                            >
                            <Input
                                :id="`desc-${d?.id ?? 'new'}`"
                                name="description"
                                :default-value="d?.description ?? ''"
                            />
                            <InputError :message="errors.description" />
                        </div>
                        <div class="flex gap-2 sm:col-span-2">
                            <Button type="submit" :disabled="processing">
                                Save
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                @click="editing = null"
                            >
                                <X /> Cancel
                            </Button>
                        </div>
                    </Form>
                </CardContent>
            </Card>
        </template>

        <div class="overflow-hidden rounded-lg border">
            <ul class="divide-y">
                <li
                    v-for="d in departments"
                    :key="d.id"
                    class="flex items-center justify-between gap-3 px-4 py-3"
                >
                    <div class="flex min-w-0 items-center gap-3">
                        <span
                            class="size-3 shrink-0 rounded-full"
                            :class="departmentDot[d.color] ?? 'bg-slate-500'"
                        />
                        <div class="min-w-0">
                            <div class="font-medium">{{ d.name }}</div>
                            <div class="truncate text-xs text-muted-foreground">
                                {{ d.description || 'No description' }} · Lead:
                                {{ d.lead ?? 'none' }} · {{ d.members }} active
                                members
                            </div>
                        </div>
                    </div>
                    <Button
                        variant="ghost"
                        size="sm"
                        :aria-label="`Edit ${d.name}`"
                        @click="editing = d.id"
                    >
                        <Pencil />
                    </Button>
                </li>
            </ul>
        </div>
    </div>
</template>
