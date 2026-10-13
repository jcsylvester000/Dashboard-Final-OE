<script setup lang="ts">
/**
 * Lightweight styled <select>. Works three ways:
 *  - v-model="x"
 *  - :model-value="initial" (uncontrolled; keeps the user's choice locally)
 *  - inside an Inertia <Form> via the `name` attribute
 *
 * A width class passed in (w-40, w-auto…) overrides the default full width,
 * so filter bars stay on one line instead of stacking.
 */
import { computed, ref, useAttrs, watch } from 'vue';
import { cn } from '@/lib/utils';

defineOptions({ inheritAttrs: false });

type Value = string | number | null | undefined;

const props = defineProps<{ modelValue?: Value }>();
const emit = defineEmits<{ 'update:modelValue': [value: Value] }>();
const attrs = useAttrs();

const local = ref<Value>(props.modelValue ?? '');

const rest = computed(() => {
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    const { class: _class, ...others } = attrs;

    return others;
});

const classes = computed(() =>
    cn(
        'h-9 w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-input/30',
        attrs.class as string | undefined,
    ),
);

watch(
    () => props.modelValue,
    (value) => (local.value = value ?? ''),
);

function onChange(): void {
    emit('update:modelValue', local.value);
}
</script>

<template>
    <select v-bind="rest" v-model="local" :class="classes" @change="onChange">
        <slot />
    </select>
</template>
