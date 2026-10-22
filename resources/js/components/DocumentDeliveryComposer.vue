<script setup lang="ts">
import DocumentEmailPreview from '@/components/DocumentEmailPreview.vue';
import { Button } from '@/components/ui/button';
import type {
    DocumentDeliveryHistory,
    DocumentDeliveryOutcome,
    DocumentDeliveryPreview,
    DocumentLanguage,
    DocumentWorkflowContext,
} from '@/types/document-delivery';
import axios from 'axios';
import {
    CheckCircle2,
    ExternalLink,
    FileText,
    Loader2,
    RefreshCw,
    Send,
    Upload,
} from 'lucide-vue-next';
import { computed, onUnmounted, ref, shallowRef, watch } from 'vue';

const props = defineProps<{
    orderId: number;
    recipient: string;
    workflow: DocumentWorkflowContext;
    limits: {
        max_files: number;
        max_file_bytes: number;
        max_total_bytes: number;
    };
}>();
const emit = defineEmits<{
    settled: [action: 'preview' | 'send' | 'status'];
    busy: [value: boolean];
    close: [];
}>();
const context = shallowRef(props.workflow);
const recipient = ref(props.recipient);
const overrideReason = ref('');
const language = ref<DocumentLanguage>(
    props.workflow.language || props.workflow.profile.default_language,
);
// Historical approved versions stay available, but selecting them is deliberate.
const selected = ref<string[]>([]);
const covered = ref<string[]>([]);
const fulfillmentConfirmed = ref(false);
const mode = ref<'complete' | 'partial' | 'resend' | 'correction'>(
    props.workflow.store.verified ? 'complete' : 'partial',
);
const previous = ref<string | null>(null);
const resendConfirmed = ref(false);
const approvedUpload = ref(false);
const pendingFiles = shallowRef<File[]>([]);
const fileInput = ref<HTMLInputElement | null>(null);
const preview = shallowRef<DocumentDeliveryPreview | null>(null);
const outcome = shallowRef<DocumentDeliveryOutcome | null>(null);
const busy = ref(false);
const error = ref('');
const fieldErrors = ref<string[]>([]);
const resultUnconfirmed = ref(false);
const showPreview = ref(false);
const reviewDecision = ref<'sent' | 'not_sent'>('not_sent');
const reviewReason = ref('');
const reviewMessageId = ref('');
const reviewConfirmed = ref(false);
let disposed = false;
let generation = 0;
let request: AbortController | null = null;
const base = computed(() => `/orders/${props.orderId}/document-deliveries`);
const recipientChanged = computed(
    () =>
        recipient.value.trim().toLowerCase() !==
        props.recipient.trim().toLowerCase(),
);
const selectedDocuments = computed(() =>
    context.value.documents.filter(
        (file) => selected.value.includes(file.id) && file.approved,
    ),
);
const canComplete = computed(
    () => context.value.store.supported && context.value.store.verified,
);
const fullFulfillment = computed(
    () =>
        fulfillmentConfirmed.value &&
        context.value.required_services.length > 0 &&
        context.value.required_services.every((service) =>
            covered.value.includes(service),
        ),
);
const validRecipient = computed(() =>
    /^[^\s@,;]+@[^\s@,;]+\.[^\s@,;]+$/.test(recipient.value.trim()),
);
const blocked = computed(
    () =>
        context.value.readiness.length > 0 ||
        !validRecipient.value ||
        selectedDocuments.value.length === 0 ||
        pendingFiles.value.length > 0 ||
        (recipientChanged.value && !overrideReason.value.trim()) ||
        (mode.value === 'complete' &&
            (!canComplete.value || !fullFulfillment.value)) ||
        (['resend', 'correction'].includes(mode.value) &&
            (!previous.value || !resendConfirmed.value)),
);
const status = computed(() => outcome.value?.status ?? preview.value?.status);
const pendingOutcome = computed(
    () =>
        resultUnconfirmed.value ||
        ['sending', 'uncertain', 'unknown'].includes(status.value ?? '') ||
        context.value.deliveries.some(
            (item) =>
                item.id !== preview.value?.id &&
                ['sending', 'uncertain', 'unknown'].includes(item.status),
        ),
);
const canSend = computed(
    () =>
        !busy.value &&
        !pendingOutcome.value &&
        ['prepared', 'failed'].includes(status.value ?? '') &&
        !!preview.value,
);
const stateMessage = computed(() => {
    if (outcome.value?.captured)
        return 'Captured locally for validation — no email was sent. Any order update is limited to the isolated test store.';
    if (resultUnconfirmed.value)
        return 'Sending outcome unknown — refresh status before taking another action. Do not resend while this result is unconfirmed.';
    if (['uncertain', 'unknown'].includes(status.value ?? ''))
        return 'Sending outcome unknown — review required. Check the connected Gmail Sent folder before deciding what to do next.';
    if (status.value === 'sending')
        return 'Sending is in progress. Refresh status to check the saved result.';
    if (status.value === 'reviewed_sent')
        return 'Recorded as sent after operator review. This is a manual finding, not provider confirmation. The order is not completed automatically.';
    if (status.value === 'reviewed_not_sent')
        return 'Recorded as not sent after operator review. Prepare and confirm a new attempt if you want to send the documents.';
    if (
        status.value === 'sent' &&
        outcome.value?.sync_status &&
        ![
            'completed',
            'synced',
            'not_required',
            'not_requested',
            'skipped',
        ].includes(outcome.value.sync_status)
    )
        return 'Documents sent — order update pending. Retry the order update only; the email will not be sent again.';
    if (status.value === 'sent')
        return preview.value?.will_complete
            ? 'Documents sent. Check the order-sync status below.'
            : 'Documents sent. This action does not complete the order.';
    if (status.value === 'failed')
        return 'The email was not sent. The order has not been completed. Correct the reported issue before explicitly retrying this reviewed email.';
    if (status.value === 'expired')
        return 'This preview expired. Prepare a fresh preview before sending.';
    return outcome.value?.message ?? '';
});
const gmailUrl = computed(() => {
    try {
        const url = new URL(outcome.value?.gmail_url ?? '');
        return url.protocol === 'https:' && url.hostname === 'mail.google.com'
            ? url.href
            : null;
    } catch {
        return null;
    }
});
function bytes(size: number) {
    return size >= 1048576
        ? `${(size / 1048576).toFixed(1)} MB`
        : `${Math.max(1, Math.ceil(size / 1024))} KB`;
}
function time(value: string | null) {
    return value ? new Date(value).toLocaleString() : 'Not sent';
}
function deliveryTimestamp(
    item: Pick<DocumentDeliveryOutcome, 'captured' | 'sent_at'>,
) {
    return item.captured
        ? `Captured locally ${time(item.sent_at)} — no email sent`
        : `Sent ${time(item.sent_at)}`;
}
function failure(exception: unknown, fallback: string) {
    fieldErrors.value = [];
    if (axios.isAxiosError(exception)) {
        if (exception.response?.status === 422) {
            const errors = exception.response.data?.errors;
            if (errors && typeof errors === 'object')
                fieldErrors.value = Object.values(errors)
                    .flat()
                    .filter(
                        (value): value is string => typeof value === 'string',
                    );
        }
        if (exception.response?.status === 409)
            return 'The order, configuration, or documents changed. Refresh this view and review a new preview.';
        if (
            exception.response?.status === 403 ||
            exception.response?.status === 404
        )
            return 'This order or delivery is unavailable, or your access has changed.';
        if (exception.response?.status === 419)
            return 'Your session expired. Reload WP Hub and sign in again.';
    }
    return fallback;
}
async function run(
    action: (signal: AbortSignal) => Promise<void>,
    fallback: string,
) {
    if (busy.value || disposed) return;
    const token = generation;
    busy.value = true;
    error.value = '';
    fieldErrors.value = [];
    request = new AbortController();
    emit('busy', true);
    try {
        await action(request.signal);
    } catch (exception) {
        if (!disposed && token === generation)
            error.value = failure(exception, fallback);
    } finally {
        if (!disposed && token === generation) {
            busy.value = false;
            emit('busy', false);
        }
    }
}
async function refreshContext(signal?: AbortSignal) {
    const id = props.orderId;
    const { data } = await axios.get(`/orders/${id}/email`, {
        signal,
        timeout: 25000,
        headers: { Accept: 'application/json' },
    });
    if (disposed || id !== props.orderId) return;
    if (
        !data.workflow?.enabled ||
        !Array.isArray(data.workflow.documents) ||
        !Array.isArray(data.workflow.deliveries)
    )
        throw new Error('Workflow unavailable');
    context.value = data.workflow;
    selected.value = selected.value.filter((id) =>
        context.value.documents.some((file) => file.id === id && file.approved),
    );
}
function chooseFiles(event: Event) {
    const input = event.target as HTMLInputElement;
    const files = Array.from(input.files ?? []);
    input.value = '';
    error.value = '';
    if (
        files.length > props.limits.max_files ||
        files.some(
            (file) =>
                !/\.pdf$/i.test(file.name) ||
                file.size < 1 ||
                file.size > props.limits.max_file_bytes,
        ) ||
        files.reduce((sum, file) => sum + file.size, 0) >
            props.limits.max_total_bytes
    ) {
        error.value = `Choose up to ${props.limits.max_files} PDFs, ${bytes(props.limits.max_file_bytes)} each and ${bytes(props.limits.max_total_bytes)} total.`;
        return;
    }
    pendingFiles.value = files;
    approvedUpload.value = false;
}
async function upload() {
    if (!approvedUpload.value || !pendingFiles.value.length) return;
    await run(async (signal) => {
        const form = new FormData();
        pendingFiles.value.forEach((file) =>
            form.append('files[]', file, file.name),
        );
        form.append('approved', '1');
        form.append('intended_customer_email', props.recipient);
        const existing = new Set(
            context.value.documents.map((file) => file.id),
        );
        await axios.post(`${base.value}/documents`, form, {
            signal,
            timeout: 30000,
            headers: { Accept: 'application/json' },
        });
        if (disposed) return;
        pendingFiles.value = [];
        approvedUpload.value = false;
        await refreshContext(signal);
        selected.value = [
            ...new Set([
                ...selected.value,
                ...context.value.documents
                    .filter((file) => file.approved && !existing.has(file.id))
                    .map((file) => file.id),
            ]),
        ];
    }, 'The PDFs could not be saved. Your selected files remain available to try again.');
}
function readPreview(value: unknown): DocumentDeliveryPreview {
    const data = value as DocumentDeliveryPreview;
    if (
        !data ||
        typeof data.id !== 'string' ||
        !data.id ||
        typeof data.html !== 'string' ||
        typeof data.recipient !== 'string' ||
        typeof data.subject !== 'string' ||
        typeof data.sender?.email !== 'string' ||
        !Array.isArray(data.attachments) ||
        !data.attachments.length ||
        !data.attachments.every(
            (file) => typeof file.name === 'string' && file.size > 0,
        )
    )
        throw new Error('Invalid delivery preview');
    return data;
}
function readOutcome(value: unknown, id: string): DocumentDeliveryOutcome {
    const data = value as DocumentDeliveryOutcome;
    if (
        !data ||
        data.id !== id ||
        typeof data.status !== 'string' ||
        typeof data.message !== 'string'
    )
        throw new Error('Invalid delivery result');
    return data;
}
async function prepare() {
    if (blocked.value || pendingOutcome.value) return;
    await run(async (signal) => {
        const { data } = await axios.post(
            `${base.value}/prepare`,
            {
                document_ids: selected.value,
                recipient: recipient.value.trim(),
                recipient_override_reason: recipientChanged.value
                    ? overrideReason.value.trim()
                    : null,
                language: language.value,
                mode: mode.value,
                fulfilled_services: covered.value,
                fulfillment_confirmed: fulfillmentConfirmed.value,
                previous_delivery_id: previous.value,
                resend_confirmed: resendConfirmed.value,
            },
            { signal, timeout: 30000, headers: { Accept: 'application/json' } },
        );
        if (disposed) return;
        preview.value = readPreview(data.preview);
        outcome.value = data.delivery
            ? readOutcome(data.delivery, preview.value.id)
            : null;
        showPreview.value = true;
        emit('settled', 'preview');
    }, 'The preview could not be prepared. Review the configuration and selected documents.');
}
async function view(id: string) {
    await run(async (signal) => {
        const { data } = await axios.get(
            `${base.value}/${encodeURIComponent(id)}`,
            { signal, timeout: 25000, headers: { Accept: 'application/json' } },
        );
        if (disposed) return;
        preview.value = readPreview(data.preview);
        outcome.value = readOutcome(data.delivery, id);
        resultUnconfirmed.value = false;
        showPreview.value = true;
        emit('settled', 'status');
    }, 'The saved result could not be loaded. This check does not send an email.');
}
async function send() {
    if (!canSend.value || !preview.value) return;
    const id = preview.value.id;
    await run(async (signal) => {
        // Until a valid server result is returned, retrying could duplicate a sent message.
        resultUnconfirmed.value = true;
        const { data } = await axios.post(
            `${base.value}/${encodeURIComponent(id)}/send`,
            { confirmed: true },
            { signal, timeout: 30000, headers: { Accept: 'application/json' } },
        );
        if (disposed) return;
        outcome.value = readOutcome(data.delivery, id);
        resultUnconfirmed.value = false;
        emit('settled', 'send');
        await refreshContext(signal);
    }, 'The send result could not be confirmed. Refresh status before taking any further action.');
}
async function retrySync() {
    if (
        !preview.value ||
        status.value !== 'sent' ||
        !preview.value.will_complete
    )
        return;
    const id = preview.value.id;
    await run(async (signal) => {
        const { data } = await axios.post(
            `${base.value}/${encodeURIComponent(id)}/sync`,
            {},
            { signal, timeout: 30000, headers: { Accept: 'application/json' } },
        );
        if (disposed) return;
        outcome.value = readOutcome(data.delivery, id);
        emit('settled', 'status');
        await refreshContext(signal);
    }, 'The order update is still pending. No email was sent by this action.');
}
function newAttempt(
    item: DocumentDeliveryHistory,
    next: 'resend' | 'correction',
) {
    if (
        busy.value ||
        pendingOutcome.value ||
        !['sent', 'reviewed_sent'].includes(item.status)
    )
        return;
    previous.value = item.id;
    mode.value = next;
    resendConfirmed.value = false;
    fulfillmentConfirmed.value = false;
    covered.value = [];
    selected.value = [];
    showPreview.value = false;
    preview.value = null;
    outcome.value = null;
    error.value = '';
}
async function recordReview() {
    if (
        !preview.value ||
        !['uncertain', 'sending'].includes(status.value ?? '') ||
        !reviewConfirmed.value ||
        reviewReason.value.trim().length < 10
    )
        return;
    const id = preview.value.id;
    await run(async (signal) => {
        const { data } = await axios.post(
            `${base.value}/${encodeURIComponent(id)}/review`,
            {
                confirmed: true,
                decision: reviewDecision.value,
                reason: reviewReason.value.trim(),
                provider_message_id:
                    reviewDecision.value === 'sent'
                        ? reviewMessageId.value.trim() || null
                        : null,
            },
            { signal, timeout: 30000, headers: { Accept: 'application/json' } },
        );
        if (disposed) return;
        outcome.value = readOutcome(data.delivery, id);
        resultUnconfirmed.value = false;
        reviewConfirmed.value = false;
        emit('settled', 'status');
        await refreshContext(signal);
    }, 'The review could not be recorded. No email was sent and no order status was changed by this review.');
}
watch(
    () => props.workflow,
    (value) => {
        context.value = value;
    },
);
onUnmounted(() => {
    disposed = true;
    generation++;
    request?.abort();
});
defineExpose({
    getState: () => ({
        sending: busy.value,
        hasDraft:
            busy.value ||
            resultUnconfirmed.value ||
            pendingFiles.value.length > 0 ||
            ((!showPreview.value ||
                !['sent', 'reviewed_sent'].includes(status.value ?? '')) &&
                Boolean(
                    preview.value ||
                    selected.value.length ||
                    covered.value.length ||
                    fulfillmentConfirmed.value ||
                    recipientChanged.value ||
                    previous.value,
                )),
    }),
});
</script>

<template>
    <div class="space-y-5">
        <p
            v-if="
                pendingOutcome &&
                !['sending', 'uncertain', 'unknown'].includes(status ?? '')
            "
            role="status"
            class="rounded-lg border border-amber-500/30 bg-amber-500/5 p-3 text-sm"
        >
            An earlier sending result needs review. Open that attempt in the
            history below before preparing another email.
        </p>
        <div
            v-if="error || fieldErrors.length"
            role="alert"
            class="rounded-lg border border-destructive/20 bg-destructive/5 p-3 text-sm text-destructive"
        >
            <p>{{ error }}</p>
            <ul v-if="fieldErrors.length" class="mt-2 list-disc space-y-1 pl-4">
                <li v-for="message in fieldErrors" :key="message">
                    {{ message }}
                </li>
            </ul>
        </div>
        <div v-if="!showPreview" class="space-y-5">
            <div class="rounded-xl border bg-muted/20 p-4">
                <p
                    class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                >
                    {{ context.profile.brand_name }}
                </p>
                <p class="mt-1 text-sm font-medium">
                    {{ context.profile.sender_name }} &lt;{{
                        context.profile.sender_email ||
                        'Configuration required'
                    }}&gt;
                </p>
                <p class="mt-1 text-xs text-muted-foreground">
                    Reply-To:
                    {{ context.profile.reply_to || 'Configuration required' }} ·
                    Brand version {{ context.profile.config_version }}
                </p>
            </div>
            <div
                v-if="context.readiness.length"
                class="rounded-lg border border-amber-500/30 bg-amber-500/5 p-3 text-sm"
                role="status"
            >
                <p class="font-medium">Configuration required</p>
                <ul class="mt-1 list-disc pl-4">
                    <li v-for="issue in context.readiness" :key="issue">
                        {{ issue }}
                    </li>
                </ul>
                <a
                    class="mt-2 inline-block underline"
                    href="/settings/email-documents"
                    target="_blank"
                    rel="noopener noreferrer"
                    >Open Email &amp; Documents settings</a
                >
            </div>
            <div
                v-if="!canComplete"
                class="rounded-lg border border-amber-500/30 bg-amber-500/5 p-3 text-sm"
            >
                <p class="font-medium">Completion suppression not configured</p>
                <p class="mt-1">
                    {{
                        context.store.message ||
                        'Send-only is available. Install and verify the store integration before using automatic completion.'
                    }}
                </p>
            </div>
            <fieldset :disabled="busy" class="space-y-4 disabled:opacity-60">
                <div class="grid gap-4 sm:grid-cols-[1fr_140px]">
                    <label class="space-y-1.5 text-sm font-medium"
                        >Customer email<input
                            v-model="recipient"
                            type="email"
                            maxlength="254"
                            class="document-input"
                            autocomplete="email"
                    /></label>
                    <label class="space-y-1.5 text-sm font-medium"
                        >Email language<select
                            v-model="language"
                            class="document-input"
                        >
                            <option value="fr">Français</option>
                            <option value="en">English</option>
                        </select></label
                    >
                </div>
                <div
                    v-if="recipientChanged"
                    class="space-y-2 rounded-lg border border-amber-500/30 bg-amber-500/5 p-3 text-sm"
                >
                    <p>
                        You are changing the order’s recipient. Verify this
                        address belongs to the intended customer. Your reason
                        and the new address will be recorded.
                    </p>
                    <label class="block text-xs font-medium"
                        >Reason for recipient correction<textarea
                            v-model="overrideReason"
                            class="document-input mt-1"
                            rows="2"
                            maxlength="1000"
                        />
                    </label>
                </div>
                <section
                    class="space-y-3"
                    aria-labelledby="approved-document-heading"
                >
                    <div class="flex items-center justify-between gap-2">
                        <h3
                            id="approved-document-heading"
                            class="text-sm font-semibold"
                        >
                            Approved customer PDFs
                        </h3>
                        <span class="text-xs text-muted-foreground"
                            >{{ selectedDocuments.length }} selected</span
                        >
                    </div>
                    <p class="text-xs text-muted-foreground">
                        Select the approved versions for this delivery. Only
                        documents saved for this exact order can be selected.
                        Check every file before approving an upload.
                    </p>
                    <div
                        v-if="!context.documents.length"
                        class="rounded-lg border border-dashed p-5 text-center text-sm text-muted-foreground"
                    >
                        No approved PDFs yet. Upload documents from your booking
                        system below.
                    </div>
                    <ul v-else class="divide-y rounded-lg border">
                        <li
                            v-for="file in context.documents"
                            :key="file.id"
                            class="flex min-w-0 items-center"
                        >
                            <label
                                class="flex min-w-0 flex-1 items-center gap-3 p-3 text-sm"
                                :class="
                                    !file.approved
                                        ? 'opacity-50'
                                        : 'cursor-pointer'
                                "
                                ><input
                                    v-model="selected"
                                    type="checkbox"
                                    :value="file.id"
                                    :disabled="!file.approved"
                                    class="size-4 accent-primary"
                                /><FileText
                                    class="size-4 shrink-0 text-muted-foreground"
                                /><span class="min-w-0 flex-1 break-words"
                                    >{{ file.name
                                    }}<span
                                        class="mt-0.5 block text-xs text-muted-foreground"
                                        >{{ bytes(file.size) }} · Version
                                        {{ file.version }} ·
                                        {{
                                            file.approved
                                                ? 'Approved'
                                                : 'Not approved'
                                        }}</span
                                    ></span
                                ></label
                            >
                            <a
                                :href="`${base}/documents/${encodeURIComponent(file.id)}`"
                                class="mr-3 shrink-0 text-xs underline underline-offset-4"
                                target="_blank"
                                rel="noopener noreferrer"
                                :aria-label="`Download ${file.name} version ${file.version}`"
                                >Download</a
                            >
                        </li>
                    </ul>
                    <div
                        class="space-y-3 rounded-lg border border-dashed bg-muted/20 p-4"
                    >
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            @click="fileInput?.click()"
                            ><Upload class="size-4" />Choose PDFs</Button
                        ><input
                            ref="fileInput"
                            type="file"
                            multiple
                            accept="application/pdf,.pdf"
                            class="sr-only"
                            aria-label="Choose order PDFs"
                            @change="chooseFiles"
                        />
                        <p class="text-xs text-muted-foreground">
                            Up to {{ limits.max_files }} files ·
                            {{ bytes(limits.max_file_bytes) }} each ·
                            {{ bytes(limits.max_total_bytes) }} total
                        </p>
                        <template v-if="pendingFiles.length"
                            ><ul class="space-y-1 text-sm">
                                <li
                                    v-for="file in pendingFiles"
                                    :key="`${file.name}-${file.size}`"
                                >
                                    {{ file.name }} · {{ bytes(file.size) }}
                                </li>
                            </ul>
                            <label class="flex items-start gap-2 text-sm"
                                ><input
                                    v-model="approvedUpload"
                                    type="checkbox"
                                    class="mt-0.5 size-4 shrink-0 accent-primary"
                                /><span
                                    >I checked these PDFs and approve them for
                                    this order and {{ props.recipient }}.</span
                                ></label
                            >
                            <div class="flex gap-2">
                                <Button
                                    type="button"
                                    size="sm"
                                    :disabled="!approvedUpload || busy"
                                    @click="upload"
                                    >Save approved PDFs</Button
                                ><Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    @click="pendingFiles = []"
                                    >Cancel upload</Button
                                >
                            </div></template
                        >
                    </div>
                </section>
                <div class="space-y-3 rounded-xl border p-4">
                    <h3 class="text-sm font-semibold">After sending</h3>
                    <template v-if="!previous"
                        ><label class="flex items-start gap-2 text-sm"
                            ><input
                                v-model="mode"
                                type="radio"
                                value="complete"
                                :disabled="!canComplete"
                                class="mt-0.5 accent-primary"
                            /><span
                                ><strong class="font-medium"
                                    >Send documents &amp; complete order</strong
                                ><span
                                    class="mt-1 block text-xs text-muted-foreground"
                                    >Complete only after a successful send and
                                    verified suppression of the extra store
                                    completion email.</span
                                ></span
                            ></label
                        ><label class="flex items-start gap-2 text-sm"
                            ><input
                                v-model="mode"
                                type="radio"
                                value="partial"
                                class="mt-0.5 accent-primary"
                            /><span
                                ><strong class="font-medium"
                                    >Send selected documents — keep order
                                    processing</strong
                                ><span
                                    class="mt-1 block text-xs text-muted-foreground"
                                    >For partial fulfillment or stores without
                                    verified completion suppression. This action
                                    leaves the current order status
                                    unchanged.</span
                                ></span
                            ></label
                        ></template
                    >
                    <template v-else
                        ><p class="text-sm font-medium">
                            {{
                                mode === 'resend'
                                    ? 'Resend documents'
                                    : 'Send corrected documents'
                            }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            Select the exact PDFs for this new attempt. It will
                            not complete the order or repeat status
                            synchronization.
                        </p>
                        <label class="flex items-start gap-2 text-sm"
                            ><input
                                v-model="resendConfirmed"
                                type="checkbox"
                                class="mt-0.5 accent-primary"
                            /><span
                                >I intend to send another email for this order.
                                This action will be recorded.</span
                            ></label
                        ></template
                    >
                    <div
                        v-if="mode === 'complete'"
                        class="space-y-2 border-t pt-3"
                    >
                        <p class="text-xs font-medium text-muted-foreground">
                            Confirm every required service is fulfilled
                        </p>
                        <label
                            v-for="service in context.required_services"
                            :key="service"
                            class="flex items-center gap-2 text-sm"
                            ><input
                                v-model="covered"
                                type="checkbox"
                                :value="service"
                                class="size-4 accent-primary"
                            />{{ service }}</label
                        >
                        <p
                            v-if="!context.required_services.length"
                            class="text-sm text-amber-700"
                        >
                            Configure the website’s required services before
                            completing an order.
                        </p>
                        <label
                            class="flex items-start gap-2 pt-2 text-sm font-medium"
                            ><input
                                v-model="fulfillmentConfirmed"
                                type="checkbox"
                                class="mt-0.5 size-4 shrink-0 accent-primary"
                            /><span
                                >All services and required documents for this
                                order are included.</span
                            ></label
                        >
                    </div>
                </div>
            </fieldset>
            <div class="flex flex-wrap justify-end gap-2 border-t pt-4">
                <Button
                    variant="outline"
                    :disabled="busy"
                    @click="emit('close')"
                    >Close</Button
                ><Button
                    :disabled="busy || blocked || pendingOutcome"
                    @click="prepare"
                    ><Loader2 v-if="busy" class="size-4 animate-spin" />Review
                    email &amp; documents</Button
                >
            </div>
        </div>
        <div v-else-if="preview" class="space-y-4">
            <div
                v-if="stateMessage"
                role="status"
                class="rounded-lg border bg-muted/20 p-4 text-sm"
            >
                <CheckCircle2
                    v-if="status === 'sent'"
                    class="mb-2 size-5 text-emerald-600"
                />
                <p class="font-medium">{{ stateMessage }}</p>
                <p
                    v-if="outcome?.message && outcome.message !== stateMessage"
                    class="mt-1"
                >
                    {{ outcome.message }}
                </p>
                <p
                    v-if="outcome?.sent_at"
                    class="mt-2 text-xs text-muted-foreground"
                >
                    {{ deliveryTimestamp(outcome) }}
                </p>
                <p
                    v-if="outcome?.sync_status"
                    class="mt-1 text-xs text-muted-foreground"
                >
                    Order sync: {{ outcome.sync_status }}
                </p>
                <p
                    v-if="outcome?.sync_error"
                    class="mt-1 text-xs text-destructive"
                >
                    {{ outcome.sync_error }}
                </p>
            </div>
            <section
                v-if="['uncertain', 'sending'].includes(status ?? '')"
                class="space-y-3 rounded-xl border border-amber-500/30 bg-amber-500/5 p-4"
                aria-labelledby="delivery-review-heading"
            >
                <h3 id="delivery-review-heading" class="text-sm font-semibold">
                    Review the sending outcome
                </h3>
                <p class="text-sm">
                    Check the correct connected Gmail account’s Sent folder,
                    recipient and attached PDF versions. Refresh the status
                    first if sending is still in progress. Record only a finding
                    you can verify.
                </p>
                <fieldset :disabled="busy" class="space-y-3">
                    <label class="block space-y-1 text-sm font-medium"
                        >Finding<select
                            v-model="reviewDecision"
                            class="document-input"
                        >
                            <option value="not_sent">
                                I verified that this message was not sent
                            </option>
                            <option value="sent">
                                I found the sent message and verified its
                                attachments
                            </option>
                        </select></label
                    >
                    <label class="block space-y-1 text-sm font-medium"
                        >Evidence and reason<textarea
                            v-model="reviewReason"
                            rows="3"
                            minlength="10"
                            maxlength="1000"
                            class="document-input"
                            placeholder="Explain what you checked and how you verified the outcome."
                        />
                    </label>
                    <label
                        v-if="reviewDecision === 'sent'"
                        class="block space-y-1 text-sm font-medium"
                        >Verified Gmail message ID
                        <span class="font-normal text-muted-foreground"
                            >(optional)</span
                        ><input
                            v-model="reviewMessageId"
                            class="document-input"
                            maxlength="255"
                    /></label>
                    <label class="flex items-start gap-2 text-sm"
                        ><input
                            v-model="reviewConfirmed"
                            type="checkbox"
                            class="mt-0.5 size-4 shrink-0 accent-primary"
                        /><span
                            >I personally verified this finding. Recording it
                            does not resend documents or complete the
                            order.</span
                        ></label
                    >
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="
                            !reviewConfirmed || reviewReason.trim().length < 10
                        "
                        @click="recordReview"
                        >Record operator review</Button
                    >
                </fieldset>
            </section>
            <div
                v-if="outcome?.review"
                class="rounded-lg border bg-muted/20 p-3 text-sm"
            >
                <p class="font-medium">Operator review recorded</p>
                <p class="mt-1">{{ outcome.review.reason }}</p>
                <p class="mt-1 text-xs text-muted-foreground">
                    {{ outcome.review.reviewer_name }} ·
                    {{ time(outcome.review.reviewed_at) }} ·
                    {{
                        outcome.review.decision === 'sent'
                            ? 'Recorded as sent'
                            : 'Recorded as not sent'
                    }}
                </p>
            </div>
            <dl
                class="grid gap-3 rounded-xl border bg-muted/20 p-4 text-sm sm:grid-cols-2"
            >
                <div>
                    <dt class="text-xs text-muted-foreground">Website brand</dt>
                    <dd class="mt-1 font-medium">
                        {{ preview.brand_name || context.profile.brand_name }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">From</dt>
                    <dd class="mt-1 break-words">
                        {{ preview.sender.name }} &lt;{{
                            preview.sender.email
                        }}&gt;
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">To</dt>
                    <dd class="mt-1 break-words">{{ preview.recipient }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">Reply-To</dt>
                    <dd class="mt-1 break-words">{{ preview.reply_to }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-xs text-muted-foreground">Subject</dt>
                    <dd class="mt-1 font-semibold">{{ preview.subject }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">Order action</dt>
                    <dd class="mt-1">
                        {{
                            preview.will_complete
                                ? 'Complete after confirmed sending'
                                : 'Keep current order status'
                        }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">
                        Extra store completion email
                    </dt>
                    <dd class="mt-1">
                        {{
                            preview.will_complete && preview.suppression
                                ? 'Suppress with verified order marker before completion'
                                : 'No completion notification is triggered by this action'
                        }}
                    </dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-xs text-muted-foreground">
                        Saved versions
                    </dt>
                    <dd class="mt-1 break-all">
                        Documents: {{ preview.document_set_version }} ·
                        Brand/template: {{ preview.config_version }}
                    </dd>
                </div>
            </dl>
            <DocumentEmailPreview :html="preview.html" />
            <section class="rounded-lg border p-4">
                <h3 class="text-sm font-semibold">
                    {{ preview.attachments.length }} actual PDF attachment{{
                        preview.attachments.length === 1 ? '' : 's'
                    }}
                </h3>
                <ul class="mt-3 space-y-2">
                    <li
                        v-for="(file, index) in preview.attachments"
                        :key="index"
                        class="flex items-start justify-between gap-3 text-sm"
                    >
                        <span class="break-words"
                            >{{ file.name
                            }}<span
                                v-if="file.version"
                                class="ml-2 text-xs text-muted-foreground"
                                >v{{ file.version }}</span
                            ></span
                        ><span class="shrink-0 text-xs text-muted-foreground">{{
                            bytes(file.size)
                        }}</span>
                    </li>
                </ul>
            </section>
            <details class="text-sm">
                <summary class="cursor-pointer text-muted-foreground">
                    Plain-text alternative
                </summary>
                <pre
                    class="mt-2 rounded-lg bg-muted/20 p-3 font-sans text-sm whitespace-pre-wrap"
                    >{{ preview.text || preview.body }}</pre
                >
            </details>
            <div class="flex flex-wrap justify-end gap-2 border-t pt-4">
                <Button
                    variant="outline"
                    :disabled="busy || pendingOutcome"
                    @click="showPreview = false"
                    >Back</Button
                ><Button
                    variant="outline"
                    :disabled="busy"
                    @click="view(preview.id)"
                    ><RefreshCw class="size-4" />Refresh status</Button
                ><a
                    v-if="gmailUrl"
                    :href="gmailUrl"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex items-center gap-2 rounded-md border px-3 py-2 text-sm"
                    >View sent message<ExternalLink class="size-3" /></a
                ><Button
                    v-if="
                        status === 'sent' &&
                        preview.will_complete &&
                        !['completed', 'synced'].includes(
                            outcome?.sync_status ?? '',
                        )
                    "
                    :disabled="busy"
                    @click="retrySync"
                    >Retry order update only</Button
                ><Button
                    v-if="['prepared', 'failed'].includes(status ?? '')"
                    :disabled="!canSend"
                    @click="send"
                    ><Loader2 v-if="busy" class="size-4 animate-spin" /><Send
                        v-else
                        class="size-4"
                    />{{
                        status === 'failed'
                            ? 'Retry sending reviewed email'
                            : preview.will_complete
                              ? 'Send documents & complete order'
                              : 'Send documents'
                    }}</Button
                ><Button v-else :disabled="busy" @click="emit('close')"
                    >Close</Button
                >
            </div>
        </div>
        <section
            v-if="context.deliveries.length"
            class="space-y-3 border-t pt-4"
            aria-labelledby="document-history-heading"
        >
            <h3 id="document-history-heading" class="text-sm font-semibold">
                Document sending history
            </h3>
            <div
                v-for="item in context.deliveries"
                :key="item.id"
                class="space-y-2 rounded-lg border p-3"
            >
                <p class="text-sm font-medium">{{ item.subject }}</p>
                <p class="text-xs break-words text-muted-foreground">
                    {{ item.sender.email }} → {{ item.recipient }}
                </p>
                <p class="text-xs text-muted-foreground">
                    {{
                        item.captured
                            ? deliveryTimestamp(item)
                            : item.status === 'sent'
                              ? deliveryTimestamp(item)
                              : item.status === 'reviewed_sent'
                                ? 'Recorded as sent after operator review'
                                : item.status === 'reviewed_not_sent'
                                  ? 'Recorded as not sent after operator review'
                                  : item.status
                    }}
                    · {{ item.attachments.length }} PDFs · Order sync:
                    {{ item.sync_status || 'Not required' }}
                </p>
                <div class="flex flex-wrap gap-2">
                    <Button
                        size="sm"
                        variant="outline"
                        :disabled="busy"
                        @click="view(item.id)"
                        >View</Button
                    ><template
                        v-if="['sent', 'reviewed_sent'].includes(item.status)"
                        ><Button
                            size="sm"
                            variant="ghost"
                            :disabled="busy || pendingOutcome"
                            @click="newAttempt(item, 'resend')"
                            >Resend documents</Button
                        ><Button
                            size="sm"
                            variant="ghost"
                            :disabled="busy || pendingOutcome"
                            @click="newAttempt(item, 'correction')"
                            >Send corrected documents</Button
                        ></template
                    >
                </div>
            </div>
        </section>
    </div>
</template>

<style scoped>
@reference "../../css/app.css";
.document-input {
    @apply block w-full min-w-0 rounded-md border bg-background px-3 py-2 text-sm font-normal outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-60;
}
</style>
