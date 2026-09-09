<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { useEchoNotifications } from '@/composables/useEchoNotifications';
import AppLayout from '@/layouts/AppLayout.vue';
import { createAutoRefresh, type AutoRefreshState } from '@/lib/liveOrders';
import {
    readUsefulAlertCenter,
    refreshUsefulAlertsSnapshot,
    usefulAlertActionUrl,
    usefulAlertError,
    usefulAlertKind,
    usefulAlertKinds,
    usefulAlertStatus,
    usefulAlertStatuses,
} from '@/lib/usefulAlerts';
import type {
    UsefulAlert,
    UsefulAlertCenter,
    UsefulAlertProps,
} from '@/types/usefulAlerts';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import {
    ArrowRight,
    BellOff,
    BellRing,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    CircleAlert,
    Clock3,
    Globe,
    Inbox,
    LoaderCircle,
    Mail,
    RefreshCw,
    RotateCcw,
    ShieldCheck,
    TriangleAlert,
    Webhook,
    X,
} from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

const props = defineProps<UsefulAlertProps>();
const page = usePage();
const inputs = ref(appliedFilters());
const filterLoading = ref(false),
    filterErrors = ref<Record<string, string>>({});
const online = ref(true),
    visible = ref(true),
    notificationsReady = ref(false);
const refreshState = ref<AutoRefreshState>({
    refreshing: false,
    hasError: false,
    lastCheckedAt: null,
});
const checking = ref(false),
    rowAction = ref<{ id: number; type: 'snooze' | 'resume' } | null>(null);
const actionError = ref(''),
    actionErrorId = ref<number | null>(null),
    actionSuccess = ref('');
const busy = computed(() => checking.value || rowAction.value !== null);
const scopeName = computed(
    () =>
        props.websites.find((site) => site.id === props.filters.website_id)
            ?.name ?? 'All websites',
);
const kindName = computed(() =>
    props.filters.kind === 'all'
        ? 'All categories'
        : usefulAlertKind(props.filters.kind).label,
);
const statusName = computed(() =>
    props.filters.status === 'all'
        ? 'All alerts'
        : `${usefulAlertStatus(props.filters.status).label} alerts`,
);
const hasFilters = computed(() =>
    Boolean(
        props.filters.website_id ||
        props.filters.status !== 'active' ||
        props.filters.kind !== 'all',
    ),
);
const refreshLabel = computed(() =>
    !online.value
        ? 'Offline · refresh when connected'
        : checking.value
          ? 'Checking stored records…'
          : refreshState.value.refreshing
            ? 'Updating alerts…'
            : refreshState.value.hasError
              ? 'Refresh needed'
              : notificationsReady.value
                ? 'Notification updates enabled'
                : 'Updates on focus · Check now available',
);
let mounted = false,
    disposed = false,
    activeUserId: number | null = null,
    actionGeneration = 0;
let actionRequest: AbortController | null = null;
let filterRequest: { key: string; cancel?: () => void } | null = null;
let dispatchingFilters = false;
const navigationVisits = new Set<object>(),
    cleanupListeners: Array<() => void> = [];
const { onNotification, offNotification } = useEchoNotifications();
const userId = () => page.props.auth?.user?.id;
const available = () =>
    mounted &&
    !disposed &&
    activeUserId !== null &&
    activeUserId === userId() &&
    online.value &&
    visible.value &&
    window.location.pathname === '/alerts';
const liveRefresh = createAutoRefresh({
    isAvailable: available,
    onState: (state) => {
        refreshState.value = state;
    },
    refresh: ({ isCurrent, complete }) => {
        const controller = new AbortController(),
            owner = activeUserId;
        void refreshUsefulAlertsSnapshot({
            getUrl: () => window.location.href,
            isCurrent: () =>
                isCurrent() && activeUserId === owner && userId() === owner,
            signal: controller.signal,
            apply: applyCenter,
        }).then(complete);
        return () => controller.abort();
    },
});
function scopedCenter(center: UsefulAlertCenter) {
    const ids = new Set(props.websites.map((site) => site.id));
    if (
        center.data.some(
            (alert) =>
                !ids.has(alert.website.id) ||
                (props.filters.website_id !== null &&
                    alert.website.id !== props.filters.website_id) ||
                (props.filters.kind !== 'all' &&
                    alert.kind !== props.filters.kind) ||
                (props.filters.status !== 'all' &&
                    alert.status !== props.filters.status),
        )
    )
        throw new Error('Invalid alerts scope');
    return center;
}
function applyCenter(
    value: UsefulAlertCenter,
    isCurrent: () => boolean,
): Promise<boolean> {
    const center = scopedCenter(value);
    return new Promise((resolve) => {
        let applied = false;
        router.replaceProp(
            'alertCenter',
            (current: unknown) => {
                if (!isCurrent()) return current;
                applied = true;
                return center;
            },
            { onFinish: () => resolve(applied) },
        );
    });
}
function appliedFilters() {
    return {
        website_id: String(props.filters.website_id ?? ''),
        status: props.filters.status || 'active',
        kind: props.filters.kind || 'all',
    };
}
function cancelAction(clearMessages = true) {
    actionGeneration++;
    actionRequest?.abort();
    actionRequest = null;
    checking.value = false;
    rowAction.value = null;
    if (clearMessages) {
        actionError.value = '';
        actionErrorId.value = null;
        actionSuccess.value = '';
    }
}
function cancelFilters(restore = false) {
    const previous = filterRequest;
    filterRequest = null;
    filterLoading.value = false;
    previous?.cancel?.();
    if (previous)
        queueMicrotask(() => {
            if (
                mounted &&
                !disposed &&
                !filterRequest &&
                !navigationVisits.size &&
                !busy.value
            )
                liveRefresh.resume();
        });
    if (restore) {
        inputs.value = appliedFilters();
        filterErrors.value = {};
    }
}
function submitFilters(targetPage = 1) {
    const data = {
            ...inputs.value,
            ...(targetPage > 1 ? { page: targetPage } : {}),
        },
        key = JSON.stringify(data);
    if (filterRequest?.key === key) return;
    cancelFilters();
    if (
        disposed ||
        (targetPage === props.alertCenter.current_page &&
            JSON.stringify(inputs.value) === JSON.stringify(appliedFilters()))
    )
        return;
    cancelAction();
    liveRefresh.suspend();
    const request: { key: string; cancel?: () => void } = { key };
    filterRequest = request;
    filterLoading.value = true;
    filterErrors.value = {};
    dispatchingFilters = true;
    try {
        router.get('/alerts', data, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onCancelToken: (token) => {
                if (filterRequest !== request) token.cancel();
                else request.cancel = () => token.cancel();
            },
            onSuccess: () => {
                if (filterRequest === request) inputs.value = appliedFilters();
            },
            onError: (errors) => {
                if (filterRequest === request) filterErrors.value = errors;
            },
            onFinish: () => {
                if (disposed || filterRequest !== request) return;
                filterRequest = null;
                filterLoading.value = false;
                if (!navigationVisits.size && !busy.value) liveRefresh.resume();
            },
        });
    } finally {
        dispatchingFilters = false;
    }
}
function updateFilter(key: 'website_id' | 'kind' | 'status', value: string) {
    Object.assign(inputs.value, { [key]: value });
    submitFilters();
}
function resetFilters() {
    inputs.value = { website_id: '', kind: 'all', status: 'active' };
    submitFilters();
}
function goToPage(number: number) {
    if (number < 1 || number > props.alertCenter.last_page) return;
    cancelFilters(true);
    submitFilters(number);
}
function requestForNotification(value: {
    type: string;
    data?: { website_id?: number };
}) {
    if (value.type !== 'useful_alert') return;
    if (
        props.filters.website_id &&
        value.data?.website_id &&
        props.filters.website_id !== value.data.website_id
    )
        return;
    liveRefresh.requestFresh();
}
function bindUser() {
    liveRefresh.suspend();
    offNotification('useful-alert-center');
    notificationsReady.value = false;
    activeUserId = null;
    const id = userId();
    if (!Number.isSafeInteger(id) || !id || id < 1) return;
    activeUserId = id;
    const current = () => mounted && userId() === id && activeUserId === id;
    onNotification(
        'useful-alert-center',
        (notification) => {
            if (current()) requestForNotification(notification);
        },
        id,
        () => {
            if (current()) {
                notificationsReady.value = true;
                liveRefresh.requestFresh();
            }
        },
    );
    if (!filterLoading.value && !navigationVisits.size && !busy.value)
        liveRefresh.resume();
}
function updateAvailability() {
    online.value = navigator.onLine;
    visible.value = !document.hidden;
    if (!online.value || !visible.value) cancelAction(false);
    liveRefresh.availabilityChanged();
}
function stopForHistory() {
    cancelFilters(true);
    cancelAction();
    liveRefresh.suspend();
}
function refreshView() {
    if (!busy.value && !filterLoading.value) liveRefresh.requestFresh();
}
async function runAction(
    type: 'check' | 'snooze' | 'resume',
    alert?: UsefulAlert,
) {
    if (!available() || busy.value || filterLoading.value) return;
    if (
        type !== 'check' &&
        (!alert ||
            !props.alertCenter.data.some((row) => row.id === alert.id) ||
            (type === 'snooze'
                ? alert.status !== 'active'
                : alert.status !== 'snoozed'))
    )
        return;
    cancelAction();
    liveRefresh.suspend();
    const token = actionGeneration,
        owner = activeUserId,
        url = window.location.href,
        controller = new AbortController();
    actionRequest = controller;
    if (type === 'check') checking.value = true;
    else rowAction.value = { id: alert!.id, type };
    const owns = () =>
        !disposed &&
        token === actionGeneration &&
        owner === activeUserId &&
        userId() === owner &&
        url === window.location.href;
    const current = () => owns() && !controller.signal.aborted;
    let timedOut = false;
    let deadline: ReturnType<typeof setTimeout> | null = null;
    const timeout = new Promise<never>((_, reject) => {
        deadline = setTimeout(() => {
            timedOut = true;
            controller.abort();
            reject(new Error('Action timed out'));
        }, 35_000);
    });
    const operation = async () => {
        const endpoint =
            type === 'check'
                ? '/alerts/refresh'
                : `/alerts/${alert!.id}/${type}`;
        const body =
            type === 'check'
                ? { ...props.filters, page: props.alertCenter.current_page }
                : {};
        const { data } = await axios.post(endpoint, body, {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
            timeout: 30_000,
        });
        if (!current()) return;
        if (type === 'check') {
            await applyCenter(readUsefulAlertCenter(data.alertCenter), current);
        } else if (typeof data?.message !== 'string')
            throw new Error('Invalid action response');
        if (!current()) return;
        window.dispatchEvent(new Event('notifications:changed'));
        actionSuccess.value =
            type === 'check'
                ? 'Stored records checked. Alerts are up to date for this check.'
                : type === 'snooze'
                  ? 'Alert snoozed for 24 hours. The underlying issue is still checked.'
                  : 'Alert resumed.';
    };
    try {
        await Promise.race([operation(), timeout]);
    } catch (error) {
        if (owns()) {
            actionErrorId.value = alert?.id ?? null;
            actionError.value = timedOut
                ? 'The result could not be confirmed. Refresh the view before trying again.'
                : usefulAlertError(
                      error,
                      type === 'check'
                          ? 'The check could not be completed. Refresh the view or try Check now again.'
                          : 'The change could not be confirmed. Refresh the view before trying again.',
                  );
        }
    } finally {
        if (deadline !== null) clearTimeout(deadline);
        if (owns()) {
            checking.value = false;
            rowAction.value = null;
            actionRequest = null;
            liveRefresh.requestFresh();
            if (!filterLoading.value && !navigationVisits.size)
                liveRefresh.resume();
        }
    }
}
function dateLabel(value: string | null) {
    if (!value) return 'Not checked yet';
    const date = new Date(value);
    return Number.isFinite(date.getTime())
        ? date.toLocaleString(undefined, {
              year: 'numeric',
              month: 'short',
              day: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
          })
        : 'Date unavailable';
}
function kindIcon(kind: UsefulAlert['kind']) {
    return kind === 'email_attention'
        ? Mail
        : kind === 'processing_aged'
          ? Clock3
          : Webhook;
}
watch(
    () => props.filters,
    () => {
        if (!filterRequest && !Object.keys(filterErrors.value).length)
            inputs.value = appliedFilters();
    },
    { deep: true },
);
watch(
    () => page.url,
    () => {
        if (!mounted) return;
        liveRefresh.suspend();
        cancelAction();
        if (!filterLoading.value && !navigationVisits.size)
            liveRefresh.resume();
    },
    { flush: 'sync' },
);
watch(
    userId,
    () => {
        cancelAction();
        cancelFilters();
        if (mounted) bindUser();
    },
    { flush: 'sync' },
);
onMounted(() => {
    mounted = true;
    online.value = navigator.onLine;
    visible.value = !document.hidden;
    liveRefresh.start();
    cleanupListeners.push(
        router.on('before', ({ detail: { visit } }) => {
            if (visit.async) return;
            if (!dispatchingFilters) cancelFilters(true);
            cancelAction();
            liveRefresh.suspend();
            queueMicrotask(() => {
                if (
                    mounted &&
                    !filterLoading.value &&
                    !navigationVisits.size &&
                    !busy.value
                )
                    liveRefresh.resume();
            });
        }),
        router.on('start', ({ detail: { visit } }) => {
            if (visit.async) return;
            navigationVisits.add(visit);
            cancelAction();
            liveRefresh.suspend();
        }),
        router.on('finish', ({ detail: { visit } }) => {
            if (visit.async) return;
            navigationVisits.delete(visit);
            if (
                mounted &&
                !filterLoading.value &&
                !navigationVisits.size &&
                !busy.value
            )
                liveRefresh.resume();
        }),
        router.on('navigate', () => {
            if (
                mounted &&
                !filterLoading.value &&
                !navigationVisits.size &&
                !busy.value
            )
                liveRefresh.resume();
        }),
    );
    document.addEventListener('visibilitychange', updateAvailability);
    window.addEventListener('focus', updateAvailability);
    window.addEventListener('online', updateAvailability);
    window.addEventListener('offline', updateAvailability);
    window.addEventListener('popstate', stopForHistory);
    bindUser();
});
onUnmounted(() => {
    disposed = true;
    mounted = false;
    cancelAction();
    cancelFilters();
    liveRefresh.stop();
    offNotification('useful-alert-center');
    cleanupListeners.forEach((remove) => remove());
    navigationVisits.clear();
    document.removeEventListener('visibilitychange', updateAvailability);
    window.removeEventListener('focus', updateAvailability);
    window.removeEventListener('online', updateAvailability);
    window.removeEventListener('offline', updateAvailability);
    window.removeEventListener('popstate', stopForHistory);
});
</script>

<template>
    <Head title="Useful alerts" />
    <AppLayout :breadcrumbs="[{ title: 'Useful alerts', href: '/alerts' }]">
        <main
            class="mx-auto flex w-full max-w-[1500px] min-w-0 flex-col gap-6 p-4 pb-10 sm:p-6 lg:p-8"
            :aria-busy="filterLoading"
        >
            <header class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p
                        class="mb-2 text-xs font-semibold tracking-widest text-indigo-600 uppercase dark:text-indigo-300"
                    >
                        Operations · needs a look
                    </p>
                    <h1
                        class="text-2xl font-semibold tracking-tight sm:text-3xl"
                    >
                        Useful alerts
                    </h1>
                    <p class="mt-2 max-w-2xl text-sm text-muted-foreground">
                        Catch stalled work and delivery issues, then go straight
                        to the records that need attention.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button
                        variant="outline"
                        :disabled="
                            busy ||
                            filterLoading ||
                            refreshState.refreshing ||
                            !online
                        "
                        @click="refreshView"
                        ><RefreshCw
                            class="size-4"
                            :class="{ 'animate-spin': refreshState.refreshing }"
                        />Refresh view</Button
                    ><Button
                        :disabled="busy || filterLoading || !online"
                        @click="runAction('check')"
                        ><LoaderCircle
                            v-if="checking"
                            class="size-4 animate-spin"
                        /><ShieldCheck v-else class="size-4" />{{
                            checking ? 'Checking…' : 'Check now'
                        }}</Button
                    >
                </div>
            </header>
            <section
                class="flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-card px-4 py-3 text-xs"
            >
                <p class="flex items-center gap-2" role="status">
                    <span
                        class="size-2 rounded-full"
                        :class="
                            online && !refreshState.hasError
                                ? 'bg-emerald-500'
                                : 'bg-amber-500'
                        "
                    />{{ refreshLabel }}
                </p>
                <p class="text-muted-foreground">
                    Last checked: {{ dateLabel(alertCenter.checked_at) }}
                </p>
                <p class="text-muted-foreground">
                    Stored records checked every 5 minutes
                </p>
            </section>
            <div
                v-if="refreshState.hasError"
                role="alert"
                class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-amber-500/30 bg-amber-500/5 p-3 text-sm"
            >
                <p>
                    The view could not be refreshed. Displayed alerts may be out
                    of date.
                </p>
                <button
                    type="button"
                    class="font-medium underline underline-offset-4"
                    :disabled="busy || filterLoading"
                    @click="refreshView"
                >
                    Refresh view
                </button>
            </div>
            <p
                v-if="
                    actionError &&
                    (actionErrorId === null ||
                        !alertCenter.data.some(
                            (alert) => alert.id === actionErrorId,
                        ))
                "
                role="alert"
                class="rounded-lg border border-destructive/20 bg-destructive/5 p-3 text-sm text-destructive"
            >
                {{ actionError }}
            </p>
            <p
                v-else-if="actionSuccess"
                role="status"
                class="flex items-center gap-2 rounded-lg border border-emerald-500/25 bg-emerald-500/5 p-3 text-sm"
            >
                <CheckCircle2 class="size-4 shrink-0 text-emerald-600" />{{
                    actionSuccess
                }}
            </p>
            <section
                class="grid grid-cols-3 gap-3"
                aria-label="Alert status totals"
            >
                <button
                    v-for="item in usefulAlertStatuses"
                    :key="item.key"
                    type="button"
                    class="rounded-xl border bg-card p-3 text-left transition hover:border-indigo-400/60 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:p-5"
                    :class="
                        inputs.status === item.key
                            ? 'border-indigo-500/60 ring-1 ring-indigo-500/20'
                            : ''
                    "
                    :aria-pressed="inputs.status === item.key"
                    @click="updateFilter('status', item.key)"
                >
                    <span
                        class="inline-flex rounded-md px-2 py-1 text-xs font-medium"
                        :class="item.classes"
                        >{{ item.label }}</span
                    >
                    <p
                        class="mt-3 text-2xl font-semibold tracking-tight tabular-nums sm:text-3xl"
                    >
                        {{ alertCenter.summary[item.key].toLocaleString() }}
                    </p>
                    <p
                        class="sr-only mt-2 text-xs text-muted-foreground sm:not-sr-only"
                    >
                        {{ item.description }}
                    </p>
                </button>
            </section>
            <section
                class="rounded-xl border bg-card p-4"
                aria-label="Alert filters"
            >
                <form
                    class="flex flex-wrap items-end gap-3"
                    @submit.prevent="submitFilters()"
                >
                    <div class="min-w-40 flex-1">
                        <label for="alerts-website" class="alerts-label"
                            >Website</label
                        ><select
                            id="alerts-website"
                            :value="inputs.website_id"
                            class="alerts-input"
                            @change="
                                updateFilter(
                                    'website_id',
                                    ($event.target as HTMLSelectElement).value,
                                )
                            "
                        >
                            <option value="">All websites</option>
                            <option
                                v-for="site in websites"
                                :key="site.id"
                                :value="String(site.id)"
                            >
                                {{ site.name }}
                            </option>
                        </select>
                    </div>
                    <div class="min-w-40 flex-1">
                        <label for="alerts-kind" class="alerts-label"
                            >Category</label
                        ><select
                            id="alerts-kind"
                            :value="inputs.kind"
                            class="alerts-input"
                            @change="
                                updateFilter(
                                    'kind',
                                    ($event.target as HTMLSelectElement).value,
                                )
                            "
                        >
                            <option value="all">All categories</option>
                            <option
                                v-for="kind in usefulAlertKinds"
                                :key="kind.key"
                                :value="kind.key"
                            >
                                {{ kind.label }}
                            </option>
                        </select>
                    </div>
                    <div class="min-w-36 flex-1">
                        <label for="alerts-status" class="alerts-label"
                            >Status</label
                        ><select
                            id="alerts-status"
                            :value="inputs.status"
                            class="alerts-input"
                            @change="
                                updateFilter(
                                    'status',
                                    ($event.target as HTMLSelectElement).value,
                                )
                            "
                        >
                            <option value="all">All statuses</option>
                            <option
                                v-for="status in usefulAlertStatuses"
                                :key="status.key"
                                :value="status.key"
                            >
                                {{ status.label }}
                            </option>
                        </select>
                    </div>
                    <Button
                        v-if="hasFilters"
                        type="button"
                        variant="ghost"
                        @click="resetFilters"
                        ><X class="size-4" />Reset</Button
                    ><Button type="submit" variant="outline"
                        ><LoaderCircle
                            v-if="filterLoading"
                            class="size-4 animate-spin"
                        />Apply</Button
                    >
                </form>
                <p
                    v-for="(message, field) in filterErrors"
                    :key="field"
                    role="alert"
                    class="mt-2 text-sm text-destructive"
                >
                    {{ message }}
                </p>
                <p class="mt-3 text-xs text-muted-foreground">
                    Totals match the selected website and category, across all
                    pages and statuses.
                </p>
            </section>
            <section class="min-w-0 space-y-4" aria-label="Alerts">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h2 class="font-semibold">
                            {{ statusName
                            }}<span
                                class="ml-2 rounded-md bg-muted px-2 py-0.5 text-xs font-medium tabular-nums"
                                >{{ alertCenter.total.toLocaleString() }}</span
                            >
                        </h2>
                        <p class="mt-1 text-xs text-muted-foreground">
                            {{ scopeName }} · {{ kindName }}
                        </p>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        Times shown in your local timezone
                    </p>
                </div>
                <article
                    v-for="alert in alertCenter.data"
                    :key="alert.id"
                    class="min-w-0 rounded-xl border bg-card p-4 sm:p-5"
                    :class="
                        alert.status === 'active' &&
                        alert.severity === 'critical'
                            ? 'border-l-4 border-l-rose-500'
                            : alert.status === 'active'
                              ? 'border-l-4 border-l-amber-500'
                              : ''
                    "
                    :aria-busy="rowAction?.id === alert.id"
                >
                    <div
                        class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between"
                    >
                        <div class="flex min-w-0 flex-1 gap-3">
                            <span
                                class="mt-0.5 flex size-10 shrink-0 items-center justify-center rounded-xl"
                                :class="
                                    alert.status === 'resolved'
                                        ? 'bg-emerald-500/10 text-emerald-600'
                                        : alert.severity === 'critical'
                                          ? 'bg-rose-500/10 text-rose-600 dark:text-rose-300'
                                          : 'bg-amber-500/10 text-amber-700 dark:text-amber-300'
                                "
                                ><CheckCircle2
                                    v-if="alert.status === 'resolved'"
                                    class="size-5" /><component
                                    :is="kindIcon(alert.kind)"
                                    v-else
                                    class="size-5"
                            /></span>
                            <div class="min-w-0 flex-1">
                                <div
                                    class="mb-2 flex flex-wrap items-center gap-2"
                                >
                                    <span
                                        class="rounded-md px-2 py-1 text-[11px] font-medium"
                                        :class="
                                            usefulAlertStatus(alert.status)
                                                .classes
                                        "
                                        >{{
                                            usefulAlertStatus(alert.status)
                                                .label
                                        }}</span
                                    ><span
                                        class="inline-flex items-center gap-1 text-[11px] font-medium"
                                        :class="
                                            alert.severity === 'critical'
                                                ? 'text-rose-700 dark:text-rose-300'
                                                : 'text-muted-foreground'
                                        "
                                        ><TriangleAlert
                                            v-if="alert.severity === 'critical'"
                                            class="size-3.5"
                                        />{{
                                            alert.severity === 'critical'
                                                ? 'Critical'
                                                : 'Warning'
                                        }}</span
                                    ><span
                                        class="text-xs text-muted-foreground"
                                        >{{
                                            usefulAlertKind(alert.kind).label
                                        }}</span
                                    >
                                </div>
                                <h3 class="text-base font-semibold break-words">
                                    {{ alert.title }}
                                </h3>
                                <p
                                    class="mt-2 max-w-3xl text-sm leading-relaxed break-words text-muted-foreground"
                                >
                                    {{ alert.message }}
                                </p>
                                <div
                                    class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2 text-xs"
                                >
                                    <span
                                        class="inline-flex items-center gap-1.5 font-medium"
                                        ><Globe
                                            class="size-3.5 text-muted-foreground"
                                        />{{ alert.website.name }}</span
                                    ><span
                                        class="rounded-md bg-muted px-2.5 py-1 font-medium tabular-nums"
                                        >{{ alert.count.toLocaleString() }}
                                        {{
                                            usefulAlertKind(alert.kind)
                                                .countLabel
                                        }}</span
                                    >
                                </div>
                                <div v-if="alert.examples?.length" class="mt-3">
                                    <p
                                        class="mb-2 text-xs font-medium text-muted-foreground"
                                    >
                                        Orders to review
                                    </p>
                                    <div class="flex flex-wrap gap-2">
                                        <Link
                                            v-for="example in alert.examples"
                                            :key="example.id"
                                            :href="example.url"
                                            class="inline-flex items-center gap-1 rounded-md border px-2.5 py-1.5 text-xs font-medium text-indigo-700 hover:bg-indigo-500/5 focus-visible:ring-2 focus-visible:ring-ring dark:text-indigo-300"
                                            >{{ example.label
                                            }}<ArrowRight class="size-3"
                                        /></Link>
                                    </div>
                                    <p
                                        v-if="
                                            alert.count > alert.examples.length
                                        "
                                        class="mt-2 text-xs text-muted-foreground"
                                    >
                                        Showing
                                        {{ alert.examples.length }} example
                                        orders from the affected records.
                                    </p>
                                </div>
                            </div>
                        </div>
                        <div
                            class="flex shrink-0 flex-wrap gap-2 lg:max-w-60 lg:justify-end"
                        >
                            <Button
                                v-if="usefulAlertActionUrl(alert.action_url)"
                                as-child
                                size="sm"
                                ><Link :href="alert.action_url"
                                    >{{ alert.action_label
                                    }}<ArrowRight
                                        class="size-3.5" /></Link></Button
                            ><Button
                                v-if="alert.status === 'active'"
                                variant="outline"
                                size="sm"
                                :disabled="busy || filterLoading || !online"
                                @click="runAction('snooze', alert)"
                                ><LoaderCircle
                                    v-if="rowAction?.id === alert.id"
                                    class="size-3.5 animate-spin"
                                /><BellOff v-else class="size-3.5" />Snooze
                                24h</Button
                            ><Button
                                v-else-if="alert.status === 'snoozed'"
                                variant="outline"
                                size="sm"
                                :disabled="busy || filterLoading || !online"
                                @click="runAction('resume', alert)"
                                ><LoaderCircle
                                    v-if="rowAction?.id === alert.id"
                                    class="size-3.5 animate-spin"
                                /><RotateCcw v-else class="size-3.5" />Resume
                                alert</Button
                            >
                        </div>
                    </div>
                    <div
                        class="mt-4 flex flex-wrap gap-x-6 gap-y-2 border-t pt-3 text-xs text-muted-foreground"
                    >
                        <p>
                            First detected
                            <span class="font-medium">{{
                                dateLabel(alert.first_detected_at)
                            }}</span>
                        </p>
                        <p>
                            Last detected
                            <span class="font-medium">{{
                                dateLabel(alert.last_detected_at)
                            }}</span>
                        </p>
                        <p
                            v-if="
                                alert.status === 'snoozed' &&
                                alert.snoozed_until
                            "
                            class="text-sky-700 dark:text-sky-300"
                        >
                            Snoozed until {{ dateLabel(alert.snoozed_until) }}
                        </p>
                        <p
                            v-if="
                                alert.status === 'resolved' && alert.resolved_at
                            "
                            class="text-emerald-700 dark:text-emerald-300"
                        >
                            Resolved {{ dateLabel(alert.resolved_at) }}
                        </p>
                    </div>
                    <p
                        v-if="actionError && actionErrorId === alert.id"
                        role="alert"
                        class="mt-3 rounded-lg border border-destructive/20 bg-destructive/5 p-3 text-sm text-destructive"
                    >
                        {{ actionError }}
                    </p>
                </article>
                <div
                    v-if="!alertCenter.data.length"
                    class="flex flex-col items-center rounded-xl border bg-card px-6 py-14 text-center"
                >
                    <BellRing
                        v-if="!alertCenter.checked_at"
                        class="mb-4 size-9 text-indigo-500/70"
                    /><CheckCircle2
                        v-else-if="filters.status === 'active'"
                        class="mb-4 size-9 text-emerald-500/70"
                    /><Inbox
                        v-else
                        class="mb-4 size-9 text-muted-foreground/60"
                    />
                    <h3 class="font-semibold">
                        {{
                            !alertCenter.checked_at
                                ? 'Ready for the first check'
                                : filters.status === 'active'
                                  ? 'No active alerts in this view'
                                  : filters.status === 'snoozed'
                                    ? 'No snoozed alerts in this view'
                                    : filters.status === 'resolved'
                                      ? 'No resolved alerts in this view'
                                      : 'No alerts in this view'
                        }}
                    </h3>
                    <p
                        class="mt-2 max-w-lg text-sm leading-relaxed text-muted-foreground"
                    >
                        {{
                            !alertCenter.checked_at
                                ? 'Use Check now to review stored webhook, email, and order records for your websites.'
                                : 'The saved checks have no matching alert records for these filters. New issues will appear when detected; this is not a complete website health check.'
                        }}
                    </p>
                    <Button
                        v-if="!alertCenter.checked_at"
                        class="mt-4"
                        :disabled="busy || filterLoading || !online"
                        @click="runAction('check')"
                        >Check now</Button
                    ><Button
                        v-else-if="hasFilters"
                        variant="outline"
                        class="mt-4"
                        @click="resetFilters"
                        >View active alerts for all websites</Button
                    >
                </div>
                <div
                    class="flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-card px-4 py-3 text-xs text-muted-foreground"
                >
                    <span
                        >{{ alertCenter.from ?? 0 }}–{{
                            alertCenter.to ?? 0
                        }}
                        of {{ alertCenter.total.toLocaleString() }} alerts</span
                    >
                    <div class="flex items-center gap-2">
                        <Button
                            variant="outline"
                            size="icon"
                            class="size-8"
                            :disabled="
                                filterLoading || alertCenter.current_page <= 1
                            "
                            aria-label="Previous alerts page"
                            @click="goToPage(alertCenter.current_page - 1)"
                            ><ChevronLeft class="size-4" /></Button
                        ><span
                            >Page {{ alertCenter.current_page }} of
                            {{ alertCenter.last_page }}</span
                        ><Button
                            variant="outline"
                            size="icon"
                            class="size-8"
                            :disabled="
                                filterLoading ||
                                alertCenter.current_page >=
                                    alertCenter.last_page
                            "
                            aria-label="Next alerts page"
                            @click="goToPage(alertCenter.current_page + 1)"
                            ><ChevronRight class="size-4"
                        /></Button>
                    </div>
                </div>
            </section>
            <section
                class="rounded-xl border bg-muted/15 p-4 sm:p-5"
                aria-label="How alerts work"
            >
                <h2 class="flex items-center gap-2 text-sm font-semibold">
                    <CircleAlert class="size-4 text-muted-foreground" />What
                    these alerts check
                </h2>
                <div
                    class="mt-3 grid gap-4 text-xs leading-relaxed text-muted-foreground md:grid-cols-3"
                >
                    <p>
                        <span class="font-medium text-foreground"
                            >Stored activity.</span
                        >
                        Failed webhook records and webhooks waiting more than
                        {{ alertCenter.thresholds.webhook_minutes }} minutes.
                        Checks use the records already in WP Hub.
                    </p>
                    <p>
                        <span class="font-medium text-foreground"
                            >Your document emails.</span
                        >
                        Failed or uncertain WP Hub sends need a review. Manual
                        Gmail sends are not included. Check Gmail Sent before
                        retrying an uncertain message.
                    </p>
                    <p>
                        <span class="font-medium text-foreground"
                            >Order reminders.</span
                        >
                        Orders still processing after
                        {{ alertCenter.thresholds.processing_hours }} hours.
                        This is a reminder to review the work, not a promised
                        delivery deadline.
                    </p>
                </div>
                <p
                    class="mt-4 border-t pt-3 text-xs leading-relaxed text-muted-foreground"
                >
                    A new issue creates one bell notification. Snoozing pauses
                    its reminder for 24 hours; checks continue and alerts
                    resolve automatically when the issue is no longer detected.
                </p>
            </section>
        </main>
    </AppLayout>
</template>

<style scoped>
@reference "../../../css/app.css";
.alerts-label {
    @apply mb-1.5 block text-xs font-medium text-muted-foreground;
}
.alerts-input {
    @apply h-9 w-full min-w-0 rounded-md border border-input bg-background px-3 text-sm shadow-xs outline-none focus-visible:ring-2 focus-visible:ring-ring;
}
</style>
