import type { RefreshOutcome } from '@/lib/liveOrders';
import type {
    UsefulAlertCenter,
    UsefulAlertKind,
    UsefulAlertStatus,
} from '@/types/usefulAlerts';
import axios from 'axios';

export const usefulAlertKinds: Array<{
    key: UsefulAlertKind;
    label: string;
    countLabel: string;
}> = [
    {
        key: 'webhook_failed',
        label: 'Failed webhooks',
        countLabel: 'failed webhook records',
    },
    {
        key: 'webhook_stalled',
        label: 'Waiting webhooks',
        countLabel: 'waiting webhook records',
    },
    {
        key: 'email_attention',
        label: 'Email attention',
        countLabel: 'email records to review',
    },
    {
        key: 'processing_aged',
        label: 'Older processing orders',
        countLabel: 'processing orders',
    },
];
export const usefulAlertStatuses: Array<{
    key: UsefulAlertStatus;
    label: string;
    description: string;
    classes: string;
}> = [
    {
        key: 'active',
        label: 'Active',
        description: 'Issues that need a review',
        classes: 'bg-amber-500/10 text-amber-800 dark:text-amber-300',
    },
    {
        key: 'snoozed',
        label: 'Snoozed',
        description: 'Reminders paused temporarily',
        classes: 'bg-sky-500/10 text-sky-700 dark:text-sky-300',
    },
    {
        key: 'resolved',
        label: 'Resolved',
        description: 'No longer detected by a check',
        classes: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
    },
];
export function usefulAlertKind(kind: UsefulAlertKind) {
    return usefulAlertKinds.find((item) => item.key === kind)!;
}
export function usefulAlertStatus(status: UsefulAlertStatus) {
    return usefulAlertStatuses.find((item) => item.key === status)!;
}
export function usefulAlertActionUrl(value: unknown): value is string {
    if (
        typeof value !== 'string' ||
        !value.startsWith('/') ||
        value.startsWith('//') ||
        /\s|\\/u.test(value) ||
        Array.from(value).some(
            (character) =>
                character.charCodeAt(0) < 32 || character.charCodeAt(0) === 127,
        )
    )
        return false;
    try {
        return (
            new URL(value, 'https://wphub.invalid').origin ===
            'https://wphub.invalid'
        );
    } catch {
        return false;
    }
}
const object = (value: unknown): value is Record<string, unknown> =>
    !!value && typeof value === 'object' && !Array.isArray(value);
const count = (value: unknown): value is number =>
    Number.isSafeInteger(value) && Number(value) >= 0;
const positive = (value: unknown): value is number =>
    count(value) && Number(value) > 0;
const date = (value: unknown): value is string =>
    typeof value === 'string' && Number.isFinite(Date.parse(value));
export function readUsefulAlertCenter(value: unknown): UsefulAlertCenter {
    if (
        !object(value) ||
        !Array.isArray(value.data) ||
        !object(value.summary) ||
        !usefulAlertStatuses.every((status) =>
            count((value.summary as Record<string, unknown>)[status.key]),
        ) ||
        !(value.checked_at === null || date(value.checked_at)) ||
        !object(value.thresholds) ||
        !positive(value.thresholds.webhook_minutes) ||
        !positive(value.thresholds.processing_hours) ||
        !positive(value.current_page) ||
        !positive(value.last_page) ||
        !positive(value.per_page) ||
        value.per_page > 25 ||
        !count(value.total) ||
        !(value.from === null || positive(value.from)) ||
        !(value.to === null || positive(value.to)) ||
        value.data.length > value.per_page
    )
        throw new Error('Invalid alerts snapshot');
    const ids = new Set<number>();
    for (const alert of value.data) {
        if (
            !object(alert) ||
            !positive(alert.id) ||
            ids.has(alert.id) ||
            !usefulAlertKinds.some((kind) => kind.key === alert.kind) ||
            !usefulAlertStatuses.some(
                (status) => status.key === alert.status,
            ) ||
            !['warning', 'critical'].includes(String(alert.severity)) ||
            ![alert.title, alert.message, alert.action_label].every(
                (item) => typeof item === 'string',
            ) ||
            !count(alert.count) ||
            !object(alert.website) ||
            !positive(alert.website.id) ||
            typeof alert.website.name !== 'string' ||
            !date(alert.first_detected_at) ||
            !date(alert.last_detected_at) ||
            !(alert.resolved_at === null || date(alert.resolved_at)) ||
            !(alert.snoozed_until === null || date(alert.snoozed_until)) ||
            !usefulAlertActionUrl(alert.action_url)
        )
            throw new Error('Invalid alert record');
        if (
            alert.examples !== undefined &&
            (!Array.isArray(alert.examples) ||
                alert.examples.length > 3 ||
                !alert.examples.every(
                    (example) =>
                        object(example) &&
                        positive(example.id) &&
                        typeof example.label === 'string' &&
                        usefulAlertActionUrl(example.url),
                ))
        )
            throw new Error('Invalid alert examples');
        ids.add(alert.id);
    }
    return {
        data: value.data,
        summary: value.summary,
        checked_at: value.checked_at,
        thresholds: value.thresholds,
        current_page: value.current_page,
        last_page: value.last_page,
        per_page: value.per_page,
        total: value.total,
        from: value.from,
        to: value.to,
    } as UsefulAlertCenter;
}
export function usefulAlertError(error: unknown, fallback: string): string {
    if (axios.isAxiosError(error)) {
        if ([401, 419].includes(error.response?.status ?? 0))
            return 'Your session expired. Reload WP Hub and sign in again.';
        if ([403, 404].includes(error.response?.status ?? 0))
            return 'This alert is no longer available, or your access changed. Refresh the view before continuing.';
        if (error.response?.status === 429)
            return 'Please wait a moment before trying again.';
        if (
            typeof error.response?.data?.message === 'string' &&
            error.response.data.message.trim()
        )
            return error.response.data.message;
    }
    return fallback;
}
/** Bounds both the JSON read and an Inertia updater waiting behind navigation. */
export async function refreshUsefulAlertsSnapshot(options: {
    getUrl: () => string;
    isCurrent: () => boolean;
    signal: AbortSignal;
    apply: (
        center: UsefulAlertCenter,
        isCurrent: () => boolean,
    ) => Promise<boolean>;
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
            const { data } = await axios.get(url, {
                signal: controller.signal,
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                timeout: 20_000,
            });
            const center = readUsefulAlertCenter(data.alertCenter);
            if (!current()) return 'cancelled';
            const applied = await options.apply(center, current);
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
