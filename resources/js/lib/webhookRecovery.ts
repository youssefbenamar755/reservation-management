import type { DeliveryDetail } from '@/types/websiteHealth';

export interface RecoveryState {
    eventId: number | null;
    detail: DeliveryDetail | null;
    loading: boolean;
    retrying: boolean;
    error: string;
    notice: string;
}

/** Owns just user-requested detail/retry calls; never polls. */
export function createWebhookRecovery(options: {
    fetch: typeof fetch;
    csrf: () => string;
    onState: (state: RecoveryState) => void;
    onRetrySettled: () => void;
}) {
    let state: RecoveryState = {
        eventId: null,
        detail: null,
        loading: false,
        retrying: false,
        error: '',
        notice: '',
    };
    let generation = 0;
    let getController: AbortController | null = null;
    let disposed = false;
    let retryInFlight = false;
    const emit = (patch: Partial<RecoveryState>) => {
        state = { ...state, ...patch, retrying: retryInFlight };
        if (!disposed) options.onState({ ...state });
    };

    async function open(eventId: number, preserveNotice = false) {
        if (disposed) return;
        const request = ++generation;
        getController?.abort();
        const controller = new AbortController();
        getController = controller;
        emit({
            eventId,
            detail: null,
            loading: true,
            error: '',
            notice: preserveNotice ? state.notice : '',
        });
        let failureMessage =
            'Delivery details could not be loaded. Please try again.';
        try {
            const response = await options.fetch(
                `/website-health/events/${eventId}`,
                {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                    cache: 'no-store',
                    signal: controller.signal,
                },
            );
            if (!response.ok) {
                if (response.status === 403 || response.status === 404)
                    failureMessage =
                        'This delivery is unavailable or you no longer have access.';
                throw new Error('Unavailable');
            }
            const detail = (await response.json()) as DeliveryDetail;
            if (
                detail?.event?.id !== eventId ||
                !Array.isArray(detail.attempts)
            )
                throw new Error(
                    'Delivery details could not be loaded. Please try again.',
                );
            if (!disposed && generation === request) emit({ detail });
        } catch {
            if (
                !disposed &&
                generation === request &&
                !controller.signal.aborted
            )
                emit({ error: failureMessage });
        } finally {
            if (!disposed && generation === request) emit({ loading: false });
        }
    }

    async function retry() {
        const eventId = state.eventId;
        if (
            disposed ||
            retryInFlight ||
            state.loading ||
            !eventId ||
            !state.detail?.event.can_retry
        )
            return;
        retryInFlight = true;
        let accepted = false;
        emit({ error: '', notice: '' });
        try {
            const response = await options.fetch(
                `/website-health/events/${eventId}/retry`,
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': options.csrf(),
                    },
                    body: '{}',
                },
            );
            if (!response.ok) {
                const message =
                    response.status === 429
                        ? 'Too many retry requests. Wait a minute, then refresh the delivery.'
                        : response.status === 409 || response.status === 422
                          ? 'This delivery is no longer eligible for retry. Refresh its details to see the latest status.'
                          : response.status === 403 || response.status === 419
                            ? 'Your session or access has changed. Reload the page before retrying.'
                            : 'The retry could not be confirmed. Refresh the delivery before trying again.';
                if (!disposed && state.eventId === eventId)
                    emit({ error: message });
                return;
            }
            accepted = true;
            if (!disposed && state.eventId === eventId) {
                emit({
                    notice: 'Retry requested. Check the latest attempt below for its result.',
                });
                await open(eventId, true);
            }
        } catch {
            if (!disposed && state.eventId === eventId)
                emit({
                    error: 'The retry could not be confirmed. Refresh the delivery before trying again.',
                });
        } finally {
            retryInFlight = false;
            if (!accepted && state.eventId === eventId && state.detail) {
                emit({
                    detail: {
                        ...state.detail,
                        event: {
                            ...state.detail.event,
                            can_retry: false,
                            retry_reason:
                                'Refresh this delivery to confirm its latest status before retrying.',
                        },
                    },
                });
            }
            emit({});
            // A failed network response may still have reached the server.
            if (!disposed) options.onRetrySettled();
        }
    }

    function close() {
        generation++;
        getController?.abort();
        emit({
            eventId: null,
            detail: null,
            loading: false,
            error: '',
            notice: '',
        });
    }

    return {
        open,
        retry,
        close,
        dispose() {
            disposed = true;
            close();
        },
    };
}
