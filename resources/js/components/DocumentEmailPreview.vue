<script setup lang="ts">
import { Monitor, Smartphone } from 'lucide-vue-next';
import { ref } from 'vue';

defineProps<{ html: string; title?: string }>();
const device = ref<'desktop' | 'mobile'>('desktop');
</script>

<template>
    <section
        class="overflow-hidden rounded-xl border"
        :aria-label="title || 'Branded email preview'"
    >
        <div
            class="flex flex-wrap items-center justify-between gap-2 border-b bg-muted/30 px-4 py-2"
        >
            <span class="text-xs text-muted-foreground">{{
                device === 'desktop' ? 'Desktop · 600 px' : 'Mobile · 375 px'
            }}</span>
            <div
                class="flex gap-1"
                role="group"
                aria-label="Email preview width"
            >
                <button
                    v-for="size in ['desktop', 'mobile'] as const"
                    :key="size"
                    type="button"
                    class="rounded-md p-2 focus-visible:ring-2 focus-visible:ring-ring"
                    :class="
                        device === size
                            ? 'bg-background shadow-sm'
                            : 'text-muted-foreground'
                    "
                    :aria-pressed="device === size"
                    :aria-label="`${size} email preview`"
                    @click="device = size"
                >
                    <component
                        :is="size === 'desktop' ? Monitor : Smartphone"
                        class="size-4"
                    />
                </button>
            </div>
        </div>
        <div class="overflow-x-auto bg-slate-100 p-2 sm:p-4">
            <iframe
                :srcdoc="html"
                :title="title || 'Branded email preview'"
                sandbox=""
                referrerpolicy="no-referrer"
                class="mx-auto block h-[540px] max-w-full border-0 bg-white"
                :style="{ width: device === 'mobile' ? '375px' : '600px' }"
            />
        </div>
    </section>
</template>
