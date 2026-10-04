<script setup lang="ts">
import { chipColor } from '@/lib/format';

defineProps<{ name: string; color: string }>();
</script>

<template>
    <span
        class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap"
        :class="chipColor[color] ?? chipColor.slate"
    >
        {{ name }}
    </span>
</template>
