<script setup lang="ts">
defineOptions({ inheritAttrs: false });
</script>

<template>
    <!-- OverEasy monogram: an "egg yolk" circle inside a rounded square. -->
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 32 32"
        aria-hidden="true"
        v-bind="$attrs"
    >
        <path
            d="M16 3c6.6 0 11 5.7 11 12.6C27 23 22.2 29 16 29S5 23 5 15.6C5 8.7 9.4 3 16 3Zm0 7.5a5 5 0 1 0 0 10 5 5 0 0 0 0-10Z"
            fill-rule="evenodd"
        />
    </svg>
</template>
