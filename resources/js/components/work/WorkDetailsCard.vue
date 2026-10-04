<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import TaskController from '@/actions/App/Http/Controllers/Work/TaskController';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type FieldDef = [string, string, string[]?];

/**
 * Department-specific fields (Marketing: channel/campaign/deliverable,
 * SEO: target URL/keyword/work type). Shown only for those departments.
 */
const props = defineProps<{
    workspaceSlug: string;
    taskId: number;
    departmentName: string | null;
    departmentSlug: string | null;
    fields: Record<string, Record<string, FieldDef>>;
    values: Record<string, string | null>;
    canEdit: boolean;
}>();

const deptKey = computed(() => props.departmentSlug ?? '');
const defs = computed(() => props.fields[deptKey.value] ?? null);

const form = reactive<Record<string, string>>(
    Object.fromEntries(Object.entries(props.values ?? {}).map(([k, v]) => [k, v ?? ''])),
);
const errors = ref<Record<string, string>>({});
const saving = ref(false);

function save(): void {
    if (!defs.value) {
        return;
    }
    saving.value = true;
    const work_details = Object.fromEntries(Object.keys(defs.value).map((k) => [k, form[k] ?? '']));

    router.put(
        TaskController.update.url({ workspace: props.workspaceSlug, task: props.taskId }),
        { work_details },
        {
            preserveScroll: true,
            onError: (e) => (errors.value = e),
            onSuccess: () => (errors.value = {}),
            onFinish: () => (saving.value = false),
        },
    );
}
</script>

<template>
    <Card v-if="defs">
        <CardHeader>
            <CardTitle class="text-base">{{ departmentName }} details</CardTitle>
            <CardDescription>Shown in reports and the client work summary.</CardDescription>
        </CardHeader>
        <CardContent class="space-y-3 text-sm">
            <div v-for="(def, key) in defs" :key="key" class="grid gap-1">
                <Label :for="`wd-${key}`">{{ def[0] }}</Label>
                <NativeSelect v-if="def[1] === 'select'" :id="`wd-${key}`" v-model="form[key]" :disabled="!canEdit">
                    <option value="">—</option>
                    <option v-for="o in def[2] ?? []" :key="o" :value="o">{{ o }}</option>
                </NativeSelect>
                <Input
                    v-else
                    :id="`wd-${key}`"
                    v-model="form[key]"
                    :type="def[1] === 'url' ? 'url' : 'text'"
                    :disabled="!canEdit"
                />
                <InputError :message="errors[`work_details.${key}`]" />
            </div>
            <InputError :message="errors.work_details" />
            <Button v-if="canEdit" size="sm" :disabled="saving" @click="save">Save details</Button>
        </CardContent>
    </Card>
</template>
