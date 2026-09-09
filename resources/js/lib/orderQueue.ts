import type { RefreshOutcome } from '@/lib/liveOrders';
import type {
    OrderQueueRow,
    OrderQueueSnapshot,
    OrderQueueStage,
} from '@/types/orderQueue';

export const orderQueueStages: Array<{
    key: OrderQueueStage;
    label: string;
    description: string;
    classes: string;
}> = [
    {
        key: 'prepare',
        label: 'To prepare',
        description: 'Review the order and prepare its email.',
        classes: 'bg-indigo-500/10 text-indigo-700 dark:text-indigo-300',
    },
    {
        key: 'ready',
        label: 'Ready to send',
        description: 'A saved preview is ready for review.',
        classes: 'bg-teal-500/10 text-teal-700 dark:text-teal-300',
    },
    {
        key: 'sending',
        label: 'Sending',
        description: 'Check the current send result.',
        classes: 'bg-sky-500/10 text-sky-700 dark:text-sky-300',
    },
    {
        key: 'sent',
        label: 'Sent',
        description: 'Gmail accepted an email; review completion.',
        classes: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
    },
    {
        key: 'attention',
        label: 'Needs attention',
        description: 'Review a failed or uncertain send.',
        classes: 'bg-rose-500/10 text-rose-700 dark:text-rose-300',
    },
    {
        key: 'waiting',
        label: 'Waiting',
        description: 'Review a pending or on-hold Woo order.',
        classes: 'bg-amber-500/10 text-amber-800 dark:text-amber-300',
    },
];
export function orderQueueStage(stage: OrderQueueStage) {
    return orderQueueStages.find((item) => item.key === stage)!;
}
export function orderQueueNextAction(order: OrderQueueRow): string {
    switch (order.stage) {
        case 'attention':
            return order.email?.status === 'failed'
                ? 'Review the failed email and connection before explicitly trying again.'
                : 'Check Gmail Sent and refresh the email status before sending another message.';
        case 'waiting':
            return 'Review the WooCommerce order status and customer details before proceeding.';
        case 'ready':
            return 'Review the saved message and attachments, then confirm Send.';
        case 'sending':
            return 'Refresh the email status to confirm the result. Do not send again.';
        case 'sent':
            return 'Gmail accepted an email. Review the order and mark it completed when your work is finished.';
        default:
            return order.email?.status === 'expired'
                ? 'The saved preview expired. Review the draft and prepare a fresh preview.'
                : 'Review the reservation, attach your booking PDFs, and prepare the email.';
    }
}
export function orderQueueEmailLabel(order: OrderQueueRow): string {
    if (!order.email) return 'No WP Hub email recorded';
    return {
        prepared: 'Saved preview',
        sending: 'Send in progress',
        sent: 'Gmail accepted',
        failed: 'Email failed',
        uncertain: 'Send result uncertain',
        expired: 'Preview expired',
    }[order.email.status];
}
export function orderQueueAge(value: string | null, asOf: string): string {
    if (!value || !Number.isFinite(Date.parse(value)))
        return 'Date unavailable';
    const minutes = Math.max(
        0,
        Math.floor((Date.parse(asOf) - Date.parse(value)) / 60_000),
    );
    if (!Number.isFinite(minutes)) return 'Date unavailable';
    if (minutes < 60) return minutes < 1 ? 'Just now' : `${minutes}m`;
    if (minutes < 1440) return `${Math.floor(minutes / 60)}h ${minutes % 60}m`;
    return `${Math.floor(minutes / 1440)}d ${Math.floor((minutes % 1440) / 60)}h`;
}
const object = (value: unknown): value is Record<string, unknown> =>
    !!value && typeof value === 'object' && !Array.isArray(value);
const positive = (value: unknown): value is number =>
    Number.isSafeInteger(value) && Number(value) > 0;
const count = (value: unknown): value is number =>
    Number.isSafeInteger(value) && Number(value) >= 0;
const date = (value: unknown): boolean =>
    value === null ||
    (typeof value === 'string' && Number.isFinite(Date.parse(value)));
const text = (value: unknown): boolean =>
    value === null || typeof value === 'string';
export function readOrderQueue(value: unknown): OrderQueueSnapshot {
    if (
        !object(value) ||
        !Array.isArray(value.data) ||
        !positive(value.current_page) ||
        !positive(value.last_page) ||
        !positive(value.per_page) ||
        value.per_page > 50 ||
        !count(value.total) ||
        !(value.from === null || positive(value.from)) ||
        !(value.to === null || positive(value.to)) ||
        !object(value.summary) ||
        !['all', ...orderQueueStages.map((stage) => stage.key)].every((key) =>
            count((value.summary as Record<string, unknown>)[key]),
        ) ||
        typeof value.generated_at !== 'string' ||
        !date(value.generated_at) ||
        typeof value.timezone !== 'string' ||
        !value.timezone ||
        value.data.length > value.per_page
    )
        throw new Error('Invalid order queue');
    const ids = new Set<number>();
    for (const row of value.data) {
        if (
            !object(row) ||
            !positive(row.id) ||
            ids.has(row.id) ||
            !positive(row.wp_order_id) ||
            !positive(row.website_id) ||
            !object(row.website) ||
            row.website.id !== row.website_id ||
            typeof row.website.name !== 'string' ||
            typeof row.status !== 'string' ||
            !row.status ||
            typeof row.can_update_status !== 'boolean' ||
            !text(row.customer_name) ||
            !text(row.customer_email) ||
            !text(row.currency) ||
            !(
                ['string', 'number'].includes(typeof row.total) &&
                String(row.total).trim() &&
                Number.isFinite(Number(row.total))
            ) ||
            !date(row.created_at_wp) ||
            !orderQueueStages.some((stage) => stage.key === row.stage) ||
            !(row.submission_id === null || positive(row.submission_id)) ||
            !(
                row.email === null ||
                (object(row.email) &&
                    [
                        'prepared',
                        'sending',
                        'sent',
                        'failed',
                        'uncertain',
                        'expired',
                    ].includes(String(row.email.status)) &&
                    date(row.email.created_at) &&
                    date(row.email.sent_at) &&
                    date(row.email.expires_at))
            )
        )
            throw new Error('Invalid order queue row');
        ids.add(row.id);
    }
    // Return the approved page fields only; shared Inertia auth and flash stay untouched.
    return {
        data: value.data,
        current_page: value.current_page,
        last_page: value.last_page,
        per_page: value.per_page,
        total: value.total,
        from: value.from,
        to: value.to,
        summary: value.summary,
        generated_at: value.generated_at,
        timezone: value.timezone,
    } as OrderQueueSnapshot;
}
export async function refreshOrderQueueSnapshot(options: {
    getUrl: () => string;
    isCurrent: () => boolean;
    signal: AbortSignal;
    apply: (
        snapshot: OrderQueueSnapshot,
        isCurrent: () => boolean,
    ) => Promise<boolean>;
    fetch?: typeof fetch;
    setTimer?: typeof setTimeout;
    clearTimer?: typeof clearTimeout;
}): Promise<RefreshOutcome> {
    const url = options.getUrl(),
        parentCurrent = () =>
            !options.signal.aborted &&
            options.isCurrent() &&
            options.getUrl() === url;
    if (!parentCurrent()) return 'cancelled';
    const controller = new AbortController(),
        current = () => parentCurrent() && !controller.signal.aborted;
    const abort = () => controller.abort();
    options.signal.addEventListener('abort', abort, { once: true });
    const deadline = (options.setTimer ?? setTimeout)(abort, 20_000);
    let onAbort = () => {};
    const interrupted = new Promise<RefreshOutcome>((resolve) => {
        onAbort = () => resolve(parentCurrent() ? 'error' : 'cancelled');
        controller.signal.addEventListener('abort', onAbort, { once: true });
    });
    const operation = async (): Promise<RefreshOutcome> => {
        try {
            const response = await (options.fetch ?? fetch)(url, {
                credentials: 'same-origin',
                cache: 'no-store',
                signal: controller.signal,
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            if (!response.ok) throw new Error('Queue unavailable');
            const snapshot = readOrderQueue((await response.json()).queue);
            if (!current()) return 'cancelled';
            const applied = await options.apply(snapshot, current);
            return applied && current() ? 'success' : 'cancelled';
        } catch {
            return parentCurrent() ? 'error' : 'cancelled';
        }
    };
    try {
        return await Promise.race([operation(), interrupted]);
    } finally {
        (options.clearTimer ?? clearTimeout)(deadline);
        controller.signal.removeEventListener('abort', onAbort);
        options.signal.removeEventListener('abort', abort);
    }
}
