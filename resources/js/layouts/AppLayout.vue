<script setup lang="ts">
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import type { BreadcrumbItemType } from '@/types';
import Toaster from '@/components/Toaster.vue';
import { useToast } from '@/composables/useToast';
import { usePage } from '@inertiajs/vue3';
import { watch } from 'vue';

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
}

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

const page = usePage();
const toast = useToast();

watch(
    () => page.props.flash?.success,
    (message) => {
        if (message) toast.success(message);
    },
    { immediate: true },
);

watch(
    () => page.props.flash?.error,
    (message) => {
        if (message) toast.error(message);
    },
    { immediate: true },
);
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <slot />
        <Toaster />
    </AppLayout>
</template>
