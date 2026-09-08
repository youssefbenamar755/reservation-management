<script setup lang="ts">
import OrderStatusControl from '@/components/OrderStatusControl.vue';
import { Button } from '@/components/ui/button';
import { useEchoNotifications } from '@/composables/useEchoNotifications';
import { useToast } from '@/composables/useToast';
import AppLayout from '@/layouts/AppLayout.vue';
import { getEcho } from '@/lib/echo';
import {
    createAutoRefresh,
    refreshOrdersSnapshot,
    type AutoRefreshState,
} from '@/lib/liveOrders';
import {
    subscribeToOrders,
    type OrdersConnectionState,
} from '@/lib/ordersPush';
import {
    runWooCommerceSync,
    summarizeWooCommerceSyncResults,
    type WooCommerceSyncCounts,
} from '@/lib/wooCommerceSync';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    ArrowUpRight,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    Clock3,
    CloudDownload,
    RefreshCw,
    Search,
    ShoppingBag,
    SlidersHorizontal,
    Wallet,
    X,
} from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

interface OrderRow {
    id: number;
    wp_order_id: number;
    website_id: number;
    website: { id: number; name: string } | null;
    status: string;
    can_update_status: boolean;
    total: string | number;
    currency: string | null;
    customer_name: string | null;
    customer_email: string | null;
    created_at_wp: string | null;
}
interface OrderPage {
    data: OrderRow[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    per_page: number;
    links: Array<{ url: string | null; label: string; active: boolean }>;
    timezone?: string;
    summary?: {
        completed: number;
        pending: number;
        on_hold: number;
        processing: number;
        failed: number;
        completed_revenue: Array<{ currency: string; total: number }>;
    };
}
const props = defineProps<{
    orders: OrderPage;
    websites: Array<{ id: number; name: string }>;
    filters: {
        website_id?: string | number | null;
        status?: string | null;
        search?: string | null;
        start_date?: string | null;
        end_date?: string | null;
        sort?: string | null;
        per_page?: string | number | null;
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Orders',
        href: '/orders',
    },
];

const filterInputs = ref(readAppliedFilters());
const filterLoading = ref(false);
const filterErrors = ref<Record<string, string>>({});
const showMoreFilters = ref(
    Boolean(props.filters.start_date || props.filters.end_date),
);
const statuses = [
    { value: 'pending', label: 'Pending payment' },
    { value: 'on-hold', label: 'On hold' },
    { value: 'processing', label: 'Processing' },
    { value: 'completed', label: 'Completed' },
    { value: 'cancelled', label: 'Cancelled' },
    { value: 'refunded', label: 'Refunded' },
    { value: 'failed', label: 'Failed' },
    { value: 'checkout-draft', label: 'Checkout draft' },
];
let searchTimer: ReturnType<typeof setTimeout> | null = null;
let filterRequest: { params: string; cancel?: () => void } | null = null;
let dispatchingFilters = false;
let filtersDisposed = false;

function readAppliedFilters() {
    return {
        website_id: String(props.filters.website_id || ''),
        status: props.filters.status || '',
        search: props.filters.search || '',
        start_date: props.filters.start_date || '',
        end_date: props.filters.end_date || '',
        sort: props.filters.sort || 'newest',
        per_page: String(props.filters.per_page || 15),
    };
}

function clearSearchTimer() {
    if (searchTimer !== null) clearTimeout(searchTimer);
    searchTimer = null;
}

function cancelFilterRequest() {
    const previous = filterRequest;
    filterRequest = null;
    filterLoading.value = false;
    previous?.cancel?.();
}

function cancelPendingFilters(restore = false) {
    clearSearchTimer();
    cancelFilterRequest();
    if (restore) {
        filterInputs.value = readAppliedFilters();
        filterErrors.value = {};
    }
}

function submitFilters() {
    clearSearchTimer();
    filterErrors.value = {};
    if (
        filterInputs.value.start_date &&
        filterInputs.value.end_date &&
        filterInputs.value.start_date > filterInputs.value.end_date
    ) {
        cancelFilterRequest();
        filterErrors.value = {
            end_date: 'End date must be on or after the start date.',
        };
        return;
    }
    const params = JSON.stringify(filterInputs.value);
    if (filterRequest?.params === params) return;
    cancelFilterRequest();
    if (filtersDisposed || params === JSON.stringify(readAppliedFilters()))
        return;
    const request: { params: string; cancel?: () => void } = { params };
    filterRequest = request;
    filterLoading.value = true;
    dispatchingFilters = true;
    try {
        router.get(
            '/orders',
            { ...filterInputs.value },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onCancelToken: (token) => {
                    if (filterRequest !== request) token.cancel();
                    else request.cancel = () => token.cancel();
                },
                onSuccess: () => {
                    if (filterRequest === request)
                        filterInputs.value = readAppliedFilters();
                },
                onError: (errors) => {
                    if (filterRequest === request) filterErrors.value = errors;
                },
                onFinish: () => {
                    if (filterRequest === request) {
                        filterRequest = null;
                        filterLoading.value = false;
                    }
                },
            },
        );
    } finally {
        dispatchingFilters = false;
    }
}

function updateSearch(value: string) {
    clearSearchTimer();
    cancelFilterRequest();
    filterInputs.value.search = value;
    filterErrors.value = {};
    if (
        JSON.stringify(filterInputs.value) ===
        JSON.stringify(readAppliedFilters())
    )
        return;
    searchTimer = setTimeout(submitFilters, 300);
}

function updateFilter(
    key:
        | 'website_id'
        | 'status'
        | 'start_date'
        | 'end_date'
        | 'sort'
        | 'per_page',
    value: string,
) {
    filterInputs.value[key] = value;
    submitFilters();
}

// Live snapshots keep the draft; returned filters and history restore the inputs.
watch(
    () => props.filters,
    () => {
        if (
            !filterRequest &&
            searchTimer === null &&
            Object.keys(filterErrors.value).length === 0
        )
            filterInputs.value = readAppliedFilters();
    },
    { deep: true },
);

function resetFilters() {
    filterInputs.value = {
        website_id: '',
        status: '',
        search: '',
        start_date: '',
        end_date: '',
        sort: 'newest',
        per_page: '15',
    };
    submitFilters();
}
const scopeName = computed(
    () =>
        props.websites.find(
            (website) =>
                String(website.id) === String(props.filters.website_id),
        )?.name ?? 'All websites',
);
const hasFilters = computed(() =>
    Boolean(
        props.filters.website_id ||
        props.filters.status ||
        props.filters.search ||
        props.filters.start_date ||
        props.filters.end_date ||
        (props.filters.sort && props.filters.sort !== 'newest') ||
        (props.filters.per_page && Number(props.filters.per_page) !== 15),
    ),
);
const summary = computed(
    () =>
        props.orders.summary ?? {
            completed: 0,
            pending: 0,
            on_hold: 0,
            processing: 0,
            failed: 0,
            completed_revenue: [],
        },
);
const filterChips = computed(() => [
    ...(props.filters.website_id
        ? [{ key: 'website_id' as const, label: scopeName.value }]
        : []),
    ...(props.filters.status
        ? [{ key: 'status' as const, label: statusName(props.filters.status) }]
        : []),
    ...(props.filters.search
        ? [{ key: 'search' as const, label: `Search: ${props.filters.search}` }]
        : []),
    ...(props.filters.start_date
        ? [
              {
                  key: 'start_date' as const,
                  label: `From ${props.filters.start_date}`,
              },
          ]
        : []),
    ...(props.filters.end_date
        ? [{ key: 'end_date' as const, label: `To ${props.filters.end_date}` }]
        : []),
]);
function removeFilter(
    key: 'website_id' | 'status' | 'search' | 'start_date' | 'end_date',
) {
    filterInputs.value[key] = '';
    submitFilters();
}
function statusName(status: string) {
    return (
        statuses.find((item) => item.value === status)?.label ??
        status.replaceAll('-', ' ')
    );
}
function customerInitials(order: OrderRow) {
    const name =
        order.customer_name?.trim() || order.customer_email?.trim() || '';
    if (!name) return '?';
    return name
        .split(/\s+/)
        .slice(0, 2)
        .map((part) => part[0])
        .join('')
        .toUpperCase();
}
function customerUrl(order: OrderRow) {
    return `/customers/${encodeURIComponent(order.customer_email ?? '')}?website_ids[0]=${order.website_id}`;
}

const isSyncing = ref(false);
const syncScopePending = computed(
    () =>
        filterLoading.value ||
        filterInputs.value.website_id !==
            String(props.filters.website_id || ''),
);
let syncAbortController: AbortController | null = null;
const syncProgress = ref(''); // e.g. "Syncing Website A (1 / 3)…"
const selectedWebsiteId = computed(() => props.filters.website_id);
const page = usePage();
const toast = useToast();

const { onNotification, offNotification } = useEchoNotifications();

const online = ref(true);
const visible = ref(true);
const connectionState = ref<OrdersConnectionState>('connecting');
const refreshState = ref<AutoRefreshState>({
    refreshing: false,
    lastCheckedAt: null,
    hasError: false,
});
const refreshLabel = computed(() => {
    if (!online.value) return 'Live updates disconnected — Sync available';
    if (refreshState.value.refreshing) return 'Updating orders…';
    if (connectionState.value === 'connecting')
        return 'Connecting to live updates…';
    if (connectionState.value === 'reconnecting')
        return 'Reconnecting to live updates…';
    if (connectionState.value === 'disconnected')
        return 'Live updates disconnected — Sync available';
    if (refreshState.value.hasError) return 'Refresh failed — Sync available';
    return 'Live updates connected';
});
const lastChecked = computed(() =>
    refreshState.value.lastCheckedAt === null
        ? ''
        : new Date(refreshState.value.lastCheckedAt).toLocaleTimeString(),
);

let mounted = false;
const navigationVisits = new Set<object>();
const cleanupListeners: Array<() => void> = [];
let stopOrdersSubscription: (() => void) | null = null;

const liveRefresh = createAutoRefresh({
    isAvailable: () => mounted && online.value && visible.value,
    onState: (state) => {
        refreshState.value = state;
    },
    refresh: ({ isCurrent, complete }) => {
        const controller = new AbortController();
        void refreshOrdersSnapshot({
            getUrl: () => window.location.href,
            isCurrent,
            signal: controller.signal,
            apply: (orders, stillCurrent) =>
                new Promise<boolean>((resolve) => {
                    let applied = false;
                    // replaceProp preserves URL, filters, scroll, and pagination. Its updater
                    // runs from Inertia's queue, so check the generation and URL again here.
                    router.replaceProp(
                        'orders',
                        (currentOrders: unknown) => {
                            if (!stillCurrent()) return currentOrders;
                            applied = true;
                            return orders;
                        },
                        { onFinish: () => resolve(applied) },
                    );
                }),
        }).then(complete);
        return () => controller.abort();
    },
});

function requestOrderRefresh(data?: {
    website_id?: number | string;
    data?: { website_id?: number | string };
}) {
    const websiteId = data?.website_id ?? data?.data?.website_id;
    if (
        selectedWebsiteId.value &&
        websiteId &&
        String(websiteId) !== String(selectedWebsiteId.value)
    )
        return;
    liveRefresh.request();
}

const onOrderNotification = (data: {
    type?: string;
    website_id?: number | string;
    data?: { website_id?: number | string };
}) => {
    if (data?.type === 'order') {
        requestOrderRefresh(data);
    }
};

const onOrderReceived = (data: { website_id?: number | string }) => {
    requestOrderRefresh(data);
};

function updateAvailability() {
    online.value = navigator.onLine;
    visible.value = !document.hidden;
    liveRefresh.availabilityChanged();
}

function suspendForHistoryNavigation() {
    cancelPendingFilters(true);
    liveRefresh.suspend();
}

// Query changes within this component must invalidate a previous snapshot too.
watch(
    () => page.url,
    () => {
        if (!mounted) return;
        liveRefresh.suspend();
        if (navigationVisits.size === 0) liveRefresh.resume();
    },
    { flush: 'sync' },
);

onMounted(() => {
    mounted = true;
    const user = page.props.auth?.user;
    if (!user) return;
    online.value = navigator.onLine;
    visible.value = !document.hidden;

    cleanupListeners.push(
        router.on('before', ({ detail: { visit } }) => {
            if (visit.async) return;
            if (!dispatchingFilters) cancelPendingFilters(true);
            liveRefresh.suspend();
            // A navigation cancelled by another listener has no start/finish event.
            queueMicrotask(() => {
                if (mounted && navigationVisits.size === 0)
                    liveRefresh.resume();
            });
        }),
        router.on('start', ({ detail: { visit } }) => {
            if (visit.async) return;
            navigationVisits.add(visit);
            liveRefresh.suspend();
        }),
        router.on('finish', ({ detail: { visit } }) => {
            if (visit.async) return;
            navigationVisits.delete(visit);
            if (mounted && navigationVisits.size === 0) liveRefresh.resume();
        }),
        router.on('navigate', () => {
            if (mounted && navigationVisits.size === 0) liveRefresh.resume();
        }),
    );
    document.addEventListener('visibilitychange', updateAvailability);
    window.addEventListener('online', updateAvailability);
    window.addEventListener('offline', updateAvailability);
    window.addEventListener('focus', updateAvailability);
    window.addEventListener('popstate', suspendForHistoryNavigation);

    try {
        onNotification('orders-index', onOrderNotification, user.id);
        stopOrdersSubscription = subscribeToOrders({
            userId: user.id,
            getEcho,
            onOrder: onOrderReceived,
            onSubscribed: () => liveRefresh.request(),
            onState: (state) => {
                connectionState.value = state;
            },
        });
    } catch (e) {
        console.error('Echo setup failed:', e);
    }
    liveRefresh.start();
});
onUnmounted(() => {
    mounted = false;
    filtersDisposed = true;
    cancelPendingFilters();
    liveRefresh.stop();
    syncAbortController?.abort();
    offNotification('orders-index');
    stopOrdersSubscription?.();
    cleanupListeners.forEach((remove) => remove());
    navigationVisits.clear();
    document.removeEventListener('visibilitychange', updateAvailability);
    window.removeEventListener('online', updateAvailability);
    window.removeEventListener('offline', updateAvailability);
    window.removeEventListener('focus', updateAvailability);
    window.removeEventListener('popstate', suspendForHistoryNavigation);
});

async function syncOrdersFromWooCommerce() {
    if (isSyncing.value || syncScopePending.value) return;
    isSyncing.value = true;
    syncProgress.value = '';

    // Both modes request one page at a time; the server keeps the resume cursor.
    const websiteId = selectedWebsiteId.value;
    const websites: { id: number; name: string }[] = websiteId
        ? props.websites.filter(
              (website) => String(website.id) === String(websiteId),
          )
        : props.websites;

    if (!websites.length) {
        isSyncing.value = false;
        toast.error('No websites configured.');
        return;
    }

    const syncResults: Array<WooCommerceSyncCounts | null> = [];
    const errors: string[] = [];
    const controller = new AbortController();
    syncAbortController = controller;

    for (let i = 0; i < websites.length; i++) {
        const w = websites[i];
        syncProgress.value = `Syncing ${w.name} (${i + 1} / ${websites.length})…`;

        try {
            const csrfMeta = document.querySelector(
                'meta[name="csrf-token"]',
            ) as HTMLMetaElement | null;
            const csrf = csrfMeta?.content ?? '';

            const result = await runWooCommerceSync(
                () =>
                    fetch(`/websites/${w.id}/sync-woocommerce-orders`, {
                        method: 'POST',
                        signal: controller.signal,
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrf,
                            Accept: 'application/json',
                        },
                        body: JSON.stringify({}),
                    }),
                (counts) => {
                    syncProgress.value = `Syncing ${w.name} (${i + 1} / ${websites.length}) — ${counts.newOrders} new, ${counts.updatedOrders} updated…`;
                },
                { signal: controller.signal },
            );
            syncResults.push(result);
        } catch (e: unknown) {
            if (controller.signal.aborted) {
                isSyncing.value = false;
                syncProgress.value = '';
                syncAbortController = null;
                return;
            }
            errors.push(
                `${w.name}: ${e instanceof Error ? e.message : 'network error'}`,
            );
        }
    }

    isSyncing.value = false;
    syncProgress.value = '';
    syncAbortController = null;

    liveRefresh.request(0);

    if (errors.length === 0) {
        toast.success(
            summarizeWooCommerceSyncResults(
                syncResults,
                websiteId ? websites[0].name : 'All websites',
            ),
        );
    } else {
        toast.error(`Synced with errors: ${errors.slice(0, 3).join(', ')}`);
    }
}

function goToPage(url: string | null) {
    if (!url) return;
    cancelPendingFilters(true);

    // Parse URL to extract path and query parameters
    // Handle both relative (/orders?page=2) and absolute URLs (http://domain.com/orders?page=2)
    try {
        const urlObj = url.startsWith('http')
            ? new URL(url)
            : new URL(url, window.location.origin);

        const path = urlObj.pathname;
        const params: Record<string, string> = {};

        // Extract query parameters
        urlObj.searchParams.forEach((value, key) => {
            params[key] = value;
        });

        router.get(path, params, {
            preserveState: true,
            preserveScroll: true,
            replace: false,
        });
    } catch {
        // Fallback: use router.visit if URL parsing fails
        router.visit(url, { preserveState: true, preserveScroll: true });
    }
}

/**
 * Safely decode HTML entities without rendering HTML tags.
 * SSR-safe: uses DOM when available, otherwise pure JS decode for Laravel pagination entities.
 */
function decodeHtmlEntities(text: string): string {
    if (!text) return '';
    if (typeof document !== 'undefined') {
        const textarea = document.createElement('textarea');
        textarea.innerHTML = text;
        return textarea.value;
    }
    return text
        .replace(/&laquo;/g, '\u00AB')
        .replace(/&raquo;/g, '\u00BB')
        .replace(/&lsaquo;/g, '\u2039')
        .replace(/&rsaquo;/g, '\u203A')
        .replace(/&#(\d+);/g, (_, n) => String.fromCharCode(parseInt(n, 10)))
        .replace(/&#x([0-9a-fA-F]+);/g, (_, n) =>
            String.fromCharCode(parseInt(n, 16)),
        );
}

function formatCurrency(amount: string | number, currency?: string | null) {
    const value = Number(amount);
    if (!Number.isFinite(value)) return '—';
    const code = currency?.trim().toUpperCase() || 'UNKNOWN';
    try {
        if (!/^[A-Z]{3}$/.test(code)) throw new Error('Unknown currency');
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: code,
        }).format(value);
    } catch {
        return `${value.toLocaleString('en-US', { maximumFractionDigits: 2 })} ${code}`;
    }
}

function formatDate(dateString: string | null, part: 'date' | 'time' = 'date') {
    if (!dateString) return '—';
    // The listing uses the backend's ISO timestamps, with its timezone labeled below.
    const date = new Date(dateString);
    if (Number.isNaN(date.getTime())) return '—';
    return new Intl.DateTimeFormat('en-US', {
        ...(part === 'date'
            ? { month: 'short', day: 'numeric', year: 'numeric' }
            : { hour: '2-digit', minute: '2-digit' }),
        timeZone: props.orders.timezone || 'UTC',
    }).format(date);
}
</script>

<template>
    <Head title="Orders" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <main
            class="orders-shell mx-auto flex w-full max-w-[1600px] min-w-0 flex-col gap-5 p-4 pb-10 sm:p-6 lg:p-8"
            :aria-busy="filterLoading"
        >
            <header class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div
                        class="mb-2 flex items-center gap-2 text-[11px] font-semibold tracking-[0.16em] text-muted-foreground uppercase"
                    >
                        <ShoppingBag
                            class="size-3.5"
                            aria-hidden="true"
                        />Reservation management
                    </div>
                    <h1 class="text-3xl font-semibold tracking-tight">
                        Orders
                    </h1>
                    <p class="mt-2 text-sm text-muted-foreground">
                        Find, review, and follow up on orders across your
                        websites.
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="refreshState.refreshing || filterLoading"
                        @click="liveRefresh.request(0)"
                        ><RefreshCw
                            class="size-3.5"
                            :class="{ 'animate-spin': refreshState.refreshing }"
                            aria-hidden="true"
                        />Refresh view</Button
                    ><Button
                        size="sm"
                        :disabled="isSyncing || syncScopePending"
                        @click="syncOrdersFromWooCommerce"
                        ><RefreshCw
                            v-if="isSyncing"
                            class="size-3.5 animate-spin"
                            aria-hidden="true"
                        /><CloudDownload
                            v-else
                            class="size-3.5"
                            aria-hidden="true"
                        />{{
                            isSyncing ? 'Syncing…' : 'Sync from WooCommerce'
                        }}</Button
                    >
                </div>
            </header>
            <div
                class="flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground"
            >
                <p>
                    <span class="font-medium text-foreground">{{
                        scopeName
                    }}</span
                    ><span class="px-1.5">·</span
                    >{{ orders.total.toLocaleString() }} matching
                    {{ orders.total === 1 ? 'order' : 'orders' }}
                </p>
                <div
                    class="flex flex-wrap items-center gap-2"
                    aria-label="Automatic order updates"
                    role="status"
                >
                    <span
                        class="size-1.5 rounded-full"
                        :class="
                            !online ||
                            connectionState !== 'connected' ||
                            refreshState.hasError
                                ? 'bg-amber-500'
                                : 'bg-emerald-500'
                        "
                        aria-hidden="true"
                    /><span>{{ refreshLabel }}</span
                    ><span v-if="lastChecked" class="hidden sm:inline"
                        >· {{ lastChecked }}</span
                    >
                </div>
            </div>
            <section
                aria-label="Matching order totals"
                class="grid grid-cols-2 gap-3 xl:grid-cols-4"
            >
                <article class="orders-metric">
                    <div class="flex items-center justify-between gap-2">
                        <h2
                            class="text-xs font-medium text-muted-foreground sm:text-sm"
                        >
                            Matching orders
                        </h2>
                        <ShoppingBag
                            class="size-4 shrink-0 text-indigo-500"
                            aria-hidden="true"
                        />
                    </div>
                    <strong
                        class="mt-3 block text-2xl font-semibold tabular-nums sm:text-3xl"
                        >{{ orders.total.toLocaleString() }}</strong
                    >
                    <p class="mt-2 text-[11px] text-muted-foreground">
                        Across all matching pages
                    </p>
                </article>
                <article class="orders-metric">
                    <div class="flex items-center justify-between gap-2">
                        <h2
                            class="text-xs font-medium text-muted-foreground sm:text-sm"
                        >
                            Completed
                        </h2>
                        <CheckCircle2
                            class="size-4 shrink-0 text-emerald-500"
                            aria-hidden="true"
                        />
                    </div>
                    <strong
                        class="mt-3 block text-2xl font-semibold tabular-nums sm:text-3xl"
                        >{{ summary.completed.toLocaleString() }}</strong
                    >
                    <p class="mt-2 text-[11px] text-muted-foreground">
                        {{ summary.processing }} processing ·
                        {{ summary.failed }} failed
                    </p>
                </article>
                <article class="orders-metric">
                    <div class="flex items-center justify-between gap-2">
                        <h2
                            class="text-xs font-medium text-muted-foreground sm:text-sm"
                        >
                            Pending &amp; on hold
                        </h2>
                        <Clock3
                            class="size-4 shrink-0 text-amber-500"
                            aria-hidden="true"
                        />
                    </div>
                    <strong
                        class="mt-3 block text-2xl font-semibold tabular-nums sm:text-3xl"
                        >{{
                            (summary.pending + summary.on_hold).toLocaleString()
                        }}</strong
                    >
                    <p class="mt-2 text-[11px] text-muted-foreground">
                        {{ summary.pending }} pending · {{ summary.on_hold }} on
                        hold
                    </p>
                </article>
                <article class="orders-metric">
                    <div class="flex items-center justify-between gap-2">
                        <h2
                            class="text-xs font-medium text-muted-foreground sm:text-sm"
                        >
                            Completed revenue
                        </h2>
                        <Wallet
                            class="size-4 shrink-0 text-teal-500"
                            aria-hidden="true"
                        />
                    </div>
                    <div class="mt-3">
                        <p
                            v-for="row in summary.completed_revenue"
                            :key="row.currency"
                            class="flex flex-wrap items-baseline gap-1.5"
                        >
                            <strong
                                class="text-xl font-semibold break-words tabular-nums sm:text-2xl"
                                >{{
                                    formatCurrency(row.total, row.currency)
                                }}</strong
                            ><span class="text-[10px] text-muted-foreground">{{
                                row.currency
                            }}</span>
                        </p>
                        <strong
                            v-if="!summary.completed_revenue.length"
                            class="block text-2xl font-semibold"
                            >—</strong
                        >
                    </div>
                    <p class="mt-2 text-[11px] text-muted-foreground">
                        Completed orders · currencies kept separate
                    </p>
                </article>
            </section>
            <section class="orders-panel p-4 sm:p-5" aria-label="Order filters">
                <div
                    class="grid gap-3 sm:grid-cols-2 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)]"
                >
                    <div class="relative sm:col-span-2 xl:col-span-1">
                        <Search
                            class="pointer-events-none absolute top-3 left-3 size-4 text-muted-foreground"
                            aria-hidden="true"
                        /><input
                            type="search"
                            class="orders-input orders-search w-full"
                            aria-label="Search orders"
                            placeholder="Search order number, customer, or email…"
                            maxlength="200"
                            :value="filterInputs.search"
                            @input="
                                updateSearch(
                                    ($event.target as HTMLInputElement).value,
                                )
                            "
                            @keydown.enter.prevent="submitFilters"
                        /><button
                            v-if="filterInputs.search"
                            type="button"
                            class="absolute top-2 right-2 rounded-md p-1 text-muted-foreground hover:bg-muted"
                            aria-label="Clear search"
                            @click="removeFilter('search')"
                        >
                            <X class="size-4" aria-hidden="true" />
                        </button>
                    </div>
                    <select
                        class="orders-input"
                        aria-label="Website"
                        :value="filterInputs.website_id"
                        @change="
                            updateFilter(
                                'website_id',
                                ($event.target as HTMLSelectElement).value,
                            )
                        "
                    >
                        <option value="">All websites</option>
                        <option
                            v-for="website in websites"
                            :key="website.id"
                            :value="website.id"
                        >
                            {{ website.name }}
                        </option>
                    </select>
                    <select
                        class="orders-input"
                        aria-label="Order status"
                        :value="filterInputs.status"
                        @change="
                            updateFilter(
                                'status',
                                ($event.target as HTMLSelectElement).value,
                            )
                        "
                    >
                        <option value="">All statuses</option>
                        <option
                            v-for="status in statuses"
                            :key="status.value"
                            :value="status.value"
                        >
                            {{ status.label }}
                        </option>
                    </select>
                </div>
                <div
                    class="mt-3 flex flex-wrap items-center justify-between gap-3"
                >
                    <div class="flex items-center gap-2">
                        <Button
                            variant="ghost"
                            size="sm"
                            class="-ml-2 text-muted-foreground"
                            :aria-expanded="showMoreFilters"
                            aria-controls="order-date-filters"
                            @click="showMoreFilters = !showMoreFilters"
                            ><SlidersHorizontal
                                class="size-3.5"
                                aria-hidden="true" />Date range<span
                                v-if="filters.start_date || filters.end_date"
                                class="size-1.5 rounded-full bg-primary"
                                aria-hidden="true" /></Button
                        ><button
                            v-if="
                                hasFilters || Object.keys(filterErrors).length
                            "
                            type="button"
                            class="text-xs text-muted-foreground underline underline-offset-4 hover:text-foreground"
                            @click="resetFilters"
                        >
                            Reset filters
                        </button>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <label
                            for="orders-sort"
                            class="text-xs text-muted-foreground"
                            >Sort</label
                        ><select
                            id="orders-sort"
                            class="rounded-lg border bg-background px-2 py-1.5 text-xs"
                            :value="filterInputs.sort"
                            @change="
                                updateFilter(
                                    'sort',
                                    ($event.target as HTMLSelectElement).value,
                                )
                            "
                        >
                            <option value="newest">Newest first</option>
                            <option value="oldest">Oldest first</option>
                            <option value="highest">Highest amount</option>
                            <option value="lowest">Lowest amount</option>
                        </select>
                    </div>
                </div>
                <div
                    v-show="showMoreFilters"
                    id="order-date-filters"
                    class="mt-4 grid gap-3 border-t pt-4 sm:grid-cols-[1fr_1fr_1.3fr]"
                >
                    <div>
                        <label
                            for="orders-from"
                            class="mb-1.5 block text-xs text-muted-foreground"
                            >From date</label
                        ><input
                            id="orders-from"
                            type="date"
                            class="orders-input w-full"
                            :value="filterInputs.start_date"
                            :aria-invalid="Boolean(filterErrors.start_date)"
                            @change="
                                updateFilter(
                                    'start_date',
                                    ($event.target as HTMLInputElement).value,
                                )
                            "
                        />
                    </div>
                    <div>
                        <label
                            for="orders-to"
                            class="mb-1.5 block text-xs text-muted-foreground"
                            >To date</label
                        ><input
                            id="orders-to"
                            type="date"
                            class="orders-input w-full"
                            :value="filterInputs.end_date"
                            :aria-invalid="Boolean(filterErrors.end_date)"
                            @change="
                                updateFilter(
                                    'end_date',
                                    ($event.target as HTMLInputElement).value,
                                )
                            "
                        />
                    </div>
                    <p
                        class="self-end pb-1 text-[11px] leading-relaxed text-muted-foreground"
                    >
                        Dates include the full selected days in
                        {{ orders.timezone || 'UTC' }}. Totals reflect all
                        active filters.
                    </p>
                </div>
                <p
                    v-if="
                        filterInputs.sort === 'highest' ||
                        filterInputs.sort === 'lowest'
                    "
                    class="mt-3 text-[11px] text-muted-foreground"
                >
                    Amounts are sorted in their original currencies, without
                    conversion.
                </p>
                <div
                    v-if="Object.keys(filterErrors).length"
                    class="mt-3 rounded-lg bg-destructive/5 p-3 text-xs text-destructive"
                    role="alert"
                >
                    <p v-for="(error, key) in filterErrors" :key="key">
                        {{ error }}
                    </p>
                </div>
                <div
                    v-if="filterChips.length"
                    class="mt-4 flex flex-wrap gap-2 border-t pt-3"
                    aria-label="Applied filters"
                >
                    <button
                        v-for="chip in filterChips"
                        :key="chip.key"
                        type="button"
                        class="inline-flex max-w-full items-center gap-2 rounded-full border bg-muted/40 px-2.5 py-1 text-[11px] text-muted-foreground hover:text-foreground"
                        :aria-label="`Remove ${chip.label} filter`"
                        @click="removeFilter(chip.key)"
                    >
                        <span class="truncate">{{ chip.label }}</span
                        ><X class="size-3 shrink-0" aria-hidden="true" />
                    </button>
                </div>
            </section>
            <div
                v-if="isSyncing"
                class="flex min-w-0 items-center gap-3 rounded-xl border border-indigo-500/20 bg-indigo-500/5 px-4 py-3 text-sm"
                role="status"
            >
                <RefreshCw
                    class="size-4 shrink-0 animate-spin text-indigo-500"
                    aria-hidden="true"
                />
                <p class="min-w-0 break-words">
                    {{ syncProgress || 'Preparing sync…' }}
                </p>
            </div>
            <section
                class="orders-panel min-w-0 overflow-hidden"
                aria-labelledby="orders-list-title"
            >
                <div
                    class="flex flex-wrap items-center justify-between gap-3 border-b px-4 py-4 sm:px-5"
                >
                    <div>
                        <h2
                            id="orders-list-title"
                            class="text-sm font-semibold"
                        >
                            Order list
                        </h2>
                        <p class="mt-1 text-xs text-muted-foreground">
                            <span v-if="filterLoading">Updating results…</span
                            ><span v-else
                                >{{ orders.from || 0 }}–{{ orders.to || 0 }} of
                                {{ orders.total.toLocaleString() }} orders</span
                            >
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <label
                            for="orders-per-page"
                            class="text-xs text-muted-foreground"
                            >Rows per page</label
                        ><select
                            id="orders-per-page"
                            class="rounded-lg border bg-background px-2 py-1.5 text-xs"
                            :value="filterInputs.per_page"
                            @change="
                                updateFilter(
                                    'per_page',
                                    ($event.target as HTMLSelectElement).value,
                                )
                            "
                        >
                            <option
                                v-for="size in [15, 30, 50, 100]"
                                :key="size"
                                :value="String(size)"
                            >
                                {{ size }}
                            </option>
                        </select>
                    </div>
                </div>
                <div
                    v-if="orders.data.length"
                    class="hidden overflow-x-auto xl:block"
                >
                    <table class="w-full table-fixed text-left text-sm">
                        <caption class="sr-only">
                            Orders matching the selected filters. Dates shown in
                            {{
                                orders.timezone || 'UTC'
                            }}.
                        </caption>
                        <thead
                            class="border-b bg-muted/30 text-[11px] text-muted-foreground"
                        >
                            <tr>
                                <th
                                    scope="col"
                                    class="w-[11%] px-5 py-3 font-medium"
                                >
                                    Order
                                </th>
                                <th
                                    scope="col"
                                    class="w-[27%] px-3 py-3 font-medium"
                                >
                                    Customer
                                </th>
                                <th
                                    scope="col"
                                    class="w-[19%] px-3 py-3 font-medium"
                                >
                                    Website
                                </th>
                                <th
                                    scope="col"
                                    class="w-[16%] px-3 py-3 font-medium"
                                >
                                    Status
                                </th>
                                <th
                                    scope="col"
                                    class="w-[13%] px-3 py-3 text-right font-medium"
                                >
                                    Total
                                </th>
                                <th
                                    scope="col"
                                    class="w-[14%] px-5 py-3 text-right font-medium"
                                >
                                    Created
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="order in orders.data"
                                :key="order.id"
                                class="transition-colors hover:bg-muted/25"
                            >
                                <td class="px-5 py-4 align-top">
                                    <Link
                                        :href="`/orders/${order.id}`"
                                        class="font-semibold text-foreground tabular-nums hover:underline"
                                        >#{{ order.wp_order_id }}</Link
                                    ><Link
                                        :href="`/orders/${order.id}`"
                                        class="mt-1.5 inline-flex items-center gap-0.5 text-[10px] text-muted-foreground hover:text-foreground"
                                        :aria-label="`View order ${order.wp_order_id}`"
                                        >View<ArrowUpRight
                                            class="size-3"
                                            aria-hidden="true"
                                    /></Link>
                                </td>
                                <td class="px-3 py-4 align-top">
                                    <div
                                        class="flex min-w-0 items-start gap-2.5"
                                    >
                                        <span
                                            class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full bg-muted text-[9px] font-semibold text-muted-foreground"
                                            >{{ customerInitials(order) }}</span
                                        >
                                        <div class="min-w-0">
                                            <Link
                                                v-if="order.customer_email"
                                                :href="customerUrl(order)"
                                                class="block truncate text-xs font-medium hover:underline"
                                                :title="
                                                    order.customer_name ||
                                                    order.customer_email
                                                "
                                                >{{
                                                    order.customer_name ||
                                                    order.customer_email
                                                }}</Link
                                            >
                                            <p
                                                v-else
                                                class="truncate text-xs font-medium"
                                            >
                                                {{
                                                    order.customer_name ||
                                                    'Guest customer'
                                                }}
                                            </p>
                                            <p
                                                class="mt-1 truncate text-[11px] text-muted-foreground"
                                                :title="
                                                    order.customer_email ||
                                                    undefined
                                                "
                                            >
                                                {{
                                                    order.customer_email ||
                                                    'No email provided'
                                                }}
                                            </p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-3 py-4 align-top">
                                    <span
                                        class="inline-flex max-w-full items-center gap-1.5 rounded-md border bg-muted/20 px-2 py-1 text-[11px]"
                                        ><span
                                            class="size-1.5 shrink-0 rounded-full bg-indigo-400"
                                            aria-hidden="true"
                                        /><span
                                            class="truncate"
                                            :title="order.website?.name"
                                            >{{
                                                order.website?.name ||
                                                'Unknown website'
                                            }}</span
                                        ></span
                                    >
                                </td>
                                <td class="px-3 py-4 align-top">
                                    <OrderStatusControl
                                        :order-id="order.id"
                                        :order-number="order.wp_order_id"
                                        :status="order.status"
                                        :website-name="order.website?.name"
                                        :disabled="
                                            filterLoading ||
                                            !order.can_update_status
                                        "
                                        @settled="liveRefresh.requestFresh()"
                                    />
                                </td>
                                <td class="px-3 py-4 text-right align-top">
                                    <strong
                                        class="block text-xs font-semibold break-words tabular-nums"
                                        >{{
                                            formatCurrency(
                                                order.total,
                                                order.currency,
                                            )
                                        }}</strong
                                    ><span
                                        class="mt-1 block text-[10px] text-muted-foreground"
                                        >{{
                                            order.currency || 'Unknown currency'
                                        }}</span
                                    >
                                </td>
                                <td class="px-5 py-4 text-right align-top">
                                    <span
                                        class="block text-[11px] whitespace-nowrap"
                                        >{{
                                            formatDate(order.created_at_wp)
                                        }}</span
                                    ><span
                                        class="mt-1 block text-[10px] text-muted-foreground"
                                        >{{
                                            formatDate(
                                                order.created_at_wp,
                                                'time',
                                            )
                                        }}</span
                                    >
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="orders.data.length" class="divide-y xl:hidden">
                    <article
                        v-for="order in orders.data"
                        :key="order.id"
                        class="p-4 sm:p-5"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <Link
                                        :href="`/orders/${order.id}`"
                                        class="text-sm font-semibold hover:underline"
                                        >#{{ order.wp_order_id }}</Link
                                    >
                                    <OrderStatusControl
                                        :order-id="order.id"
                                        :order-number="order.wp_order_id"
                                        :status="order.status"
                                        :website-name="order.website?.name"
                                        :disabled="
                                            filterLoading ||
                                            !order.can_update_status
                                        "
                                        @settled="liveRefresh.requestFresh()"
                                    />
                                </div>
                                <p
                                    class="mt-2 truncate text-xs text-muted-foreground"
                                >
                                    {{
                                        order.website?.name || 'Unknown website'
                                    }}
                                </p>
                            </div>
                            <div class="shrink-0 text-right">
                                <strong
                                    class="text-sm font-semibold tabular-nums"
                                    >{{
                                        formatCurrency(
                                            order.total,
                                            order.currency,
                                        )
                                    }}</strong
                                >
                                <p
                                    class="mt-1 text-[10px] text-muted-foreground"
                                >
                                    {{ order.currency || 'Unknown currency' }}
                                </p>
                            </div>
                        </div>
                        <div
                            class="mt-4 flex min-w-0 items-center justify-between gap-3"
                        >
                            <div class="flex min-w-0 items-center gap-2.5">
                                <span
                                    class="flex size-8 shrink-0 items-center justify-center rounded-full bg-muted text-[10px] font-medium text-muted-foreground"
                                    >{{ customerInitials(order) }}</span
                                >
                                <div class="min-w-0">
                                    <Link
                                        v-if="order.customer_email"
                                        :href="customerUrl(order)"
                                        class="block truncate text-xs font-medium hover:underline"
                                        >{{
                                            order.customer_name ||
                                            order.customer_email
                                        }}</Link
                                    >
                                    <p
                                        v-else
                                        class="truncate text-xs font-medium"
                                    >
                                        {{
                                            order.customer_name ||
                                            'Guest customer'
                                        }}
                                    </p>
                                    <p
                                        class="mt-1 truncate text-[11px] text-muted-foreground"
                                    >
                                        {{
                                            order.customer_email ||
                                            'No email provided'
                                        }}
                                    </p>
                                </div>
                            </div>
                            <Link
                                :href="`/orders/${order.id}`"
                                class="rounded-md border p-2 text-muted-foreground hover:bg-muted"
                                :aria-label="`View order ${order.wp_order_id}`"
                                ><ArrowUpRight
                                    class="size-4"
                                    aria-hidden="true"
                            /></Link>
                        </div>
                        <p
                            class="mt-3 border-t pt-3 text-[10px] text-muted-foreground"
                        >
                            {{ formatDate(order.created_at_wp) }} ·
                            {{ formatDate(order.created_at_wp, 'time') }}
                            {{ orders.timezone || 'UTC' }}
                        </p>
                    </article>
                </div>
                <div
                    v-if="!orders.data.length"
                    class="flex flex-col items-center gap-2 px-5 py-16 text-center"
                >
                    <span class="mb-2 rounded-full bg-muted p-4"
                        ><Search
                            class="size-6 text-muted-foreground"
                            aria-hidden="true"
                    /></span>
                    <h3 class="text-base font-semibold">
                        {{
                            hasFilters
                                ? 'No orders match these filters'
                                : 'No orders yet'
                        }}
                    </h3>
                    <p
                        class="max-w-sm text-sm leading-relaxed text-muted-foreground"
                    >
                        {{
                            hasFilters
                                ? 'Try a different customer, date range, or website. You can also clear the filters to see all orders.'
                                : 'New orders will appear automatically when your connected websites send them.'
                        }}
                    </p>
                    <Button
                        v-if="hasFilters"
                        variant="outline"
                        size="sm"
                        class="mt-3"
                        @click="resetFilters"
                        >Clear all filters</Button
                    >
                </div>
                <nav
                    v-if="orders.total > 0"
                    class="flex flex-wrap items-center justify-between gap-3 border-t bg-muted/20 px-4 py-4 sm:px-5"
                    aria-label="Order pagination"
                >
                    <p class="text-xs text-muted-foreground">
                        Page
                        <span class="font-medium text-foreground">{{
                            orders.current_page
                        }}</span>
                        of {{ orders.last_page
                        }}<span class="ml-2 hidden sm:inline"
                            >· {{ orders.total.toLocaleString() }} matching
                            orders</span
                        >
                    </p>
                    <div class="flex items-center gap-1.5">
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="
                                !orders.links[0]?.url ||
                                orders.current_page === 1 ||
                                filterLoading
                            "
                            aria-label="Previous page"
                            @click="goToPage(orders.links[0]?.url ?? null)"
                            ><ChevronLeft
                                class="size-4"
                                aria-hidden="true"
                            /><span class="hidden sm:inline"
                                >Previous</span
                            ></Button
                        ><template
                            v-for="(link, index) in orders.links"
                            :key="index"
                            ><span
                                v-if="
                                    index > 0 &&
                                    index < orders.links.length - 1 &&
                                    !link.url
                                "
                                class="hidden px-1 text-xs text-muted-foreground md:block"
                                >{{ decodeHtmlEntities(link.label) }}</span
                            ><Button
                                v-else-if="
                                    index > 0 && index < orders.links.length - 1
                                "
                                variant="outline"
                                size="sm"
                                class="hidden min-w-8 px-2 md:block"
                                :class="
                                    link.active
                                        ? 'border-primary bg-primary text-primary-foreground hover:bg-primary/90 hover:text-primary-foreground'
                                        : ''
                                "
                                :aria-current="link.active ? 'page' : undefined"
                                :aria-label="`Page ${decodeHtmlEntities(link.label)}`"
                                :disabled="filterLoading"
                                @click="goToPage(link.url)"
                                >{{ decodeHtmlEntities(link.label) }}</Button
                            ></template
                        ><Button
                            variant="outline"
                            size="sm"
                            :disabled="
                                !orders.links[orders.links.length - 1]?.url ||
                                orders.current_page >= orders.last_page ||
                                filterLoading
                            "
                            aria-label="Next page"
                            @click="
                                goToPage(
                                    orders.links[orders.links.length - 1]
                                        ?.url ?? null,
                                )
                            "
                            ><span class="hidden sm:inline">Next</span
                            ><ChevronRight class="size-4" aria-hidden="true"
                        /></Button>
                    </div>
                </nav>
            </section>
            <footer
                class="flex flex-wrap justify-between gap-2 text-[11px] text-muted-foreground"
            >
                <p>
                    Dates shown in {{ orders.timezone || 'UTC' }}. Revenue
                    includes completed orders only.
                </p>
                <p>
                    Live updates are automatic. Sync imports orders from
                    {{
                        scopeName === 'All websites'
                            ? 'all websites'
                            : scopeName
                    }}.
                </p>
            </footer>
        </main>
    </AppLayout>
</template>

<style scoped>
@reference "../../../css/app.css";
.orders-shell {
    background: linear-gradient(
        180deg,
        color-mix(in oklab, var(--muted) 35%, transparent),
        transparent 400px
    );
}
.orders-panel {
    @apply rounded-xl border bg-card shadow-sm;
}
.orders-metric {
    @apply min-w-0 rounded-xl border bg-card p-4 shadow-sm sm:p-5;
}
.orders-input {
    @apply h-10 min-w-0 rounded-lg border bg-background px-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-50;
}
.orders-search {
    padding-inline: 2.25rem;
}
</style>
