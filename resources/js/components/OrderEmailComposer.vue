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
import type {
    EmailDelivery,
    EmailDeliveryStatus,
    EmailHistory,
    EmailOpenTracking,
    EmailPreview,
    OrderEmailContext,
} from '@/types/email';
import axios from 'axios';
import {
    CheckCircle2,
    FileText,
    Loader2,
    Mail,
    Paperclip,
    RefreshCw,
    Upload,
    X,
} from 'lucide-vue-next';
import { computed, onUnmounted, reactive, ref, shallowRef, watch } from 'vue';

const props = defineProps<{ orderId: number; orderNumber?: string | number }>();
const open = ref(false);
const context = shallowRef<OrderEmailContext | null>(null);
const draft = reactive({
    recipient: '',
    subject: '',
    body: '',
    trackOpens: false,
});
const files = shallowRef<File[]>([]);
const fileInput = ref<HTMLInputElement | null>(null);
const preview = shallowRef<EmailPreview | null>(null);
const delivery = shallowRef<EmailDelivery | null>(null);
const step = ref<'compose' | 'preview'>('compose');
const loading = ref(false);
const preparing = ref(false);
const sending = ref(false);
const checking = ref(false);
const sendUnconfirmed = ref(false);
const error = ref('');
const errors = ref<Record<string, string>>({});
const otherErrors = computed(() =>
    Object.entries(errors.value).filter(
        ([key]) =>
            !['recipient', 'subject', 'body'].includes(key) &&
            !key.startsWith('files'),
    ),
);
const dragging = ref(false);
let disposed = false;
let generation = 0;
let request: AbortController | null = null;
let sendRequest: AbortController | null = null;
const busy = computed(
    () => loading.value || preparing.value || sending.value || checking.value,
);
const limits = computed(
    () =>
        context.value?.limits ?? {
            max_files: 5,
            max_file_bytes: 5_242_880,
            max_total_bytes: 10_485_760,
        },
);
const totalBytes = computed(() =>
    files.value.reduce((sum, file) => sum + file.size, 0),
);
const configured = computed(() =>
    Boolean(context.value?.connection.connected && context.value.sender?.email),
);
const currentStatus = computed(
    () => delivery.value?.status ?? preview.value?.status ?? null,
);
const currentTracking = computed(
    () => delivery.value?.tracking ?? preview.value?.tracking ?? null,
);
const trackingCaveat =
    'Tracking detects image loads. Privacy tools and views in your Sent folder can trigger it; blocked images can hide opens. It does not prove the email was read or a PDF was opened.';
const canSend = computed(
    () =>
        !busy.value &&
        !sendUnconfirmed.value &&
        step.value === 'preview' &&
        Boolean(preview.value) &&
        ['prepared', 'failed'].includes(currentStatus.value ?? ''),
);
const statusMessage = computed(() => {
    if (sendUnconfirmed.value)
        return 'The send result is not confirmed. Refresh the status before taking another action. Check Gmail Sent if it remains uncertain.';
    switch (currentStatus.value) {
        case 'sending':
            return 'This email is being sent. Use Refresh status to check the result.';
        case 'sent':
            return 'This email was sent successfully.';
        case 'failed':
            return 'The email was not sent. Review this message and correct any connection issues before explicitly trying again.';
        case 'uncertain':
            return 'Delivery is uncertain. Check Gmail Sent before preparing another message. Sending this message again is disabled.';
        case 'expired':
            return 'This preview has expired. Return to the draft and prepare a new preview.';
        default:
            return '';
    }
});
const statuses: EmailDeliveryStatus[] = [
    'prepared',
    'sending',
    'sent',
    'failed',
    'uncertain',
    'expired',
];
function isSender(value: unknown): boolean {
    const sender = value as { email?: unknown; name?: unknown } | null;
    return Boolean(
        sender &&
        typeof sender.email === 'string' &&
        typeof sender.name === 'string',
    );
}
function readTracking(value: unknown): EmailOpenTracking {
    if (value === undefined)
        return { enabled: false, first_open_detected_at: null };
    const data = value as EmailOpenTracking | null;
    if (
        !data ||
        typeof data.enabled !== 'boolean' ||
        (data.first_open_detected_at !== null &&
            (typeof data.first_open_detected_at !== 'string' ||
                !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/.test(
                    data.first_open_detected_at,
                ) ||
                !Number.isFinite(Date.parse(data.first_open_detected_at)))) ||
        (!data.enabled && data.first_open_detected_at !== null)
    )
        throw new Error('Invalid email tracking');
    return {
        enabled: data.enabled,
        first_open_detected_at: data.first_open_detected_at,
    };
}
function trackingLabel(
    tracking: EmailOpenTracking,
    status: EmailDeliveryStatus | null,
): string {
    if (!tracking.enabled) return 'Tracking off';
    if (tracking.first_open_detected_at) return 'Open detected';
    return status === 'sent' || status === 'uncertain'
        ? 'No open detected yet'
        : 'Tracking on';
}
function readPreview(value: unknown): EmailPreview {
    const data = value as EmailPreview | null;
    if (
        !data ||
        !/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(
            data.id,
        ) ||
        !statuses.includes(data.status) ||
        !isSender(data.sender) ||
        ![data.recipient, data.subject, data.body, data.expires_at].every(
            (part) => typeof part === 'string',
        ) ||
        !Number.isFinite(Date.parse(data.expires_at)) ||
        !Array.isArray(data.attachments) ||
        !data.attachments.length ||
        !data.attachments.every(
            (file) =>
                typeof file.name === 'string' &&
                Number.isSafeInteger(file.size) &&
                file.size > 0,
        )
    ) {
        throw new Error('Invalid email preview');
    }
    return { ...data, tracking: readTracking(data.tracking) };
}
function readDelivery(value: unknown, id: string): EmailDelivery {
    const data = value as EmailDelivery | null;
    if (
        !data ||
        data.id !== id ||
        !statuses.includes(data.status) ||
        typeof data.message !== 'string' ||
        (data.sent_at !== null && typeof data.sent_at !== 'string')
    )
        throw new Error('Invalid email status');
    return { ...data, tracking: readTracking(data.tracking) };
}
function readHistory(value: unknown): EmailHistory {
    const data = value as EmailHistory | null;
    if (!data || typeof data.created_at !== 'string')
        throw new Error('Invalid email history');
    const saved = readPreview({ ...data, body: '' });
    return {
        ...data,
        ...readDelivery(data, saved.id),
        created_at: data.created_at,
    };
}
function invalidate() {
    generation++;
    request?.abort();
    request = null;
    loading.value = false;
    preparing.value = false;
    checking.value = false;
}
function active(token: number, id: number) {
    return (
        !disposed && open.value && generation === token && props.orderId === id
    );
}
function failure(exception: unknown, fallback: string): string {
    const status = axios.isAxiosError(exception)
        ? exception.response?.status
        : null;
    if (status === 403 || status === 404)
        return 'This order or email is unavailable, or your access has changed.';
    if (status === 419)
        return 'Your session expired. Reload WP Hub and sign in again.';
    if (status === 429)
        return 'Too many requests. Wait a moment and try again.';
    return fallback;
}
function validationErrors(exception: unknown): Record<string, string> {
    if (!axios.isAxiosError(exception) || exception.response?.status !== 422)
        return {};
    const result: Record<string, string> = {};
    const fields = exception.response.data?.errors;
    if (!fields || typeof fields !== 'object') return result;
    for (const [key, value] of Object.entries(fields)) {
        const first = Array.isArray(value) ? value[0] : value;
        if (typeof first === 'string') result[key] = first;
    }
    return result;
}
async function loadContext() {
    if (busy.value || disposed || !open.value) return;
    invalidate();
    const token = generation,
        id = props.orderId;
    request = new AbortController();
    loading.value = true;
    error.value = '';
    try {
        const { data } = await axios.get(`/orders/${id}/email`, {
            headers: { Accept: 'application/json' },
            signal: request.signal,
            timeout: 25_000,
        });
        if (
            !data ||
            typeof data.connection?.connected !== 'boolean' ||
            (data.sender !== null && !isSender(data.sender)) ||
            ![data.recipient, data.subject, data.body].every(
                (value) => typeof value === 'string',
            ) ||
            !Array.isArray(data.history) ||
            !['max_files', 'max_file_bytes', 'max_total_bytes'].every(
                (key) =>
                    Number.isSafeInteger(data.limits?.[key]) &&
                    data.limits[key] > 0,
            )
        )
            throw new Error('Invalid email settings');
        const history = data.history.map(readHistory);
        if (!active(token, id)) return;
        if (!context.value)
            Object.assign(draft, {
                recipient: data.recipient,
                subject: data.subject,
                body: data.body,
            });
        context.value = { ...data, history };
    } catch (exception) {
        if (active(token, id))
            error.value = failure(
                exception,
                'Email settings could not be loaded. Your draft is unchanged.',
            );
    } finally {
        if (active(token, id)) loading.value = false;
    }
}
function setOpen(value: boolean) {
    if (sending.value) return;
    open.value = value;
    if (value) {
        const contextRequest = loadContext();
        const token = generation,
            id = props.orderId;
        void contextRequest.then(() => {
            if (
                active(token, id) &&
                !error.value &&
                step.value === 'preview' &&
                preview.value
            )
                void viewDelivery(preview.value.id);
        });
    } else {
        invalidate();
        dragging.value = false;
    }
}
function addFiles(incoming: File[]) {
    if (busy.value) return;
    error.value = '';
    const next = [...files.value, ...incoming];
    let message = '';
    if (next.length > limits.value.max_files)
        message = `Attach up to ${limits.value.max_files} PDF files.`;
    else if (
        incoming.some((file) => !/\.pdf$/i.test(file.name) || file.size === 0)
    )
        message =
            'Choose non-empty PDF files downloaded from your booking system.';
    else if (next.some((file) => file.size > limits.value.max_file_bytes))
        message = `Each PDF must be ${formatBytes(limits.value.max_file_bytes)} or smaller.`;
    else if (
        next.reduce((sum, file) => sum + file.size, 0) >
        limits.value.max_total_bytes
    )
        message = `All PDFs together must be ${formatBytes(limits.value.max_total_bytes)} or smaller.`;
    errors.value = { ...errors.value, files: message };
    if (!message) files.value = next;
}
function chooseFiles(event: Event) {
    const input = event.target as HTMLInputElement;
    addFiles(Array.from(input.files ?? []));
    input.value = '';
}
function dropFiles(event: DragEvent) {
    dragging.value = false;
    addFiles(Array.from(event.dataTransfer?.files ?? []));
}
function removeFile(index: number) {
    if (busy.value) return;
    files.value = files.value.filter((_, item) => item !== index);
    errors.value = {};
}
async function preparePreview() {
    if (busy.value || !configured.value || disposed) return;
    if (sendUnconfirmed.value) {
        error.value =
            'Refresh the previous delivery status before preparing another email.';
        return;
    }
    const next: Record<string, string> = {};
    if (
        !/^[^\s@,;]+@[^\s@,;]+\.[^\s@,;]+$/.test(draft.recipient.trim()) ||
        draft.recipient.trim().length > 254
    )
        next.recipient = 'Enter one valid recipient email address.';
    if (!draft.subject.trim() || draft.subject.length > 255)
        next.subject = 'Enter a subject of up to 255 characters.';
    if (!draft.body.trim() || draft.body.length > 20_000)
        next.body = 'Enter a message of up to 20,000 characters.';
    if (!files.value.length)
        next.files =
            'Attach at least one PDF downloaded from your booking system.';
    errors.value = next;
    error.value = '';
    if (Object.keys(next).length) return;
    invalidate();
    const token = generation,
        id = props.orderId;
    request = new AbortController();
    preparing.value = true;
    const form = new FormData();
    form.append('recipient', draft.recipient.trim());
    form.append('subject', draft.subject);
    form.append('body', draft.body);
    form.append('track_opens', draft.trackOpens ? '1' : '0');
    files.value.forEach((file) => form.append('files[]', file, file.name));
    try {
        const { data } = await axios.post(`/orders/${id}/email/preview`, form, {
            headers: { Accept: 'application/json' },
            signal: request.signal,
            timeout: 30_000,
        });
        const prepared = readPreview(data.preview);
        if (!active(token, id)) return;
        preview.value = prepared;
        delivery.value = null;
        sendUnconfirmed.value = false;
        step.value = 'preview';
    } catch (exception) {
        if (active(token, id)) {
            errors.value = validationErrors(exception);
            error.value = failure(
                exception,
                'The email preview could not be prepared. Your message and selected PDFs are still here.',
            );
        }
    } finally {
        if (active(token, id)) preparing.value = false;
    }
}
function editDraft() {
    if (busy.value) return;
    invalidate();
    step.value = 'compose';
    error.value = '';
    errors.value = {};
}
async function viewDelivery(id: string) {
    if (busy.value || disposed) return;
    invalidate();
    const token = generation,
        orderId = props.orderId;
    request = new AbortController();
    checking.value = true;
    error.value = '';
    try {
        const { data } = await axios.get(
            `/orders/${orderId}/email/${encodeURIComponent(id)}`,
            {
                headers: { Accept: 'application/json' },
                signal: request.signal,
                timeout: 25_000,
            },
        );
        const saved = readPreview(data.preview);
        const result = readDelivery(data.delivery, id);
        if (saved.id !== id) throw new Error('Invalid saved preview');
        if (!active(token, orderId)) return;
        preview.value = saved;
        delivery.value = result;
        sendUnconfirmed.value = false;
        step.value = 'preview';
    } catch (exception) {
        if (active(token, orderId))
            error.value = failure(
                exception,
                'The email status could not be refreshed. No email was sent by this check.',
            );
    } finally {
        if (active(token, orderId)) checking.value = false;
    }
}
async function sendEmail() {
    if (!canSend.value || !preview.value || disposed || !open.value) return;
    if (Date.parse(preview.value.expires_at) <= Date.now()) {
        delivery.value = {
            id: preview.value.id,
            status: 'expired',
            message: '',
            sent_at: null,
            tracking: preview.value.tracking,
        };
        return;
    }
    const id = preview.value.id,
        orderId = props.orderId,
        token = generation;
    sending.value = true;
    error.value = '';
    sendRequest = new AbortController();
    try {
        const { data } = await axios.post(
            `/orders/${orderId}/email/${encodeURIComponent(id)}/send`,
            { confirmed: true },
            {
                headers: { Accept: 'application/json' },
                signal: sendRequest.signal,
                timeout: 30_000,
            },
        );
        const result = readDelivery(data.delivery, id);
        if (!active(token, orderId)) return;
        delivery.value = result;
        sendUnconfirmed.value = false;
    } catch (exception) {
        if (active(token, orderId)) {
            sendUnconfirmed.value = true;
            error.value = failure(
                exception,
                'The send result could not be confirmed. Do not send again until you have checked its status.',
            );
        }
    } finally {
        if (active(token, orderId)) sending.value = false;
    }
}
function formatBytes(size: number) {
    return size >= 1_048_576
        ? `${(size / 1_048_576).toFixed(1)} MB`
        : `${Math.max(1, Math.ceil(size / 1024))} KB`;
}
function formatTime(value: string) {
    const date = new Date(value);
    return Number.isFinite(date.getTime()) ? date.toLocaleString() : '—';
}
watch(
    () => props.orderId,
    () => {
        invalidate();
        sendRequest?.abort();
        sending.value = false;
        context.value = null;
        preview.value = null;
        delivery.value = null;
        files.value = [];
        Object.assign(draft, {
            recipient: '',
            subject: '',
            body: '',
            trackOpens: false,
        });
        step.value = 'compose';
        sendUnconfirmed.value = false;
        if (open.value) void loadContext();
    },
);
onUnmounted(() => {
    disposed = true;
    invalidate();
    sendRequest?.abort();
});
</script>

<template>
    <Button type="button" variant="outline" size="sm" @click="setOpen(true)"
        ><Mail class="size-4" />Email documents</Button
    >
    <Dialog :open="open" @update:open="setOpen">
        <DialogContent
            class="max-h-[90dvh] auto-rows-max overflow-y-auto sm:max-w-3xl"
            :show-close-button="!sending"
            @escape-key-down="
                (event) => {
                    if (sending) event.preventDefault();
                }
            "
            @interact-outside="
                (event) => {
                    if (sending) event.preventDefault();
                }
            "
        >
            <DialogHeader>
                <DialogTitle
                    >Email documents
                    <span
                        v-if="orderNumber"
                        class="font-normal text-muted-foreground"
                        >· Order #{{ orderNumber }}</span
                    ></DialogTitle
                >
                <DialogDescription>{{
                    step === 'compose'
                        ? 'Attach the PDFs from your booking system, then review the complete email.'
                        : 'Review the saved sender, recipient, message, and attachments before sending.'
                }}</DialogDescription>
            </DialogHeader>
            <div
                v-if="loading"
                class="flex items-center gap-2 py-8 text-sm text-muted-foreground"
                role="status"
            >
                <Loader2 class="size-4 animate-spin" />Loading email settings…
            </div>
            <div
                v-if="error"
                role="alert"
                class="rounded-lg border border-destructive/20 bg-destructive/5 p-3 text-sm text-destructive"
            >
                {{ error }}
                <ul v-if="otherErrors.length" class="mt-2 space-y-1">
                    <li v-for="[key, message] in otherErrors" :key="key">
                        {{ message }}
                    </li>
                </ul>
            </div>
            <div v-if="!context && !loading" class="flex justify-start">
                <Button variant="outline" @click="loadContext"
                    >Retry loading</Button
                >
            </div>
            <template v-if="context && step === 'compose'">
                <div
                    v-if="
                        preview &&
                        (sendUnconfirmed ||
                            currentStatus === 'uncertain' ||
                            currentStatus === 'sending')
                    "
                    class="space-y-2 rounded-lg border bg-muted/30 p-3 text-sm"
                    role="status"
                >
                    <p>{{ statusMessage }}</p>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        :disabled="busy"
                        @click="viewDelivery(preview.id)"
                        >Review previous delivery status</Button
                    >
                </div>
                <div
                    v-if="!configured"
                    class="space-y-2 rounded-lg border bg-muted/30 p-4 text-sm"
                >
                    <p>
                        {{
                            !context.connection.connected
                                ? 'Connect Gmail to send documents from WP Hub.'
                                : 'Choose a verified sender for this website before preparing an email.'
                        }}
                    </p>
                    <div class="flex flex-wrap gap-3">
                        <a
                            href="/settings/email"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="font-medium text-primary underline underline-offset-4"
                            >Open email settings</a
                        ><button
                            type="button"
                            class="underline underline-offset-4"
                            :disabled="busy"
                            @click="loadContext"
                        >
                            Reload settings
                        </button>
                    </div>
                </div>
                <form
                    class="min-w-0 space-y-4"
                    @submit.prevent="preparePreview"
                >
                    <div
                        v-if="context.sender"
                        class="rounded-lg bg-muted/30 p-3 text-sm"
                    >
                        <span class="text-xs text-muted-foreground">From</span>
                        <p class="mt-1 font-medium break-words">
                            {{ context.sender.name }} &lt;{{
                                context.sender.email
                            }}&gt;
                        </p>
                        <a
                            href="/settings/email"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="mt-1 inline-block text-xs text-muted-foreground underline underline-offset-4"
                            >Manage website sender and template</a
                        >
                    </div>
                    <div class="space-y-1.5">
                        <label
                            for="order-email-recipient"
                            class="text-sm font-medium"
                            >To</label
                        ><input
                            id="order-email-recipient"
                            v-model="draft.recipient"
                            type="email"
                            maxlength="254"
                            required
                            autocomplete="email"
                            class="email-input"
                            :disabled="busy"
                            :aria-invalid="Boolean(errors.recipient)"
                        />
                        <p
                            v-if="errors.recipient"
                            class="text-xs text-destructive"
                        >
                            {{ errors.recipient }}
                        </p>
                    </div>
                    <div class="space-y-1.5">
                        <label
                            for="order-email-subject"
                            class="text-sm font-medium"
                            >Subject</label
                        ><input
                            id="order-email-subject"
                            v-model="draft.subject"
                            maxlength="255"
                            required
                            class="email-input"
                            :disabled="busy"
                            :aria-invalid="Boolean(errors.subject)"
                        />
                        <p
                            v-if="errors.subject"
                            class="text-xs text-destructive"
                        >
                            {{ errors.subject }}
                        </p>
                    </div>
                    <div class="space-y-1.5">
                        <label
                            for="order-email-body"
                            class="text-sm font-medium"
                            >Message</label
                        ><textarea
                            id="order-email-body"
                            v-model="draft.body"
                            rows="8"
                            maxlength="20000"
                            required
                            class="email-input resize-y"
                            :disabled="busy"
                            :aria-invalid="Boolean(errors.body)"
                        />
                        <p v-if="errors.body" class="text-xs text-destructive">
                            {{ errors.body }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            The saved template and signature are included in
                            this editable message.
                        </p>
                    </div>
                    <section
                        class="space-y-3"
                        aria-labelledby="email-attachments-title"
                    >
                        <div
                            class="flex flex-wrap items-baseline justify-between gap-2"
                        >
                            <h3
                                id="email-attachments-title"
                                class="text-sm font-medium"
                            >
                                PDF attachments
                            </h3>
                            <span class="text-xs text-muted-foreground"
                                >{{ files.length }} /
                                {{ limits.max_files }} files ·
                                {{
                                    files.length
                                        ? formatBytes(totalBytes)
                                        : '0 KB'
                                }}</span
                            >
                        </div>
                        <div
                            class="rounded-lg border border-dashed p-4 text-center"
                            :class="
                                dragging
                                    ? 'border-primary bg-primary/5'
                                    : 'bg-muted/20'
                            "
                            @dragover.prevent="dragging = true"
                            @dragleave.prevent="dragging = false"
                            @drop.prevent="dropFiles"
                        >
                            <Upload
                                class="mx-auto mb-2 size-5 text-muted-foreground"
                            />
                            <p class="text-sm">
                                Drop your downloaded PDFs here
                            </p>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                class="mt-3"
                                :disabled="busy"
                                @click="fileInput?.click()"
                                >Choose PDFs</Button
                            ><input
                                ref="fileInput"
                                type="file"
                                multiple
                                accept="application/pdf,.pdf"
                                class="sr-only"
                                aria-label="Choose PDF attachments"
                                :disabled="busy"
                                @change="chooseFiles"
                            />
                            <p class="mt-2 text-xs text-muted-foreground">
                                Up to
                                {{ formatBytes(limits.max_file_bytes) }} each,
                                {{ formatBytes(limits.max_total_bytes) }} total.
                            </p>
                        </div>
                        <ul
                            v-if="files.length"
                            class="divide-y rounded-lg border"
                        >
                            <li
                                v-for="(file, index) in files"
                                :key="index"
                                class="flex min-w-0 items-center gap-3 px-3 py-2.5"
                            >
                                <FileText
                                    class="size-4 shrink-0 text-muted-foreground"
                                />
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm break-words">
                                        {{ file.name }}
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        {{ formatBytes(file.size) }}
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    :disabled="busy"
                                    :aria-label="`Remove ${file.name}`"
                                    class="rounded p-1 text-muted-foreground hover:bg-muted"
                                    @click="removeFile(index)"
                                >
                                    <X class="size-4" />
                                </button>
                            </li>
                        </ul>
                        <p
                            v-for="(message, key) in errors"
                            v-show="String(key).startsWith('files') && message"
                            :key="key"
                            class="text-xs text-destructive"
                            role="alert"
                        >
                            {{ message }}
                        </p>
                    </section>
                    <div class="space-y-2 rounded-lg border bg-muted/20 p-3">
                        <label
                            for="order-email-track-opens"
                            class="flex cursor-pointer items-center gap-2.5 text-sm font-medium"
                        >
                            <input
                                id="order-email-track-opens"
                                v-model="draft.trackOpens"
                                type="checkbox"
                                class="size-4 rounded border accent-primary focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-60"
                                :disabled="busy"
                                aria-describedby="order-email-tracking-help"
                            />
                            Track opens
                            <span
                                class="text-xs font-normal text-muted-foreground"
                                >Optional</span
                            >
                        </label>
                        <p
                            id="order-email-tracking-help"
                            class="text-xs leading-relaxed text-muted-foreground"
                        >
                            {{ trackingCaveat }}
                        </p>
                    </div>
                    <DialogFooter class="gap-2 border-t pt-4"
                        ><Button
                            type="button"
                            variant="outline"
                            :disabled="busy"
                            @click="setOpen(false)"
                            >Close</Button
                        ><Button type="submit" :disabled="busy || !configured"
                            ><Loader2
                                v-if="preparing"
                                class="size-4 animate-spin"
                            />{{
                                preparing
                                    ? 'Preparing preview…'
                                    : 'Review email'
                            }}</Button
                        ></DialogFooter
                    >
                </form>
                <details v-if="context.history.length" class="border-t pt-3">
                    <summary
                        class="cursor-pointer text-sm text-muted-foreground"
                    >
                        Recent email deliveries
                    </summary>
                    <ul class="mt-2 divide-y">
                        <li
                            v-for="item in context.history"
                            :key="item.id"
                            class="flex min-w-0 items-center gap-3 py-3"
                        >
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium">
                                    {{ item.subject }}
                                </p>
                                <p
                                    class="text-xs break-words text-muted-foreground"
                                >
                                    {{ item.recipient }} · {{ item.status }} ·
                                    {{ formatTime(item.created_at) }}
                                </p>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    {{
                                        trackingLabel(
                                            item.tracking,
                                            item.status,
                                        )
                                    }}<template
                                        v-if="
                                            item.tracking.first_open_detected_at
                                        "
                                    >
                                        ·
                                        {{
                                            formatTime(
                                                item.tracking
                                                    .first_open_detected_at,
                                            )
                                        }}</template
                                    >
                                </p>
                            </div>
                            <Button
                                size="sm"
                                variant="outline"
                                :disabled="busy"
                                @click="viewDelivery(item.id)"
                                >View</Button
                            >
                        </li>
                    </ul>
                </details>
            </template>
            <template v-if="step === 'preview' && preview">
                <div
                    v-if="statusMessage"
                    class="rounded-lg border p-3 text-sm"
                    :class="
                        currentStatus === 'sent'
                            ? 'border-emerald-500/30 bg-emerald-500/5'
                            : 'bg-muted/30'
                    "
                    role="status"
                >
                    <CheckCircle2
                        v-if="currentStatus === 'sent'"
                        class="mb-2 size-5 text-emerald-600"
                    />{{ statusMessage }}
                    <p
                        v-if="delivery?.sent_at"
                        class="mt-1 text-xs text-muted-foreground"
                    >
                        {{ formatTime(delivery.sent_at) }}
                    </p>
                </div>
                <div
                    v-if="
                        currentTracking?.enabled &&
                        (currentTracking.first_open_detected_at ||
                            ['sent', 'uncertain'].includes(currentStatus ?? ''))
                    "
                    class="space-y-1 rounded-lg border bg-muted/20 p-3 text-sm"
                    role="status"
                >
                    <p class="font-medium">
                        {{ trackingLabel(currentTracking, currentStatus) }}
                    </p>
                    <p
                        v-if="currentTracking.first_open_detected_at"
                        class="text-xs text-muted-foreground"
                    >
                        First detected
                        {{ formatTime(currentTracking.first_open_detected_at) }}
                    </p>
                    <p class="text-xs leading-relaxed text-muted-foreground">
                        {{ trackingCaveat }}
                    </p>
                </div>
                <section
                    class="min-w-0 overflow-hidden rounded-xl border"
                    aria-label="Saved email preview"
                >
                    <dl class="space-y-3 border-b bg-muted/20 p-4 text-sm">
                        <div class="grid gap-1 sm:grid-cols-[70px_1fr]">
                            <dt class="text-muted-foreground">From</dt>
                            <dd class="font-medium break-words">
                                {{ preview.sender.name }} &lt;{{
                                    preview.sender.email
                                }}&gt;
                            </dd>
                        </div>
                        <div class="grid gap-1 sm:grid-cols-[70px_1fr]">
                            <dt class="text-muted-foreground">To</dt>
                            <dd class="break-words">{{ preview.recipient }}</dd>
                        </div>
                        <div class="grid gap-1 sm:grid-cols-[70px_1fr]">
                            <dt class="text-muted-foreground">Subject</dt>
                            <dd class="font-semibold break-words">
                                {{ preview.subject }}
                            </dd>
                        </div>
                        <div class="grid gap-1 sm:grid-cols-[70px_1fr]">
                            <dt class="text-muted-foreground">Tracking</dt>
                            <dd>
                                {{
                                    preview.tracking.enabled
                                        ? 'Tracking on'
                                        : 'Tracking off'
                                }}
                                <p
                                    v-if="
                                        preview.tracking.enabled &&
                                        !['sent', 'uncertain'].includes(
                                            currentStatus ?? '',
                                        )
                                    "
                                    class="mt-1 text-xs leading-relaxed text-muted-foreground"
                                >
                                    {{ trackingCaveat }}
                                </p>
                            </dd>
                        </div>
                    </dl>
                    <div
                        class="max-h-72 overflow-y-auto p-4 text-sm leading-relaxed break-words whitespace-pre-wrap"
                    >
                        {{ preview.body }}
                    </div>
                    <div class="border-t p-4">
                        <h3
                            class="mb-2 flex items-center gap-2 text-xs font-medium text-muted-foreground"
                        >
                            <Paperclip class="size-3.5" />{{
                                preview.attachments.length
                            }}
                            attached PDF{{
                                preview.attachments.length === 1 ? '' : 's'
                            }}
                        </h3>
                        <ul class="space-y-2">
                            <li
                                v-for="(file, index) in preview.attachments"
                                :key="index"
                                class="flex min-w-0 items-start justify-between gap-3 text-sm"
                            >
                                <span class="min-w-0 flex-1 break-words">{{
                                    file.name
                                }}</span
                                ><span
                                    class="shrink-0 text-xs text-muted-foreground"
                                    >{{ formatBytes(file.size) }}</span
                                >
                            </li>
                        </ul>
                    </div>
                </section>
                <p class="text-xs text-muted-foreground">
                    Preview expires {{ formatTime(preview.expires_at) }}. The
                    saved message, PDFs, and tracking choice shown above will be
                    used.
                </p>
                <DialogFooter class="flex-wrap gap-2 border-t pt-4"
                    ><Button
                        variant="outline"
                        :disabled="busy"
                        @click="editDraft"
                        >Back to draft</Button
                    ><Button
                        variant="outline"
                        :disabled="busy"
                        @click="viewDelivery(preview.id)"
                        ><RefreshCw
                            class="size-3.5"
                            :class="{ 'animate-spin': checking }"
                        />Refresh status</Button
                    ><Button
                        v-if="
                            ['prepared', 'failed'].includes(
                                currentStatus ?? '',
                            ) && !sendUnconfirmed
                        "
                        :disabled="!canSend"
                        @click="sendEmail"
                        ><Loader2
                            v-if="sending"
                            class="size-4 animate-spin"
                        /><Mail v-else class="size-4" />{{
                            sending
                                ? 'Sending…'
                                : currentStatus === 'failed'
                                  ? 'Retry send'
                                  : 'Send email'
                        }}</Button
                    ><Button v-else :disabled="sending" @click="setOpen(false)"
                        >Close</Button
                    ></DialogFooter
                >
            </template>
        </DialogContent>
    </Dialog>
</template>

<style scoped>
@reference "../../css/app.css";
.email-input {
    @apply w-full min-w-0 rounded-md border bg-background px-3 py-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-60;
}
</style>
