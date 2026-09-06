<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { useEchoNotifications } from '@/composables/useEchoNotifications';
import { useOrderSound } from '@/composables/useOrderSound';
import { createOrderAlerts, type OrderAlert } from '@/lib/orderAlerts';
import { router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { ShoppingBag, X } from 'lucide-vue-next';
import { onMounted, onUnmounted, ref } from 'vue';

const page = usePage();
const alerts = ref<OrderAlert[]>([]);
let opening: AbortController | null = null;
const sound = useOrderSound();
const { onNotification, offNotification } = useEchoNotifications();
const controller = createOrderAlerts({
    onChange: (value) => { alerts.value = value; },
    onSound: () => sound.play(),
});
const unlockSound = () => { void sound.unlock(); };

async function openOrder(event: MouseEvent, alert: OrderAlert) {
    if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return;
    event.preventDefault();
    if (opening) return;
    const request = new AbortController();
    opening = request;
    try {
        await axios.post(`/notifications/${encodeURIComponent(alert.id)}/read`, {}, {
            signal: request.signal, timeout: 10000, headers: { Accept: 'application/json' },
        });
        window.dispatchEvent(new Event('notifications:changed'));
    } catch {
        // Opening the order remains available if marking the notification read fails.
    } finally {
        if (opening === request) opening = null;
    }
    if (!request.signal.aborted) {
        controller.dismiss(alert.id);
        router.visit(alert.url);
    }
}

onMounted(() => {
    const user = page.props.auth?.user;
    if (!user) return;
    sound.initialize(user.id);
    onNotification('global-order-alerts', controller.receive, user.id);
    // Browsers permit audio after a user gesture. No permission dialog is needed.
    window.addEventListener('pointerdown', unlockSound);
    window.addEventListener('keydown', unlockSound);
});

onUnmounted(() => {
    offNotification('global-order-alerts');
    controller.dispose();
    opening?.abort();
    window.removeEventListener('pointerdown', unlockSound);
    window.removeEventListener('keydown', unlockSound);
});
</script>

<template>
    <div class="pointer-events-none fixed bottom-4 right-4 z-[100] flex w-[calc(100%-2rem)] max-w-sm flex-col gap-3" aria-live="polite" aria-label="New order alerts">
        <div v-for="alert in alerts" :key="alert.id" class="pointer-events-auto rounded-xl border bg-background p-4 shadow-lg" role="status">
            <div class="flex items-start gap-3">
                <ShoppingBag class="mt-0.5 h-5 w-5 shrink-0 text-primary" aria-hidden="true" />
                <div class="min-w-0 flex-1">
                    <p class="font-semibold">New order</p>
                    <p class="mt-1 break-words text-sm text-muted-foreground">{{ alert.message }}</p>
                    <a :href="alert.url" class="mt-3 inline-block text-sm font-medium text-primary underline underline-offset-4" @click="openOrder($event, alert)">View order</a>
                </div>
                <Button variant="ghost" size="icon" class="h-7 w-7 shrink-0" aria-label="Dismiss order alert" @click="controller.dismiss(alert.id)">
                    <X class="h-4 w-4" aria-hidden="true" />
                </Button>
            </div>
        </div>
    </div>
</template>
