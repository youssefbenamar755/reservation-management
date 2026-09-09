<script setup lang="ts">
import OrderEmailComposer from '@/components/OrderEmailComposer.vue';
import OrderStatusControl from '@/components/OrderStatusControl.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useEchoNotifications } from '@/composables/useEchoNotifications';
import AppLayout from '@/layouts/AppLayout.vue';
import { getEcho } from '@/lib/echo';
import { createAutoRefresh, type AutoRefreshState } from '@/lib/liveOrders';
import {
    orderQueueAge,
    orderQueueEmailLabel,
    orderQueueNextAction,
    orderQueueStage,
    orderQueueStages,
    refreshOrderQueueSnapshot,
} from '@/lib/orderQueue';
import {
    subscribeToOrders,
    type OrdersConnectionState,
} from '@/lib/ordersPush';
import type {
    OrderQueueFilters,
    OrderQueueProps,
    OrderQueueRow,
} from '@/types/orderQueue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    CircleAlert,
    ClipboardList,
    Clock3,
    ExternalLink,
    FileText,
    Inbox,
    LoaderCircle,
    Mail,
    RefreshCw,
    Search,
    X,
} from 'lucide-vue-next';
import {
    computed,
    nextTick,
    onMounted,
    onUnmounted,
    ref,
    shallowRef,
    watch,
} from 'vue';

const props = defineProps<OrderQueueProps>();
const page = usePage();
const filterInputs = ref(appliedFilters());
const filterLoading = ref(false),
    filterErrors = ref<Record<string, string>>({});
const online = ref(true),
    visible = ref(true),
    channelState = ref<OrdersConnectionState>('connecting');
const refreshState = ref<AutoRefreshState>({
    refreshing: false,
    hasError: false,
    lastCheckedAt: null,
});
const scopeName = computed(
    () =>
        props.websites.find(
            (site) => String(site.id) === String(props.filters.website_id),
        )?.name ?? 'All websites',
);
const activeStage = computed(() =>
    props.filters.stage === 'all' ? null : orderQueueStage(props.filters.stage),
);
const hasFilters = computed(() =>
    Boolean(
        props.filters.website_id ||
        props.filters.search ||
        props.filters.stage !== 'all' ||
        props.filters.sort !== 'oldest' ||
        Number(props.filters.per_page) !== 25,
    ),
);
const liveLabel = computed(() =>
    !online.value
        ? 'Offline · refresh when connected'
        : refreshState.value.hasError
          ? 'Refresh needed'
          : refreshState.value.refreshing
            ? 'Updating queue…'
            : channelState.value === 'connected'
              ? 'Live order updates'
              : channelState.value === 'connecting'
                ? 'Connecting…'
                : channelState.value === 'reconnecting'
                  ? 'Reconnecting…'
                  : 'Live unavailable · Refresh available',
);
type EmailOrder = Pick<OrderQueueRow, 'id' | 'wp_order_id' | 'stage'> & {
    website_name: string;
};
interface ComposerHandle {
    open: (reviewLatest?: boolean) => void;
    getState: () => { open: boolean; sending: boolean; hasDraft: boolean };
}
const activeEmailOrder = shallowRef<EmailOrder | null>(null),
    pendingEmailOrder = shallowRef<EmailOrder | null>(null);
const composer = ref<ComposerHandle | null>(null),
    switchDialog = ref(false),
    composerNotice = ref('');
let mounted = false,
    disposed = false,
    activeUserId: number | null = null,
    emailGeneration = 0;
let searchTimer: ReturnType<typeof setTimeout> | null = null;
let filterRequest: { key: string; cancel?: () => void } | null = null;
let dispatchingFilter = false;
let stopSubscription: (() => void) | null = null;
const navigationVisits = new Set<object>(),
    cleanupListeners: Array<() => void> = [];
const { onNotification, offNotification } = useEchoNotifications();
const currentUserId = () => page.props.auth?.user?.id;
const available = () =>
    mounted &&
    !disposed &&
    activeUserId !== null &&
    currentUserId() === activeUserId &&
    online.value &&
    visible.value &&
    window.location.pathname === '/order-work-queue';
const liveRefresh = createAutoRefresh({
    isAvailable: available,
    onState: (state) => {
        refreshState.value = state;
    },
    refresh: ({ isCurrent, complete }) => {
        const controller = new AbortController(),
            owner = activeUserId;
        void refreshOrderQueueSnapshot({
            getUrl: () => window.location.href,
            isCurrent: () =>
                isCurrent() &&
                activeUserId === owner &&
                currentUserId() === owner,
            signal: controller.signal,
            apply: (queue, stillCurrent) =>
                new Promise<boolean>((resolve) => {
                    const ids = new Set(props.websites.map((site) => site.id));
                    if (
                        queue.data.some(
                            (order) =>
                                !ids.has(order.website_id) ||
                                (props.filters.website_id &&
                                    String(order.website_id) !==
                                        String(props.filters.website_id)),
                        )
                    ) {
                        throw new Error('Invalid queue scope');
                    }
                    let applied = false;
                    router.replaceProp(
                        'queue',
                        (current: unknown) => {
                            if (!stillCurrent()) return current;
                            applied = true;
                            return queue;
                        },
                        { onFinish: () => resolve(applied) },
                    );
                }),
        }).then(complete);
        return () => controller.abort();
    },
});
function appliedFilters() {
    return {
        website_id: String(props.filters.website_id ?? ''),
        search: props.filters.search || '',
        stage: props.filters.stage || 'all',
        sort: props.filters.sort || 'oldest',
        per_page: String(props.filters.per_page || 25),
    };
}
function clearSearch() {
    if (searchTimer !== null) clearTimeout(searchTimer);
    searchTimer = null;
}
function cancelFilters(restore = false) {
    clearSearch();
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
                !navigationVisits.size
            )
                liveRefresh.resume();
        });
    if (restore) {
        filterInputs.value = appliedFilters();
        filterErrors.value = {};
    }
}
function submitFilters(targetPage = 1) {
    clearSearch();
    const data = {
            ...filterInputs.value,
            ...(targetPage > 1 ? { page: targetPage } : {}),
        },
        key = JSON.stringify(data);
    if (filterRequest?.key === key) return;
    cancelFilters();
    if (
        disposed ||
        (targetPage === props.queue.current_page &&
            JSON.stringify(filterInputs.value) ===
                JSON.stringify(appliedFilters()))
    )
        return;
    const request: { key: string; cancel?: () => void } = { key };
    filterRequest = request;
    filterLoading.value = true;
    filterErrors.value = {};
    liveRefresh.suspend();
    dispatchingFilter = true;
    try {
        router.get('/order-work-queue', data, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onCancelToken: (token) => {
                if (filterRequest !== request) token.cancel();
                else request.cancel = () => token.cancel();
            },
            onSuccess: () => {
                if (filterRequest === request)
                    filterInputs.value = appliedFilters();
            },
            onError: (errors) => {
                if (filterRequest === request) filterErrors.value = errors;
            },
            onFinish: () => {
                if (filterRequest !== request || disposed) return;
                filterRequest = null;
                filterLoading.value = false;
                if (!navigationVisits.size) liveRefresh.resume();
            },
        });
    } finally {
        dispatchingFilter = false;
    }
}
function updateSearch(value: string) {
    cancelFilters();
    filterInputs.value.search = value;
    filterErrors.value = {};
    if (JSON.stringify(filterInputs.value) !== JSON.stringify(appliedFilters()))
        searchTimer = setTimeout(() => submitFilters(), 300);
}
function updateFilter<K extends keyof OrderQueueFilters>(
    key: K,
    value: string,
) {
    Object.assign(filterInputs.value, { [key]: value });
    submitFilters();
}
function resetFilters() {
    filterInputs.value = {
        website_id: '',
        search: '',
        stage: 'all',
        sort: 'oldest',
        per_page: '25',
    };
    submitFilters();
}
function goToPage(number: number) {
    if (number < 1 || number > props.queue.last_page) return;
    cancelFilters(true);
    submitFilters(number);
}
function requestForWebsite(value?: unknown) {
    const payload =
        value && typeof value === 'object'
            ? (value as Record<string, unknown>)
            : {};
    const data =
        payload.data && typeof payload.data === 'object'
            ? (payload.data as Record<string, unknown>)
            : {};
    const website = payload.website_id ?? data.website_id;
    if (
        props.filters.website_id &&
        website != null &&
        String(props.filters.website_id) !== String(website)
    )
        return;
    liveRefresh.request();
}
function bindUser() {
    liveRefresh.suspend();
    stopSubscription?.();
    stopSubscription = null;
    offNotification('order-work-queue');
    activeUserId = null;
    channelState.value = 'disconnected';
    const id = currentUserId();
    if (!Number.isSafeInteger(id) || !id || id < 1) return;
    activeUserId = id;
    const current = () =>
        mounted && activeUserId === id && currentUserId() === id;
    stopSubscription = subscribeToOrders({
        userId: id,
        getEcho,
        onOrder: (value) => {
            if (current()) requestForWebsite(value);
        },
        onSubscribed: () => {
            if (current()) liveRefresh.request();
        },
        onState: (state) => {
            if (current()) channelState.value = state;
        },
    });
    onNotification(
        'order-work-queue',
        (notification) => {
            if (
                current() &&
                ['order', 'form_submission'].includes(notification.type)
            )
                requestForWebsite(notification);
        },
        id,
        () => {
            if (current()) liveRefresh.request();
        },
    );
    if (!navigationVisits.size && !filterLoading.value) liveRefresh.resume();
}
function updateAvailability() {
    online.value = navigator.onLine;
    visible.value = !document.hidden;
    liveRefresh.availabilityChanged();
}
function suspendForHistory() {
    cancelFilters(true);
    liveRefresh.suspend();
}
function refreshAfterMutation() {
    liveRefresh.requestFresh();
}
async function activateEmail(order: EmailOrder, reviewLatest: boolean) {
    const token = ++emailGeneration,
        owner = currentUserId();
    activeEmailOrder.value = order;
    pendingEmailOrder.value = null;
    switchDialog.value = false;
    composerNotice.value = '';
    await nextTick();
    if (
        !disposed &&
        token === emailGeneration &&
        currentUserId() === owner &&
        activeEmailOrder.value?.id === order.id
    )
        composer.value?.open(reviewLatest);
}
function openEmail(order: OrderQueueRow) {
    if (
        disposed ||
        filterLoading.value ||
        !order.can_update_status ||
        !props.queue.data.some((row) => row.id === order.id)
    )
        return;
    const selected: EmailOrder = {
        id: order.id,
        wp_order_id: order.wp_order_id,
        website_name: order.website.name,
        stage: order.stage,
    };
    const state = composer.value?.getState();
    if (activeEmailOrder.value?.id === order.id) {
        composer.value?.open();
        return;
    }
    if (state?.sending) {
        composerNotice.value =
            'An email is being sent. Wait for its result before switching orders.';
        return;
    }
    if (state?.hasDraft) {
        pendingEmailOrder.value = selected;
        switchDialog.value = true;
        return;
    }
    void activateEmail(
        selected,
        order.stage !== 'prepare' && order.email !== null,
    );
}
function confirmEmailSwitch() {
    if (!pendingEmailOrder.value || composer.value?.getState().sending) return;
    void activateEmail(
        pendingEmailOrder.value,
        pendingEmailOrder.value.stage !== 'prepare',
    );
}
function setSwitchDialog(value: boolean) {
    switchDialog.value = value;
    if (!value) pendingEmailOrder.value = null;
}
function emailSettled(event: { orderId: number }) {
    if (!disposed && event.orderId === activeEmailOrder.value?.id)
        refreshAfterMutation();
}
function emailClosed() {
    composerNotice.value = '';
    // A closed preview request may already have reached the server.
    if (!disposed && activeEmailOrder.value) refreshAfterMutation();
}
function money(value: number | string, currency: string | null) {
    const amount = Number(value);
    if (!Number.isFinite(amount)) return '—';
    if (!currency)
        return amount.toLocaleString('en-US', { maximumFractionDigits: 2 });
    try {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency,
        }).format(amount);
    } catch {
        return `${amount.toLocaleString('en-US', { maximumFractionDigits: 2 })} ${currency}`;
    }
}
function dateLabel(value: string | null) {
    if (!value || !Number.isFinite(Date.parse(value)))
        return 'Date unavailable';
    try {
        return new Intl.DateTimeFormat('en-US', {
            timeZone: props.queue.timezone,
            month: 'short',
            day: 'numeric',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        }).format(new Date(value));
    } catch {
        return new Date(value).toLocaleString();
    }
}
function emailButton(order: OrderQueueRow) {
    return order.stage === 'attention' || order.stage === 'sending'
        ? 'Check email'
        : order.stage === 'ready' || order.stage === 'sent'
          ? 'Review email'
          : 'Email documents';
}
watch(
    () => props.filters,
    () => {
        if (
            !filterRequest &&
            searchTimer === null &&
            !Object.keys(filterErrors.value).length
        )
            filterInputs.value = appliedFilters();
    },
    { deep: true },
);
watch(
    () => page.url,
    () => {
        if (!mounted) return;
        liveRefresh.suspend();
        if (!navigationVisits.size && !filterLoading.value)
            liveRefresh.resume();
    },
    { flush: 'sync' },
);
watch(
    currentUserId,
    () => {
        emailGeneration++;
        activeEmailOrder.value = null;
        pendingEmailOrder.value = null;
        switchDialog.value = false;
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
            if (!dispatchingFilter) cancelFilters(true);
            liveRefresh.suspend();
            queueMicrotask(() => {
                if (mounted && !navigationVisits.size && !filterLoading.value)
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
            if (mounted && !navigationVisits.size && !filterLoading.value)
                liveRefresh.resume();
        }),
        router.on('navigate', () => {
            if (mounted && !navigationVisits.size && !filterLoading.value)
                liveRefresh.resume();
        }),
    );
    document.addEventListener('visibilitychange', updateAvailability);
    window.addEventListener('online', updateAvailability);
    window.addEventListener('offline', updateAvailability);
    window.addEventListener('focus', updateAvailability);
    window.addEventListener('popstate', suspendForHistory);
    bindUser();
});
onUnmounted(() => {
    disposed = true;
    mounted = false;
    emailGeneration++;
    cancelFilters();
    liveRefresh.stop();
    stopSubscription?.();
    offNotification('order-work-queue');
    cleanupListeners.forEach((remove) => remove());
    navigationVisits.clear();
    document.removeEventListener('visibilitychange', updateAvailability);
    window.removeEventListener('online', updateAvailability);
    window.removeEventListener('offline', updateAvailability);
    window.removeEventListener('focus', updateAvailability);
    window.removeEventListener('popstate', suspendForHistory);
});
</script>

<template>
    <Head title="Order work queue" />
    <AppLayout
        :breadcrumbs="[
            { title: 'Orders', href: '/orders' },
            { title: 'Work queue', href: '/order-work-queue' },
        ]"
    >
        <main
            class="mx-auto flex w-full max-w-[1600px] min-w-0 flex-col gap-6 p-4 pb-10 sm:p-6 lg:p-8"
            :aria-busy="filterLoading"
        >
            <header class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p
                        class="mb-2 text-xs font-semibold tracking-widest text-indigo-600 uppercase dark:text-indigo-300"
                    >
                        Daily operations
                    </p>
                    <h1
                        class="text-2xl font-semibold tracking-tight sm:text-3xl"
                    >
                        Order work queue
                    </h1>
                    <p class="mt-2 max-w-2xl text-sm text-muted-foreground">
                        Review reservations, prepare documents, and follow each
                        order through to completion.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button variant="outline" as-child
                        ><Link
                            :href="
                                props.filters.website_id
                                    ? `/orders?website_id=${encodeURIComponent(String(props.filters.website_id))}`
                                    : '/orders'
                            "
                            >All orders<ExternalLink
                                class="size-4" /></Link></Button
                    ><Button
                        :disabled="
                            filterLoading || refreshState.refreshing || !online
                        "
                        @click="liveRefresh.request(0)"
                        ><RefreshCw
                            class="size-4"
                            :class="{ 'animate-spin': refreshState.refreshing }"
                        />Refresh queue</Button
                    >
                </div>
            </header>
            <section
                class="flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-card px-4 py-3 text-xs"
            >
                <p class="flex items-center gap-2">
                    <span
                        class="size-2 rounded-full"
                        :class="
                            online &&
                            channelState === 'connected' &&
                            !refreshState.hasError
                                ? 'bg-emerald-500'
                                : 'bg-amber-500'
                        "
                    /><span role="status">{{ liveLabel }}</span>
                </p>
                <p class="text-muted-foreground">
                    Open WooCommerce orders · all dates · {{ scopeName }}
                </p>
                <p class="text-muted-foreground">
                    As of {{ dateLabel(queue.generated_at) }}
                </p>
            </section>
            <p
                v-if="refreshState.hasError"
                role="alert"
                class="rounded-lg border border-amber-500/30 bg-amber-500/5 p-3 text-sm"
            >
                The queue could not be refreshed. Displayed orders may be out of
                date. Use Refresh queue to check again.
            </p>
            <section
                v-if="activeEmailOrder"
                class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-indigo-500/25 bg-indigo-500/5 px-4 py-3"
            >
                <div class="flex min-w-0 items-start gap-2">
                    <Mail
                        class="mt-0.5 size-4 shrink-0 text-indigo-600 dark:text-indigo-300"
                    />
                    <div>
                        <p class="text-sm font-medium">
                            Email workspace · #{{
                                activeEmailOrder.wp_order_id
                            }}
                        </p>
                        <p class="mt-0.5 text-xs text-muted-foreground">
                            {{ activeEmailOrder.website_name }} · Kept open
                            across queue updates and filters.
                        </p>
                        <p
                            v-if="composerNotice"
                            role="status"
                            class="mt-2 text-xs text-amber-700 dark:text-amber-300"
                        >
                            {{ composerNotice }}
                        </p>
                    </div>
                </div>
                <Button variant="outline" size="sm" @click="composer?.open()"
                    >Resume email<ArrowRight class="size-3.5"
                /></Button>
            </section>
            <section
                class="rounded-xl border bg-card p-4"
                aria-label="Queue filters"
            >
                <form
                    class="flex flex-wrap items-end gap-3"
                    @submit.prevent="submitFilters()"
                >
                    <div class="min-w-40 flex-1">
                        <label for="queue-website" class="queue-label"
                            >Website</label
                        ><select
                            id="queue-website"
                            :value="filterInputs.website_id"
                            class="queue-input"
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
                                :value="String(website.id)"
                            >
                                {{ website.name }}
                            </option>
                        </select>
                    </div>
                    <div class="min-w-52 flex-[2]">
                        <label for="queue-search" class="queue-label"
                            >Search orders</label
                        >
                        <div class="relative">
                            <Search
                                class="pointer-events-none absolute top-2.5 left-3 size-4 text-muted-foreground"
                            /><input
                                id="queue-search"
                                :value="filterInputs.search"
                                class="queue-input queue-search-input"
                                placeholder="Order number, customer name or email…"
                                @input="
                                    updateSearch(
                                        ($event.target as HTMLInputElement)
                                            .value,
                                    )
                                "
                            />
                        </div>
                    </div>
                    <div class="min-w-36 flex-1">
                        <label for="queue-sort" class="queue-label">Order</label
                        ><select
                            id="queue-sort"
                            :value="filterInputs.sort"
                            class="queue-input"
                            @change="
                                updateFilter(
                                    'sort',
                                    ($event.target as HTMLSelectElement).value,
                                )
                            "
                        >
                            <option value="oldest">Oldest first</option>
                            <option value="newest">Newest first</option>
                        </select>
                    </div>
                    <Button
                        v-if="hasFilters"
                        variant="ghost"
                        type="button"
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
                    v-for="(error, field) in filterErrors"
                    :key="field"
                    role="alert"
                    class="mt-2 text-sm text-destructive"
                >
                    {{ error }}
                </p>
            </section>
            <section
                aria-label="Work stages"
                class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6"
            >
                <button
                    v-for="item in orderQueueStages"
                    :key="item.key"
                    type="button"
                    class="rounded-xl border bg-card p-4 text-left transition hover:border-indigo-400/60 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :class="
                        filterInputs.stage === item.key
                            ? 'border-indigo-500/60 ring-1 ring-indigo-500/20'
                            : ''
                    "
                    :aria-pressed="filterInputs.stage === item.key"
                    @click="
                        updateFilter(
                            'stage',
                            filterInputs.stage === item.key ? 'all' : item.key,
                        )
                    "
                >
                    <span
                        class="inline-flex rounded-md px-2 py-1 text-[11px] font-semibold"
                        :class="item.classes"
                        >{{ item.label }}</span
                    >
                    <p class="mt-3 text-2xl font-semibold tabular-nums">
                        {{ queue.summary[item.key].toLocaleString() }}
                    </p>
                    <p
                        class="sr-only mt-1 text-xs leading-relaxed text-muted-foreground sm:not-sr-only"
                    >
                        {{ item.description }}
                    </p>
                </button>
            </section>
            <p class="-mt-3 text-xs leading-relaxed text-muted-foreground">
                Email stages reflect your WP Hub email history; manual Gmail
                sends aren’t shown.
            </p>
            <section
                class="min-w-0 overflow-hidden rounded-xl border bg-card"
                aria-label="Orders to work on"
            >
                <div
                    class="flex flex-wrap items-center justify-between gap-3 border-b p-4"
                >
                    <div>
                        <h2 class="flex items-center gap-2 font-semibold">
                            <ClipboardList
                                class="size-4 text-indigo-600 dark:text-indigo-300"
                            />{{ activeStage?.label ?? 'All open work'
                            }}<span
                                class="rounded-md bg-muted px-2 py-0.5 text-xs tabular-nums"
                                >{{ queue.total.toLocaleString() }}</span
                            >
                        </h2>
                        <p class="mt-1 text-xs text-muted-foreground">
                            {{ queue.summary.all.toLocaleString() }} open orders
                            for this website and search scope. Stage counts
                            include all matching pages.
                        </p>
                    </div>
                    <Button
                        v-if="filters.stage !== 'all'"
                        variant="outline"
                        size="sm"
                        @click="updateFilter('stage', 'all')"
                        >All stages</Button
                    >
                </div>
                <div v-if="queue.data.length" class="divide-y">
                    <article
                        v-for="order in queue.data"
                        :key="order.id"
                        class="grid min-w-0 gap-4 p-4 transition hover:bg-muted/15 sm:p-5 xl:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)_minmax(0,1.2fr)]"
                    >
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <Link
                                    :href="`/orders/${order.id}`"
                                    class="text-base font-semibold hover:underline"
                                    >#{{ order.wp_order_id }}</Link
                                ><span
                                    class="rounded-md px-2 py-1 text-[11px] font-medium"
                                    :class="
                                        orderQueueStage(order.stage).classes
                                    "
                                    >{{
                                        orderQueueStage(order.stage).label
                                    }}</span
                                >
                            </div>
                            <p class="mt-2 text-sm font-medium break-words">
                                {{
                                    order.customer_name ||
                                    order.customer_email ||
                                    'Guest customer'
                                }}
                            </p>
                            <p
                                v-if="
                                    order.customer_email && order.customer_name
                                "
                                class="mt-1 text-xs break-all text-muted-foreground"
                            >
                                {{ order.customer_email }}
                            </p>
                            <div
                                class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-muted-foreground"
                            >
                                <span>{{ order.website.name }}</span
                                ><span class="font-medium">{{
                                    money(order.total, order.currency)
                                }}</span>
                            </div>
                            <div
                                class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs"
                            >
                                <span
                                    class="inline-flex items-center gap-1.5 text-muted-foreground"
                                    :title="dateLabel(order.created_at_wp)"
                                    ><Clock3 class="size-3.5" />Order age:
                                    {{
                                        orderQueueAge(
                                            order.created_at_wp,
                                            queue.generated_at,
                                        )
                                    }}</span
                                ><span class="text-muted-foreground">{{
                                    dateLabel(order.created_at_wp)
                                }}</span>
                            </div>
                        </div>
                        <div
                            class="min-w-0 space-y-3 rounded-lg bg-muted/20 p-3"
                        >
                            <div>
                                <p class="queue-label">WooCommerce status</p>
                                <OrderStatusControl
                                    :order-id="order.id"
                                    :order-number="order.wp_order_id"
                                    :status="order.status"
                                    :website-name="order.website.name"
                                    :disabled="
                                        filterLoading ||
                                        !order.can_update_status
                                    "
                                    @settled="refreshAfterMutation"
                                />
                            </div>
                            <div>
                                <p class="queue-label">Document email</p>
                                <p
                                    class="flex items-center gap-1.5 text-xs font-medium"
                                    :class="
                                        order.stage === 'attention'
                                            ? 'text-rose-700 dark:text-rose-300'
                                            : ''
                                    "
                                >
                                    <CircleAlert
                                        v-if="order.stage === 'attention'"
                                        class="size-3.5 shrink-0"
                                    /><CheckCircle2
                                        v-else-if="
                                            order.email?.status === 'sent'
                                        "
                                        class="size-3.5 shrink-0 text-emerald-600"
                                    /><Mail
                                        v-else
                                        class="size-3.5 shrink-0 text-muted-foreground"
                                    />{{ orderQueueEmailLabel(order) }}
                                </p>
                                <p
                                    v-if="
                                        order.email?.sent_at ||
                                        order.email?.created_at
                                    "
                                    class="mt-1 text-[11px] text-muted-foreground"
                                >
                                    {{
                                        order.email.sent_at
                                            ? 'Accepted'
                                            : 'Email record'
                                    }}
                                    {{
                                        dateLabel(
                                            order.email.sent_at ||
                                                order.email.created_at,
                                        )
                                    }}
                                </p>
                            </div>
                        </div>
                        <div
                            class="flex min-w-0 flex-col justify-between gap-4"
                        >
                            <div>
                                <p class="queue-label">Next step</p>
                                <p class="text-sm leading-relaxed">
                                    {{ orderQueueNextAction(order) }}
                                </p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <Button
                                    size="sm"
                                    :disabled="
                                        filterLoading ||
                                        !order.can_update_status
                                    "
                                    @click="openEmail(order)"
                                    ><Mail class="size-3.5" />{{
                                        emailButton(order)
                                    }}</Button
                                ><Button
                                    v-if="order.submission_id"
                                    variant="outline"
                                    size="sm"
                                    as-child
                                    ><Link
                                        :href="`/submissions/entries/${order.submission_id}`"
                                        ><FileText
                                            class="size-3.5"
                                        />Submission</Link
                                    ></Button
                                ><Link
                                    :href="`/orders/${order.id}`"
                                    class="inline-flex items-center gap-1 px-1 text-xs font-medium text-muted-foreground hover:text-foreground"
                                    >Order details<ArrowRight class="size-3.5"
                                /></Link>
                            </div>
                        </div>
                    </article>
                </div>
                <div
                    v-else
                    class="flex flex-col items-center px-6 py-14 text-center"
                >
                    <Inbox class="mb-4 size-9 text-muted-foreground/60" />
                    <h3 class="font-semibold">
                        {{
                            filters.search || filters.stage !== 'all'
                                ? 'No orders match this view'
                                : 'No open orders in this scope'
                        }}
                    </h3>
                    <p
                        class="mt-2 max-w-md text-sm leading-relaxed text-muted-foreground"
                    >
                        {{
                            filters.search || filters.stage !== 'all'
                                ? 'Try another search or view all work stages.'
                                : 'Pending, on-hold, and processing orders appear here. Completed and closed orders remain in All orders.'
                        }}
                    </p>
                    <Button
                        v-if="queue.current_page > queue.last_page"
                        class="mt-4"
                        variant="outline"
                        @click="goToPage(queue.last_page)"
                        >Go to the last available page</Button
                    ><Button
                        v-else-if="filters.search || filters.stage !== 'all'"
                        class="mt-4"
                        variant="outline"
                        @click="
                            filterInputs.search = '';
                            updateFilter('stage', 'all');
                        "
                        >Clear search and stage</Button
                    >
                </div>
                <div
                    class="flex flex-wrap items-center justify-between gap-3 border-t px-4 py-3 text-xs text-muted-foreground"
                >
                    <div class="flex flex-wrap items-center gap-3">
                        <span
                            >{{ queue.from ?? 0 }}–{{ queue.to ?? 0 }} of
                            {{ queue.total.toLocaleString() }} orders</span
                        ><label
                            class="flex items-center gap-2"
                            for="queue-per-page"
                            >Per page<select
                                id="queue-per-page"
                                class="h-8 rounded-md border bg-background px-2 text-xs"
                                :value="filterInputs.per_page"
                                @change="
                                    updateFilter(
                                        'per_page',
                                        ($event.target as HTMLSelectElement)
                                            .value,
                                    )
                                "
                            >
                                <option value="25">25</option>
                                <option value="50">50</option>
                            </select></label
                        >
                    </div>
                    <div class="flex items-center gap-2">
                        <Button
                            variant="outline"
                            size="icon"
                            class="size-8"
                            :disabled="filterLoading || queue.current_page <= 1"
                            aria-label="Previous queue page"
                            @click="goToPage(queue.current_page - 1)"
                            ><ChevronLeft class="size-4" /></Button
                        ><span
                            >Page {{ queue.current_page }} of
                            {{ queue.last_page }}</span
                        ><Button
                            variant="outline"
                            size="icon"
                            class="size-8"
                            :disabled="
                                filterLoading ||
                                queue.current_page >= queue.last_page
                            "
                            aria-label="Next queue page"
                            @click="goToPage(queue.current_page + 1)"
                            ><ChevronRight class="size-4"
                        /></Button>
                    </div>
                </div>
            </section>
            <footer
                class="grid gap-3 text-xs leading-relaxed text-muted-foreground md:grid-cols-3"
            >
                <p>
                    <span class="font-medium text-foreground">Scope.</span> Only
                    pending, on-hold, and processing WooCommerce orders. Waiting
                    is a prompt to review the order, not proof of an unpaid
                    balance.
                </p>
                <p>
                    <span class="font-medium text-foreground"
                        >Email history.</span
                    >
                    Stages reflect your emails recorded in WP Hub. Messages sent
                    manually from Gmail and documents in your booking system are
                    not inferred.
                </p>
                <p>
                    <span class="font-medium text-foreground">Completion.</span>
                    Sent means Gmail accepted the email, not that it was read.
                    Order completion remains an explicit status change.
                </p>
            </footer>
        </main>
        <OrderEmailComposer
            v-if="activeEmailOrder"
            :key="activeEmailOrder.id"
            ref="composer"
            :order-id="activeEmailOrder.id"
            :order-number="activeEmailOrder.wp_order_id"
            hide-trigger
            @settled="emailSettled"
            @closed="emailClosed"
        />
        <Dialog :open="switchDialog" @update:open="setSwitchDialog"
            ><DialogContent class="sm:max-w-md"
                ><DialogHeader
                    ><DialogTitle>Switch the email workspace?</DialogTitle
                    ><DialogDescription
                        >You have an unfinished email for order #{{
                            activeEmailOrder?.wp_order_id
                        }}. Switching to #{{
                            pendingEmailOrder?.wp_order_id
                        }}
                        will discard its local message edits and selected files.
                        Saved previews remain in that order’s email
                        history.</DialogDescription
                    ></DialogHeader
                >
                <p class="text-xs leading-relaxed text-muted-foreground">
                    If a send result is uncertain, check Gmail Sent before
                    preparing another message.
                </p>
                <DialogFooter
                    ><Button variant="outline" @click="setSwitchDialog(false)"
                        >Keep current draft</Button
                    ><Button @click="confirmEmailSwitch"
                        >Discard draft and switch</Button
                    ></DialogFooter
                ></DialogContent
            ></Dialog
        >
    </AppLayout>
</template>

<style scoped>
@reference "../../../css/app.css";
.queue-label {
    @apply mb-1.5 block text-xs font-medium text-muted-foreground;
}
.queue-input {
    @apply h-9 w-full min-w-0 rounded-md border border-input bg-background px-3 text-sm shadow-xs outline-none focus-visible:ring-2 focus-visible:ring-ring;
}
.queue-search-input {
    @apply pl-9;
}
</style>
