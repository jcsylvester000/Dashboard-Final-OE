<script setup lang="ts">
/**
 * Lightweight styled <select>. Works three ways:
 *  - v-model="x"
 *  - :model-value="initial" (uncontrolled; keeps the user's choice locally)
 *  - inside an Inertia <Form> via the `name` attribute
 */
import { ref, watch } from 'vue';

defineOptions({ inheritAttrs: false });

type Value = string | number | null | undefined;

const props = defineProps<{ modelValue?: Value }>();
const emit = defineEmits<{ 'update:modelValue': [value: Value] }>();

const local = ref<Value>(props.modelValue ?? '');

watch(
    () => props.modelValue,
    (value) => (local.value = value ?? ''),
);

function onChange(): void {
    emit('update:modelValue', local.value);
}
</script>

<template>
    <select
        v-bind="$attrs"
        v-model="local"
        class="h-9 w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-input/30"
        @change="onChange"
    >
        <slot />
    </select>
</template>
