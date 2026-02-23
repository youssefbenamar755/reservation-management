<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

interface Props {
    className?: HTMLAttributes['class'];
}

defineProps<Props>();
</script>

<template>
    <!-- Hub icon (lucide) -->
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        :class="className"
        v-bind="$attrs"
    >
        <path d="M12 13V2l8 4-8 4" />
        <path d="M20.561 10.222a9 9 0 1 1-12.55-1.29" />
        <path d="M16.382 11.817a5 5 0 1 1-5.923.412" />
    </svg>
</template>
