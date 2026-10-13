<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';
import TaskController from '@/actions/App/Http/Controllers/Work/TaskController';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { CustomField } from '@/types/work';

/**
 * B-07: this client's own task fields (set up by a lead under Settings).
 */
const props = defineProps<{
    workspaceSlug: string;
    taskId: number;
    fields: CustomField[];
    values: Record<string, string | number | boolean | null>;
    canEdit: boolean;
}>();

const form = reactive<Record<string, string | boolean>>(
    Object.fromEntries(
        props.fields.map((f) => {
            const v = props.values?.[f.key];

            return [
                f.key,
                f.type === 'checkbox'
                    ? v === true || v === 1 || v === '1'
                    : v === null || v === undefined
                      ? ''
                      : String(v),
            ];
        }),
    ),
);
const errors = ref<Record<string, string>>({});
const saving = ref(false);

function save(): void {
    saving.value = true;
    const custom_values = Object.fromEntries(
        props.fields.map((f) => [
            f.key,
            f.type === 'checkbox'
                ? form[f.key]
                    ? 1
                    : null
                : form[f.key] || null,
        ]),
    );
    router.put(
        TaskController.update.url({
            workspace: props.workspaceSlug,
            task: props.taskId,
        }),
        { custom_values },
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
    <Card v-if="fields.length">
        <CardHeader>
            <CardTitle class="text-base">Client fields</CardTitle>
            <CardDescription
                >Set up by your lead for this client.</CardDescription
            >
        </CardHeader>
        <CardContent class="space-y-3 text-sm">
            <div v-for="f in fields" :key="f.key" class="grid gap-1">
                <label
                    v-if="f.type === 'checkbox'"
                    class="inline-flex items-center gap-2"
                >
                    <input
                        v-model="form[f.key]"
                        type="checkbox"
                        class="size-4"
                        :disabled="!canEdit"
                    />
                    {{ f.label }}
                </label>
                <template v-else>
                    <Label :for="`cf-${f.key}`">{{ f.label }}</Label>
                    <NativeSelect
                        v-if="f.type === 'select'"
                        :id="`cf-${f.key}`"
                        v-model="form[f.key] as string"
                        :disabled="!canEdit"
                    >
                        <option value="">—</option>
                        <option
                            v-for="o in f.options ?? []"
                            :key="o"
                            :value="o"
                        >
                            {{ o }}
                        </option>
                    </NativeSelect>
                    <Input
                        v-else
                        :id="`cf-${f.key}`"
                        v-model="form[f.key] as string"
                        :type="
                            f.type === 'number'
                                ? 'number'
                                : f.type === 'date'
                                  ? 'date'
                                  : f.type === 'url'
                                    ? 'url'
                                    : 'text'
                        "
                        :step="f.type === 'number' ? 'any' : undefined"
                        :disabled="!canEdit"
                    />
                </template>
                <InputError :message="errors[`custom_values.${f.key}`]" />
            </div>
            <InputError :message="errors.custom_values" />
            <Button v-if="canEdit" size="sm" :disabled="saving" @click="save"
                >Save fields</Button
            >
        </CardContent>
    </Card>
</template>
