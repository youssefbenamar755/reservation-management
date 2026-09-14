<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Mail, Settings2 } from 'lucide-vue-next';
import { computed } from 'vue';
defineProps<{
    title: string;
    description: string;
    active: string;
    connected: boolean;
}>();
const page = usePage();
const flash = computed(
    () => page.props.flash as { success?: string; error?: string },
);
const errors = computed(() => Object.values(page.props.errors || {}).flat());
const tabs = [
    { name: 'Campaigns', href: '/marketing' },
    { name: 'Audience', href: '/marketing/audience' },
    { name: 'Templates', href: '/marketing/templates' },
];
</script>
<template>
    <Head :title="title + ' · Marketing'" />
    <AppLayout
        :breadcrumbs="[
            { title: 'Marketing', href: '/marketing' },
            { title, href: '' },
        ]"
    >
        <div
            class="mx-auto flex w-full max-w-[1600px] flex-col gap-6 p-4 sm:p-6"
        >
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p
                        class="mb-2 flex items-center gap-2 text-sm font-medium text-teal-700 dark:text-teal-300"
                    >
                        <Mail class="size-4" /> Customer relationships
                    </p>
                    <h1 class="text-3xl font-semibold tracking-tight">
                        {{ title }}
                    </h1>
                    <p class="mt-2 text-muted-foreground">{{ description }}</p>
                </div>
                <Link
                    href="/settings/marketing"
                    class="flex items-center gap-2 rounded-lg border px-4 py-2 text-sm font-medium hover:bg-muted"
                    ><Settings2 class="size-4" /> Marketing settings</Link
                >
            </div>
            <nav aria-label="Marketing" class="flex gap-1 border-b">
                <Link
                    v-for="tab in tabs"
                    :key="tab.href"
                    :href="tab.href"
                    :aria-current="active === tab.name ? 'page' : undefined"
                    class="border-b-2 px-4 py-3 text-sm font-medium"
                    :class="
                        active === tab.name
                            ? 'border-teal-600 text-teal-700 dark:text-teal-300'
                            : 'border-transparent text-muted-foreground hover:text-foreground'
                    "
                    >{{ tab.name }}</Link
                >
            </nav>
            <p
                v-if="flash?.success"
                role="status"
                class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200"
            >
                {{ flash.success }}
            </p>
            <div
                v-if="errors.length || flash?.error"
                role="alert"
                class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-900 dark:border-red-900 dark:bg-red-950 dark:text-red-200"
            >
                <p v-if="flash?.error">{{ flash.error }}</p>
                <p v-for="(error, index) in errors" :key="index">{{ error }}</p>
            </div>
            <div
                v-if="!connected"
                class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-teal-200 bg-teal-50 p-4 text-sm text-teal-950 dark:border-teal-900 dark:bg-teal-950 dark:text-teal-100"
            >
                <span
                    >Prepare your audience and templates now. Connect Brevo to
                    test and schedule campaigns.</span
                ><Link
                    href="/settings/marketing"
                    class="font-semibold underline underline-offset-4"
                    >Connect Brevo</Link
                >
            </div>
            <slot />
        </div>
    </AppLayout>
</template>
