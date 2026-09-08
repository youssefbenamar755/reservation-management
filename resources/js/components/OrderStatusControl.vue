<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useToast } from '@/composables/useToast';
import {
    editableOrderStatuses,
    isEditableOrderStatus,
    orderStatusClasses,
    orderStatusName,
    readConfirmedOrderStatus,
    type ConfirmedOrderStatus,
} from '@/lib/orderStatus';
import axios from 'axios';
import { ArrowRight, ChevronDown, Loader2 } from 'lucide-vue-next';
import { computed, onUnmounted, ref, useId, watch } from 'vue';

const props = defineProps<{
    orderId: number;
    orderNumber: number | string;
    status: string;
    websiteName?: string;
    disabled?: boolean;
}>();
const emit = defineEmits<{
    updated: [order: ConfirmedOrderStatus];
    settled: [order: { id: number }];
}>();
const toast = useToast();
const selectId = useId();
const open = ref(false);
const confirmedStatus = ref(props.status);
const selectedStatus = ref(props.status);
const saving = ref(false);
const error = ref('');
const notice = ref('');
let request: AbortController | null = null;
let generation = 0;
let disposed = false;
const canSave = computed(
    () =>
        open.value &&
        !saving.value &&
        !props.disabled &&
        isEditableOrderStatus(selectedStatus.value) &&
        selectedStatus.value !== confirmedStatus.value,
);

function setOpen(value: boolean) {
    if (saving.value || disposed || (value && props.disabled)) return;
    if (value && !open.value) {
        selectedStatus.value = confirmedStatus.value;
        error.value = '';
        notice.value = '';
    }
    open.value = value;
}

function isCurrent(token: number, id: number): boolean {
    return !disposed && generation === token && props.orderId === id;
}

function failureMessage(exception: unknown): string {
    if (!axios.isAxiosError(exception) || !exception.response)
        return 'The result could not be confirmed. Check the latest order status before trying again.';
    const { status, data } = exception.response;
    if (status === 401 || status === 419)
        return 'Your session expired. Reload WP Hub and sign in again before changing the status.';
    if (status === 403)
        return 'Your access to this order has changed. Refresh the page before continuing.';
    if (status === 404) return 'This order is no longer available.';
    if (status === 429)
        return 'Too many requests. Wait a moment before trying again.';
    const validation = data?.errors?.status;
    const first = Array.isArray(validation) ? validation[0] : validation;
    if (typeof first === 'string' && first.trim()) return first;
    if (typeof data?.message === 'string' && data.message.trim())
        return data.message;
    return 'The status could not be changed. Check the latest order status before trying again.';
}

async function saveStatus() {
    if (!canSave.value || disposed) return;
    const token = ++generation,
        id = props.orderId,
        number = props.orderNumber;
    request = new AbortController();
    saving.value = true;
    error.value = '';
    notice.value = '';
    try {
        const { data } = await axios.put(
            `/orders/${id}`,
            { status: selectedStatus.value },
            {
                headers: { Accept: 'application/json' },
                signal: request.signal,
                timeout: 35_000,
            },
        );
        const order = readConfirmedOrderStatus(data?.order, id);
        if (!order) throw new Error('Invalid order status response');
        if (!isCurrent(token, id)) return;
        confirmedStatus.value = order.status;
        selectedStatus.value = order.status;
        open.value = false;
        emit('updated', order);
        toast.success(
            `Order #${number} status is ${orderStatusName(order.status)}.`,
        );
    } catch (exception) {
        if (!isCurrent(token, id)) return;
        const order = axios.isAxiosError(exception)
            ? readConfirmedOrderStatus(exception.response?.data?.order, id)
            : null;
        if (order) {
            confirmedStatus.value = order.status;
            selectedStatus.value = order.status;
        }
        error.value = failureMessage(exception);
    } finally {
        if (isCurrent(token, id)) {
            saving.value = false;
            request = null;
            emit('settled', { id });
        }
    }
}

watch(
    () => props.status,
    (status) => {
        if (status === confirmedStatus.value) return;
        confirmedStatus.value = status;
        if (open.value && !saving.value) {
            selectedStatus.value = status;
            notice.value = `The order status changed to ${orderStatusName(status)} while you were editing. Review it before saving.`;
        } else if (!open.value) selectedStatus.value = status;
    },
);
watch(
    () => props.orderId,
    () => {
        generation++;
        request?.abort();
        request = null;
        saving.value = false;
        open.value = false;
        error.value = '';
        notice.value = '';
        confirmedStatus.value = props.status;
        selectedStatus.value = props.status;
    },
);
onUnmounted(() => {
    disposed = true;
    generation++;
    request?.abort();
});
</script>

<template>
    <button
        type="button"
        class="inline-flex max-w-full items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium transition hover:ring-1 hover:ring-current focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-60"
        :class="orderStatusClasses(confirmedStatus)"
        :disabled="disabled || saving"
        :aria-label="`Change status for order #${orderNumber}, currently ${orderStatusName(confirmedStatus)}`"
        aria-haspopup="dialog"
        :aria-expanded="open"
        @click.stop="setOpen(true)"
    >
        <span class="truncate">{{ orderStatusName(confirmedStatus) }}</span>
        <Loader2
            v-if="saving"
            class="size-3 shrink-0 animate-spin"
            aria-hidden="true"
        />
        <ChevronDown v-else class="size-3 shrink-0" aria-hidden="true" />
    </button>
    <Dialog :open="open" @update:open="setOpen">
        <DialogContent
            class="max-h-[90dvh] overflow-y-auto sm:max-w-md"
            :show-close-button="!saving"
            @escape-key-down="
                (event) => {
                    if (saving) event.preventDefault();
                }
            "
            @interact-outside="
                (event) => {
                    if (saving) event.preventDefault();
                }
            "
        >
            <DialogHeader>
                <DialogTitle>Change order status</DialogTitle>
                <DialogDescription>
                    Order #{{ orderNumber
                    }}<template v-if="websiteName">
                        · {{ websiteName }}</template
                    >
                </DialogDescription>
            </DialogHeader>
            <form class="space-y-4" @submit.prevent="saveStatus">
                <div
                    class="flex flex-wrap items-center gap-2 rounded-lg bg-muted/30 p-3 text-sm"
                    aria-label="Status change"
                >
                    <span>{{ orderStatusName(confirmedStatus) }}</span>
                    <ArrowRight
                        class="size-4 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <span class="font-medium">{{
                        orderStatusName(selectedStatus)
                    }}</span>
                </div>
                <div class="space-y-1.5">
                    <label :for="selectId" class="text-sm font-medium"
                        >New status</label
                    >
                    <select
                        :id="selectId"
                        v-model="selectedStatus"
                        :disabled="saving || disabled"
                        class="w-full rounded-md border bg-background px-3 py-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-60"
                    >
                        <option
                            v-if="!isEditableOrderStatus(confirmedStatus)"
                            :value="confirmedStatus"
                            disabled
                        >
                            {{ confirmedStatus }} (current)
                        </option>
                        <option
                            v-for="status in editableOrderStatuses"
                            :key="status.value"
                            :value="status.value"
                        >
                            {{ status.label }}
                        </option>
                    </select>
                </div>
                <p class="text-xs leading-relaxed text-muted-foreground">
                    Saving updates the order in WooCommerce and may trigger
                    customer emails or other store actions.
                </p>
                <p
                    v-if="selectedStatus === 'refunded'"
                    class="rounded-lg border border-amber-500/20 bg-amber-500/5 p-3 text-sm"
                >
                    This changes the order status only. It does not issue a
                    payment refund.
                </p>
                <p
                    v-if="notice"
                    role="status"
                    class="rounded-lg border bg-muted/30 p-3 text-sm"
                >
                    {{ notice }}
                </p>
                <p
                    v-if="error"
                    role="alert"
                    class="rounded-lg border border-destructive/20 bg-destructive/5 p-3 text-sm text-destructive"
                >
                    {{ error }}
                </p>
                <DialogFooter class="gap-2 border-t pt-4">
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="saving"
                        @click="setOpen(false)"
                        >Cancel</Button
                    >
                    <Button type="submit" :disabled="!canSave">
                        <Loader2
                            v-if="saving"
                            class="size-4 animate-spin"
                            aria-hidden="true"
                        />
                        {{ saving ? 'Saving…' : 'Save status' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
