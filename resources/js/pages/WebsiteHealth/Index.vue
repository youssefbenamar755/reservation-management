<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    createWebhookRecovery,
    type RecoveryState,
} from '@/lib/webhookRecovery';
import type { BreadcrumbItem } from '@/types';
import type { HealthFilters, WebsiteHealth } from '@/types/websiteHealth';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Activity,
    ArrowRight,
    CheckCircle2,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    Clock3,
    Globe,
    History,
    RefreshCw,
    RotateCcw,
    ShieldCheck,
    TriangleAlert,
} from 'lucide-vue-next';
import { computed, onUnmounted, ref } from 'vue';

const props = defineProps<{
    health: WebsiteHealth;
    filters: HealthFilters;
    websites: Array<{ id: number; name: string }>;
}>();
const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Website health', href: '/website-health' },
];
const loading = ref(false);
const pageError = ref('');
const confirming = ref(false);
let refreshAfterNavigation = false;
const recoveryState = ref<RecoveryState>({
    eventId: null,
    detail: null,
    loading: false,
    retrying: false,
    error: '',
    notice: '',
});
const scopeName = computed(
    () =>
        props.websites.find((site) => site.id === props.filters.website_id)
            ?.name || 'All websites',
);
const filtered = computed(() =>
    Boolean(
        props.filters.website_id ||
        props.filters.status !== 'failed' ||
        props.filters.source !== 'all' ||
        props.filters.range !== '24h',
    ),
);
const statusNames: Record<string, string> = {
    failed: 'Failed',
    queued: 'Waiting',
    processed: 'Processed',
    running: 'Processing',
    succeeded: 'Succeeded',
    skipped: 'Skipped',
};
const sourceNames: Record<string, string> = {
    woocommerce: 'WooCommerce',
    fluentforms: 'Fluent Forms',
};
const recovery = createWebhookRecovery({
    fetch: (...args) => fetch(...args),
    csrf: () =>
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content || '',
    onState: (state) => {
        recoveryState.value = state;
    },
    onRetrySettled: () => {
        if (loading.value) refreshAfterNavigation = true;
        else refreshHealth();
    },
});
const dialogOpen = computed({
    get: () => recoveryState.value.eventId !== null,
    set: (open: boolean) => {
        if (!open) {
            confirming.value = false;
            recovery.close();
        }
    },
});
let navigation = 0;
let cancelNavigation: (() => void) | null = null;

function navigate(params: Record<string, string | number | null>) {
    const visit = ++navigation;
    cancelNavigation?.();
    cancelNavigation = null;
    loading.value = true;
    pageError.value = '';
    router.get('/website-health', params, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onCancelToken: (token) => {
            if (visit === navigation) cancelNavigation = () => token.cancel();
            else token.cancel();
        },
        onError: () => {
            if (visit === navigation)
                pageError.value =
                    'These filters could not be applied. Please try again.';
        },
        onFinish: () => {
            if (visit === navigation) {
                loading.value = false;
                cancelNavigation = null;
                if (refreshAfterNavigation) {
                    refreshAfterNavigation = false;
                    refreshHealth();
                }
            }
        },
    });
}
function setFilter(key: keyof HealthFilters, value: string) {
    navigate({ ...props.filters, [key]: value, page: 1 });
}
function resetFilters() {
    navigate({
        website_id: null,
        status: 'failed',
        source: 'all',
        range: '24h',
        page: 1,
    });
}
function showAll() {
    navigate({ ...props.filters, status: 'all', range: 'all', page: 1 });
}
function refreshHealth() {
    navigate({ ...props.filters, page: props.health.events.current_page });
}
function pageTo(number: number) {
    if (loading.value || number < 1 || number > props.health.events.last_page)
        return;
    navigate({ ...props.filters, page: number });
}
function openDelivery(id: number) {
    confirming.value = false;
    void recovery.open(id);
}
function refreshDetail() {
    if (recoveryState.value.eventId && !recoveryState.value.retrying) {
        confirming.value = false;
        void recovery.open(recoveryState.value.eventId);
    }
}
function confirmRetry() {
    confirming.value = false;
    void recovery.retry();
}
function date(value: string | null) {
    if (!value) return 'No activity recorded';
    const parsed = new Date(value);
    if (Number.isNaN(parsed.getTime())) return 'Date unavailable';
    return new Intl.DateTimeFormat('en-US', {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        timeZone: props.health.timezone || 'UTC',
    }).format(parsed);
}
function badge(status: string) {
    if (['processed', 'succeeded'].includes(status))
        return 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300';
    if (status === 'failed')
        return 'bg-rose-500/10 text-rose-700 dark:text-rose-300';
    if (['queued', 'running'].includes(status))
        return 'bg-amber-500/10 text-amber-800 dark:text-amber-300';
    return 'bg-muted text-muted-foreground';
}
onUnmounted(() => {
    navigation++;
    cancelNavigation?.();
    recovery.dispose();
});
</script>

<template>
    <Head title="Website health" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <main
            class="health-shell flex min-w-0 flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8"
            :aria-busy="loading"
        >
            <header class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p
                        class="mb-2 flex items-center gap-2 text-[10px] font-medium tracking-[0.18em] text-muted-foreground uppercase"
                    >
                        <Activity
                            class="size-3.5"
                            aria-hidden="true"
                        />Connection overview
                    </p>
                    <h1
                        class="text-2xl font-semibold tracking-tight sm:text-3xl"
                    >
                        Website health
                    </h1>
                    <p
                        class="mt-2 max-w-xl text-sm leading-relaxed text-muted-foreground"
                    >
                        Track deliveries and recover failed imports across your
                        websites.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="loading"
                        @click="refreshHealth"
                        ><RefreshCw
                            class="size-3.5"
                            :class="{ 'animate-spin': loading }"
                            aria-hidden="true"
                        />Refresh</Button
                    >
                    <Button variant="outline" size="sm" as-child
                        ><Link href="/websites"
                            ><Globe class="size-3.5" aria-hidden="true" />Manage
                            websites</Link
                        ></Button
                    >
                </div>
            </header>

            <div
                class="flex flex-wrap justify-between gap-2 text-xs text-muted-foreground"
            >
                <p>
                    <span class="font-medium text-foreground">{{
                        scopeName
                    }}</span>
                    · Delivery overview
                </p>
                <p>
                    Checked {{ date(health.checked_at) }} ·
                    {{ health.timezone }}
                </p>
            </div>
            <section
                aria-label="Delivery overview"
                class="grid grid-cols-2 gap-3 xl:grid-cols-4"
            >
                <article class="health-metric">
                    <div class="flex items-start justify-between gap-2">
                        <h2 class="text-xs text-muted-foreground">
                            Failed in 24 hours
                        </h2>
                        <TriangleAlert
                            class="size-4 shrink-0 text-rose-500"
                            aria-hidden="true"
                        />
                    </div>
                    <p class="mt-4 text-3xl font-semibold tabular-nums">
                        {{ health.summary.failed_recent.toLocaleString() }}
                    </p>
                    <p
                        class="mt-2 text-[11px] leading-relaxed text-muted-foreground"
                    >
                        Recent deliveries still marked failed
                    </p>
                </article>
                <article class="health-metric">
                    <div class="flex items-start justify-between gap-2">
                        <h2 class="text-xs text-muted-foreground">
                            All failed deliveries
                        </h2>
                        <History
                            class="size-4 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                    </div>
                    <p class="mt-4 text-3xl font-semibold tabular-nums">
                        {{ health.summary.failed_total.toLocaleString() }}
                    </p>
                    <p
                        class="mt-2 text-[11px] leading-relaxed text-muted-foreground"
                    >
                        Includes historical failures
                    </p>
                </article>
                <article class="health-metric">
                    <div class="flex items-start justify-between gap-2">
                        <h2 class="text-xs text-muted-foreground">
                            Waiting to process
                        </h2>
                        <Clock3
                            class="size-4 shrink-0 text-amber-500"
                            aria-hidden="true"
                        />
                    </div>
                    <p class="mt-4 text-3xl font-semibold tabular-nums">
                        {{ health.summary.queued.toLocaleString() }}
                    </p>
                    <p
                        class="mt-2 text-[11px] leading-relaxed text-muted-foreground"
                    >
                        {{
                            health.summary.oldest_queued_at
                                ? `Oldest: ${date(health.summary.oldest_queued_at)}`
                                : 'No deliveries waiting'
                        }}
                    </p>
                </article>
                <article class="health-metric">
                    <div class="flex items-start justify-between gap-2">
                        <h2 class="text-xs text-muted-foreground">
                            Processed in 24 hours
                        </h2>
                        <CheckCircle2
                            class="size-4 shrink-0 text-emerald-500"
                            aria-hidden="true"
                        />
                    </div>
                    <p class="mt-4 text-3xl font-semibold tabular-nums">
                        {{ health.summary.processed_recent.toLocaleString() }}
                    </p>
                    <p
                        class="mt-2 text-[11px] leading-relaxed text-muted-foreground"
                    >
                        Deliveries processed successfully
                    </p>
                </article>
            </section>

            <details class="group">
                <summary
                    class="flex cursor-pointer list-none items-center justify-between gap-3 rounded-lg border bg-card px-4 py-3 text-sm font-medium [&::-webkit-details-marker]:hidden"
                >
                    <span
                        >Website connections
                        <span
                            class="ml-2 text-xs font-normal text-muted-foreground"
                            >{{ health.sites.length }}
                            {{
                                health.sites.length === 1
                                    ? 'website'
                                    : 'websites'
                            }}</span
                        ></span
                    >
                    <ChevronDown
                        class="size-4 shrink-0 text-muted-foreground transition-transform group-open:rotate-180"
                        aria-hidden="true"
                    />
                </summary>
                <section
                    aria-label="Website connections"
                    class="mt-3 grid gap-3 lg:grid-cols-2 xl:grid-cols-3"
                >
                    <article
                        v-for="site in health.sites"
                        :key="site.id"
                        class="health-panel min-w-0 p-4 sm:p-5"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <h2
                                class="min-w-0 truncate text-sm font-semibold"
                                :title="site.name"
                            >
                                {{ site.name }}
                            </h2>
                            <span
                                class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-medium"
                                :class="
                                    site.status !== 'active'
                                        ? 'bg-muted text-muted-foreground'
                                        : site.failed_recent
                                          ? badge('failed')
                                          : site.queued
                                            ? badge('queued')
                                            : 'bg-muted text-muted-foreground'
                                "
                                >{{
                                    site.status !== 'active'
                                        ? 'Paused'
                                        : site.failed_recent
                                          ? 'Recent failures'
                                          : site.queued
                                            ? 'Deliveries waiting'
                                            : 'No recent failures'
                                }}</span
                            >
                        </div>
                        <div
                            class="mt-4 flex flex-wrap gap-x-4 gap-y-1 text-xs"
                        >
                            <span
                                ><strong class="font-semibold">{{
                                    site.failed_recent
                                }}</strong>
                                failed in 24h</span
                            ><span class="text-muted-foreground"
                                >{{ site.failed_total }} failed overall</span
                            ><span class="text-muted-foreground"
                                >{{ site.queued }} waiting</span
                            >
                        </div>
                        <dl
                            class="mt-4 grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-2 border-t pt-3 text-[11px]"
                        >
                            <dt class="text-muted-foreground">Last delivery</dt>
                            <dd class="text-right">
                                {{ date(site.last_webhook_at) }}
                            </dd>
                            <dt class="text-muted-foreground">
                                Last processed
                            </dt>
                            <dd class="text-right">
                                {{ date(site.last_processed_at) }}
                            </dd>
                            <dt class="text-muted-foreground">
                                Last order import
                            </dt>
                            <dd class="text-right">
                                {{ date(site.wc_orders_synced_at) }}
                            </dd>
                        </dl>
                        <div class="mt-4 flex justify-end">
                            <button
                                type="button"
                                :disabled="loading"
                                class="inline-flex items-center gap-1 text-xs font-medium hover:underline disabled:opacity-50"
                                :aria-label="`View deliveries for ${site.name}`"
                                @click="
                                    navigate({
                                        ...filters,
                                        website_id: site.id,
                                        status: 'all',
                                        page: 1,
                                    })
                                "
                            >
                                View deliveries<ArrowRight
                                    class="size-3.5"
                                    aria-hidden="true"
                                />
                            </button>
                        </div>
                    </article>
                    <div
                        v-if="!health.sites.length"
                        class="health-panel p-6 text-sm text-muted-foreground lg:col-span-2"
                    >
                        No websites are available in this view.
                        <Link
                            href="/websites"
                            class="font-medium text-foreground underline"
                            >Manage websites</Link
                        >
                    </div>
                </section>
            </details>

            <section
                class="health-panel p-4 sm:p-5"
                aria-label="Delivery filters"
            >
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <label class="health-label"
                        >Website<select
                            class="health-select"
                            :value="filters.website_id || ''"
                            :disabled="loading"
                            @change="
                                setFilter(
                                    'website_id',
                                    ($event.target as HTMLSelectElement).value,
                                )
                            "
                        >
                            <option value="">All websites</option>
                            <option
                                v-for="site in websites"
                                :key="site.id"
                                :value="site.id"
                            >
                                {{ site.name }}
                            </option>
                        </select></label
                    >
                    <label class="health-label"
                        >Delivery status<select
                            class="health-select"
                            :value="filters.status"
                            :disabled="loading"
                            @change="
                                setFilter(
                                    'status',
                                    ($event.target as HTMLSelectElement).value,
                                )
                            "
                        >
                            <option value="failed">Failed</option>
                            <option value="queued">Waiting</option>
                            <option value="processed">Processed</option>
                            <option value="all">All statuses</option>
                        </select></label
                    >
                    <label class="health-label"
                        >Source<select
                            class="health-select"
                            :value="filters.source"
                            :disabled="loading"
                            @change="
                                setFilter(
                                    'source',
                                    ($event.target as HTMLSelectElement).value,
                                )
                            "
                        >
                            <option value="all">All sources</option>
                            <option value="woocommerce">WooCommerce</option>
                            <option value="fluentforms">Fluent Forms</option>
                        </select></label
                    >
                    <label class="health-label"
                        >Received<select
                            class="health-select"
                            :value="filters.range"
                            :disabled="loading"
                            @change="
                                setFilter(
                                    'range',
                                    ($event.target as HTMLSelectElement).value,
                                )
                            "
                        >
                            <option value="24h">Last 24 hours</option>
                            <option value="7d">Last 7 days</option>
                            <option value="30d">Last 30 days</option>
                            <option value="all">All time</option>
                        </select></label
                    >
                </div>
                <div
                    class="mt-4 flex flex-wrap items-center justify-between gap-3 text-[11px] text-muted-foreground"
                >
                    <p>
                        These filters apply to the delivery list. Overview
                        totals use the selected website.
                    </p>
                    <button
                        v-if="filtered"
                        type="button"
                        :disabled="loading"
                        class="font-medium text-foreground hover:underline disabled:opacity-50"
                        @click="resetFilters"
                    >
                        Reset filters
                    </button>
                </div>
                <p
                    v-if="pageError"
                    role="alert"
                    class="mt-3 text-sm text-destructive"
                >
                    {{ pageError }}
                </p>
            </section>

            <section
                class="health-panel min-w-0 overflow-hidden"
                aria-labelledby="deliveries-title"
            >
                <div
                    class="flex flex-wrap items-center justify-between gap-2 border-b px-4 py-4 sm:px-5"
                >
                    <div>
                        <h2 id="deliveries-title" class="text-sm font-semibold">
                            Delivery history
                        </h2>
                        <p class="mt-1 text-xs text-muted-foreground">
                            {{ health.events.from || 0 }}–{{
                                health.events.to || 0
                            }}
                            of
                            {{ health.events.total.toLocaleString() }}
                            deliveries
                        </p>
                    </div>
                    <span class="text-[11px] text-muted-foreground"
                        >Most recent first · {{ health.timezone }}</span
                    >
                </div>
                <div v-if="health.events.data.length" class="hidden xl:block">
                    <table class="w-full table-fixed text-left text-xs">
                        <caption class="sr-only">
                            Webhook deliveries matching your filters
                        </caption>
                        <thead
                            class="border-b bg-muted/25 text-[11px] text-muted-foreground"
                        >
                            <tr>
                                <th class="w-[23%] px-5 py-3 font-medium">
                                    Website / source
                                </th>
                                <th class="w-[17%] px-3 py-3 font-medium">
                                    Delivery
                                </th>
                                <th class="w-[13%] px-3 py-3 font-medium">
                                    Status
                                </th>
                                <th class="w-[30%] px-3 py-3 font-medium">
                                    Result
                                </th>
                                <th
                                    class="w-[17%] px-5 py-3 text-right font-medium"
                                >
                                    Received
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="event in health.events.data"
                                :key="event.id"
                                class="align-top hover:bg-muted/20"
                            >
                                <td class="px-5 py-4">
                                    <p
                                        class="truncate font-medium"
                                        :title="event.website.name"
                                    >
                                        {{ event.website.name }}
                                    </p>
                                    <p
                                        class="mt-1.5 text-[11px] text-muted-foreground"
                                    >
                                        {{
                                            sourceNames[event.source] ||
                                            event.source
                                        }}
                                    </p>
                                </td>
                                <td class="px-3 py-4">
                                    <button
                                        type="button"
                                        class="font-semibold hover:underline"
                                        :aria-label="`View delivery ${event.id}`"
                                        @click="openDelivery(event.id)"
                                    >
                                        #{{ event.id }}
                                    </button>
                                    <p
                                        class="mt-1.5 truncate text-[11px] text-muted-foreground"
                                        :title="event.topic"
                                    >
                                        {{ event.topic }}
                                    </p>
                                    <p
                                        v-if="event.external_id"
                                        class="mt-1 truncate text-[10px] text-muted-foreground"
                                    >
                                        Reference #{{ event.external_id }}
                                    </p>
                                </td>
                                <td class="px-3 py-4">
                                    <span
                                        class="inline-flex rounded-full px-2 py-1 text-[10px] font-medium"
                                        :class="badge(event.status)"
                                        >{{
                                            statusNames[event.status] ||
                                            event.status
                                        }}</span
                                    >
                                    <p
                                        v-if="event.attempts_count"
                                        class="mt-2 text-[10px] text-muted-foreground"
                                    >
                                        {{ event.attempts_count }}
                                        {{
                                            event.attempts_count === 1
                                                ? 'retry'
                                                : 'retries'
                                        }}
                                    </p>
                                </td>
                                <td class="px-3 py-4">
                                    <p
                                        class="text-[11px] leading-relaxed text-muted-foreground"
                                    >
                                        {{
                                            event.failure_summary ||
                                            (event.status === 'processed'
                                                ? 'Delivery processed successfully.'
                                                : 'Waiting for processing.')
                                        }}
                                    </p>
                                    <button
                                        type="button"
                                        class="mt-2 inline-flex items-center gap-1 text-[11px] font-medium hover:underline"
                                        :aria-label="`Details for delivery ${event.id}`"
                                        @click="openDelivery(event.id)"
                                    >
                                        View details<ArrowRight
                                            class="size-3"
                                            aria-hidden="true"
                                        />
                                    </button>
                                </td>
                                <td
                                    class="px-5 py-4 text-right text-[11px] leading-relaxed text-muted-foreground"
                                >
                                    {{ date(event.received_at) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div
                    v-if="health.events.data.length"
                    class="divide-y xl:hidden"
                >
                    <article
                        v-for="event in health.events.data"
                        :key="event.id"
                        class="p-4 sm:p-5"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h3 class="truncate text-sm font-semibold">
                                    {{ event.website.name }}
                                </h3>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    {{
                                        sourceNames[event.source] ||
                                        event.source
                                    }}
                                    · #{{ event.id }}
                                </p>
                            </div>
                            <span
                                class="shrink-0 rounded-full px-2 py-1 text-[10px] font-medium"
                                :class="badge(event.status)"
                                >{{
                                    statusNames[event.status] || event.status
                                }}</span
                            >
                        </div>
                        <p
                            class="mt-3 text-xs leading-relaxed text-muted-foreground"
                        >
                            {{
                                event.failure_summary ||
                                (event.status === 'processed'
                                    ? 'Delivery processed successfully.'
                                    : 'Waiting for processing.')
                            }}
                        </p>
                        <div
                            class="mt-4 flex items-center justify-between gap-3 border-t pt-3"
                        >
                            <p class="text-[10px] text-muted-foreground">
                                {{ date(event.received_at)
                                }}<span
                                    v-if="event.attempts_count"
                                    class="mt-1 block"
                                    >{{ event.attempts_count }}
                                    {{
                                        event.attempts_count === 1
                                            ? 'retry'
                                            : 'retries'
                                    }}</span
                                >
                            </p>
                            <Button
                                variant="outline"
                                size="sm"
                                :aria-label="`Details for delivery ${event.id}`"
                                @click="openDelivery(event.id)"
                                >Details<ArrowRight
                                    class="size-3.5"
                                    aria-hidden="true"
                            /></Button>
                        </div>
                    </article>
                </div>
                <div
                    v-if="!health.events.data.length"
                    class="flex flex-col items-center px-5 py-14 text-center"
                >
                    <div class="mb-4 rounded-full bg-muted p-4">
                        <CheckCircle2
                            v-if="filters.status === 'failed'"
                            class="size-6 text-emerald-600 dark:text-emerald-400"
                            aria-hidden="true"
                        /><Activity
                            v-else
                            class="size-6 text-muted-foreground"
                            aria-hidden="true"
                        />
                    </div>
                    <h3 class="font-semibold">
                        {{
                            filters.status === 'failed'
                                ? 'No failed deliveries in this view'
                                : 'No deliveries in this view'
                        }}
                    </h3>
                    <p
                        class="mt-2 max-w-md text-sm leading-relaxed text-muted-foreground"
                    >
                        Try a different website, source, or period to explore
                        delivery activity. A quiet website may simply have no
                        new orders or submissions.
                    </p>
                    <Button
                        variant="outline"
                        size="sm"
                        class="mt-4"
                        :disabled="loading"
                        @click="showAll"
                        >Show all deliveries</Button
                    >
                </div>
                <nav
                    v-if="health.events.total"
                    aria-label="Delivery pagination"
                    class="flex flex-wrap items-center justify-between gap-3 border-t bg-muted/20 px-4 py-4 sm:px-5"
                >
                    <p class="text-xs text-muted-foreground">
                        Page {{ health.events.current_page }} of
                        {{ health.events.last_page }}
                    </p>
                    <div class="flex gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="loading || !health.events.prev_page_url"
                            @click="pageTo(health.events.current_page - 1)"
                            ><ChevronLeft
                                class="size-4"
                                aria-hidden="true"
                            />Previous</Button
                        ><Button
                            variant="outline"
                            size="sm"
                            :disabled="loading || !health.events.next_page_url"
                            @click="pageTo(health.events.current_page + 1)"
                            >Next<ChevronRight
                                class="size-4"
                                aria-hidden="true"
                        /></Button>
                    </div>
                </nav>
            </section>
            <footer
                class="flex flex-wrap justify-between gap-2 text-[11px] leading-relaxed text-muted-foreground"
            >
                <p>
                    Historical failures may already have been resolved by a
                    later import.
                </p>
                <p>
                    This page updates when opened, refreshed, or after a retry.
                </p>
            </footer>
        </main>

        <Dialog v-model:open="dialogOpen">
            <DialogContent class="max-h-[90dvh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader class="pr-7 text-left"
                    ><DialogTitle
                        >Delivery #{{ recoveryState.eventId }}</DialogTitle
                    ><DialogDescription
                        >Review the delivery result and its retry
                        history.</DialogDescription
                    ></DialogHeader
                >
                <div
                    v-if="recoveryState.loading"
                    role="status"
                    class="flex items-center gap-2 py-8 text-sm text-muted-foreground"
                >
                    <RefreshCw
                        class="size-4 animate-spin"
                        aria-hidden="true"
                    />Loading delivery details…
                </div>
                <p
                    v-if="recoveryState.error"
                    role="alert"
                    class="rounded-lg border border-destructive/20 bg-destructive/5 p-3 text-sm leading-relaxed text-destructive"
                >
                    {{ recoveryState.error }}
                </p>
                <p
                    v-if="recoveryState.notice"
                    role="status"
                    class="rounded-lg border bg-muted/30 p-3 text-sm leading-relaxed"
                >
                    {{ recoveryState.notice }}
                </p>
                <template v-if="recoveryState.detail && !recoveryState.loading">
                    <div
                        class="flex flex-wrap items-center justify-between gap-2"
                    >
                        <h3 class="min-w-0 text-base font-semibold break-words">
                            {{ recoveryState.detail.event.website.name }}
                        </h3>
                        <span
                            class="rounded-full px-2 py-1 text-xs font-medium"
                            :class="badge(recoveryState.detail.event.status)"
                            >{{
                                statusNames[
                                    recoveryState.detail.event.status
                                ] || recoveryState.detail.event.status
                            }}</span
                        >
                    </div>
                    <dl
                        class="grid grid-cols-2 gap-x-4 gap-y-4 rounded-lg border bg-muted/15 p-4 text-xs"
                    >
                        <div>
                            <dt class="text-muted-foreground">Source</dt>
                            <dd class="mt-1 font-medium">
                                {{
                                    sourceNames[
                                        recoveryState.detail.event.source
                                    ] || recoveryState.detail.event.source
                                }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Reference</dt>
                            <dd class="mt-1 font-medium break-words">
                                {{
                                    recoveryState.detail.event.external_id
                                        ? `#${recoveryState.detail.event.external_id}`
                                        : 'Not provided'
                                }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Topic</dt>
                            <dd class="mt-1 font-medium break-words">
                                {{ recoveryState.detail.event.topic }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Signature</dt>
                            <dd
                                class="mt-1 flex items-center gap-1 font-medium"
                            >
                                <ShieldCheck
                                    v-if="
                                        recoveryState.detail.event
                                            .signature_valid
                                    "
                                    class="size-3.5 text-emerald-600"
                                    aria-hidden="true"
                                />{{
                                    recoveryState.detail.event.signature_valid
                                        ? 'Verified'
                                        : 'Not verified'
                                }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">
                                Received · {{ health.timezone }}
                            </dt>
                            <dd class="mt-1 leading-relaxed">
                                {{
                                    date(recoveryState.detail.event.received_at)
                                }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">
                                Processed · {{ health.timezone }}
                            </dt>
                            <dd class="mt-1 leading-relaxed">
                                {{
                                    recoveryState.detail.event.processed_at
                                        ? date(
                                              recoveryState.detail.event
                                                  .processed_at,
                                          )
                                        : 'Not processed'
                                }}
                            </dd>
                        </div>
                    </dl>
                    <div
                        v-if="recoveryState.detail.event.failure_summary"
                        class="rounded-lg border border-rose-500/20 bg-rose-500/5 p-4"
                    >
                        <h3 class="text-xs font-semibold">What happened</h3>
                        <p
                            class="mt-2 text-sm leading-relaxed text-muted-foreground"
                        >
                            {{ recoveryState.detail.event.failure_summary }}
                        </p>
                    </div>
                    <section aria-labelledby="retry-history-title">
                        <div
                            class="mb-3 flex items-center justify-between gap-2"
                        >
                            <h3
                                id="retry-history-title"
                                class="text-sm font-semibold"
                            >
                                Retry history
                            </h3>
                            <span class="text-[10px] text-muted-foreground"
                                >Latest 20 attempts</span
                            >
                        </div>
                        <p
                            v-if="!recoveryState.detail.attempts.length"
                            class="rounded-lg border border-dashed p-4 text-xs text-muted-foreground"
                        >
                            No manual retries recorded for this delivery.
                        </p>
                        <ol v-else class="divide-y rounded-lg border">
                            <li
                                v-for="attempt in recoveryState.detail.attempts"
                                :key="attempt.id"
                                class="p-3"
                            >
                                <div
                                    class="flex flex-wrap items-center justify-between gap-2"
                                >
                                    <span
                                        class="rounded-full px-2 py-0.5 text-[10px] font-medium"
                                        :class="badge(attempt.status)"
                                        >{{
                                            statusNames[attempt.status] ||
                                            attempt.status
                                        }}</span
                                    ><time
                                        class="text-[10px] text-muted-foreground"
                                        >{{ date(attempt.requested_at) }}</time
                                    >
                                </div>
                                <p class="mt-2 text-xs leading-relaxed">
                                    {{
                                        attempt.result_message ||
                                        (attempt.status === 'queued'
                                            ? 'Waiting for processing. Refresh to check the latest result.'
                                            : 'Processing this delivery.')
                                    }}
                                </p>
                                <p
                                    class="mt-1.5 text-[10px] text-muted-foreground"
                                >
                                    Requested by
                                    {{
                                        attempt.requested_by ||
                                        'Former team member'
                                    }}<span v-if="attempt.finished_at">
                                        · Finished
                                        {{ date(attempt.finished_at) }}</span
                                    >
                                </p>
                            </li>
                        </ol>
                    </section>
                    <p
                        v-if="!recoveryState.detail.event.can_retry"
                        class="text-xs leading-relaxed text-muted-foreground"
                    >
                        {{
                            recoveryState.detail.event.retry_reason ||
                            'This delivery is not eligible for retry.'
                        }}
                    </p>
                    <div
                        v-if="confirming"
                        class="rounded-lg border border-amber-500/30 bg-amber-500/5 p-4"
                    >
                        <h3 class="text-sm font-semibold">
                            Retry this delivery?
                        </h3>
                        <p
                            class="mt-2 text-xs leading-relaxed text-muted-foreground"
                        >
                            This will reprocess one stored delivery for
                            {{ recoveryState.detail.event.website.name }}. It
                            may recover a missing order or submission. Check the
                            result here after requesting the retry.
                        </p>
                        <div class="mt-4 flex flex-wrap gap-2">
                            <Button
                                size="sm"
                                :disabled="recoveryState.retrying"
                                @click="confirmRetry"
                                >Confirm retry</Button
                            ><Button
                                variant="outline"
                                size="sm"
                                @click="confirming = false"
                                >Cancel</Button
                            >
                        </div>
                    </div>
                </template>
                <div class="flex flex-wrap justify-end gap-2 border-t pt-4">
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="
                            recoveryState.loading || recoveryState.retrying
                        "
                        @click="refreshDetail"
                        ><RefreshCw
                            class="size-3.5"
                            aria-hidden="true"
                        />Refresh details</Button
                    ><Button
                        v-if="
                            recoveryState.detail?.event.can_retry && !confirming
                        "
                        size="sm"
                        :disabled="
                            recoveryState.loading || recoveryState.retrying
                        "
                        @click="confirming = true"
                        ><RotateCcw
                            class="size-3.5"
                            :class="{ 'animate-spin': recoveryState.retrying }"
                            aria-hidden="true"
                        />{{
                            recoveryState.retrying
                                ? 'Requesting retry…'
                                : 'Retry delivery'
                        }}</Button
                    >
                </div>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>

<style scoped>
@reference "../../../css/app.css";
.health-shell {
    background: linear-gradient(
        180deg,
        color-mix(in oklab, var(--muted) 35%, transparent),
        transparent 400px
    );
}
.health-panel {
    @apply rounded-xl border bg-card shadow-sm;
}
.health-metric {
    @apply min-w-0 rounded-xl border bg-card p-4 shadow-sm sm:p-5;
}
.health-label {
    @apply flex min-w-0 flex-col gap-2 text-xs font-medium;
}
.health-select {
    @apply h-10 min-w-0 rounded-lg border bg-background px-3 text-sm font-normal outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-50;
}
</style>
