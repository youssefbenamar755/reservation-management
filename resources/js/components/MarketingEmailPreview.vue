<script setup lang="ts">
import { Monitor, Smartphone } from 'lucide-vue-next';
import { ref } from 'vue';

defineProps<{ html: string }>();
const device = ref<'desktop' | 'mobile'>('desktop');
</script>

<template>
    <section class="overflow-hidden rounded-xl border bg-slate-100">
        <div
            class="flex flex-wrap items-center justify-between gap-3 border-b bg-card px-5 py-4"
        >
            <h2 class="font-semibold">Email preview</h2>
            <div
                class="flex rounded-lg border p-1"
                role="group"
                aria-label="Preview size"
            >
                <button
                    v-for="size in ['desktop', 'mobile'] as const"
                    :key="size"
                    type="button"
                    :aria-pressed="device === size"
                    :aria-label="
                        size === 'desktop'
                            ? 'Desktop preview'
                            : 'Mobile preview'
                    "
                    class="rounded-md px-3 py-1.5 text-sm"
                    :class="
                        device === size
                            ? 'bg-muted text-foreground'
                            : 'text-muted-foreground'
                    "
                    @click="device = size"
                >
                    <component
                        :is="size === 'desktop' ? Monitor : Smartphone"
                        class="size-4"
                    />
                </button>
            </div>
        </div>
        <div class="overflow-x-auto p-2 sm:p-3">
            <iframe
                :srcdoc="html"
                title="Email preview"
                sandbox=""
                referrerpolicy="no-referrer"
                class="mx-auto block h-[960px] max-w-full border-0 bg-white"
                :style="{ width: device === 'mobile' ? '375px' : '640px' }"
            />
        </div>
        <p class="px-5 pb-4 text-xs text-slate-500">
            Email apps may display small differences in spacing and fonts.
        </p>
    </section>
</template>
