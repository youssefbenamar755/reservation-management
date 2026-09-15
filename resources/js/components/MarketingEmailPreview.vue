<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Eye, Monitor, Smartphone } from 'lucide-vue-next';
import { ref, watch } from 'vue';

const props = withDefaults(
    defineProps<{ html: string; autoOpen?: boolean }>(),
    { autoOpen: false },
);
const open = ref(props.autoOpen);
watch(
    () => props.html,
    () => {
        if (props.autoOpen) open.value = true;
    },
);
const device = ref<'desktop' | 'mobile'>('desktop');
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child
            ><Button type="button" variant="outline"
                ><Eye class="size-4" />Open email preview</Button
            ></DialogTrigger
        >
        <DialogContent class="gap-0 overflow-hidden p-0 sm:max-w-[780px]">
            <DialogHeader class="border-b px-5 py-4 pr-12">
                <DialogTitle>Email preview</DialogTitle>
                <DialogDescription
                    >Compare desktop and mobile layouts. Email apps may display
                    small differences in spacing and fonts.</DialogDescription
                >
            </DialogHeader>
            <div
                class="flex flex-wrap items-center justify-between gap-3 border-b bg-card px-5 py-2"
            >
                <p class="text-sm text-muted-foreground">
                    {{
                        device === 'desktop'
                            ? 'Desktop · 640 px'
                            : 'Mobile · 375 px'
                    }}
                </p>
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
            <div class="overflow-x-auto bg-slate-100 p-2 sm:p-3">
                <iframe
                    :srcdoc="html"
                    title="Email preview"
                    sandbox=""
                    referrerpolicy="no-referrer"
                    class="mx-auto block h-[min(70vh,960px)] max-w-none border-0 bg-white"
                    :style="{ width: device === 'mobile' ? '375px' : '640px' }"
                />
            </div>
        </DialogContent>
    </Dialog>
</template>
