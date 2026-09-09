<script setup lang="ts">
import DashboardTrend from '@/components/charts/DashboardTrend.vue';
import OrderStatusControl from '@/components/OrderStatusControl.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useDashboardLive } from '@/composables/useDashboardLive';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    dashboardChange,
    dashboardDay,
    dashboardMoney,
    dashboardOrderUrl,
    dashboardStatus,
} from '@/lib/dashboard';
import type { BreadcrumbItem } from '@/types';
import type { DashboardProps, DashboardRevenue } from '@/types/dashboard';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    Activity,
    ArrowDownRight,
    ArrowRight,
    ArrowUpRight,
    CalendarDays,
    Check,
    ChevronRight,
    CircleAlert,
    Clock3,
    FileText,
    Globe2,
    RefreshCw,
    ShoppingBag,
    Users,
    Wallet,
} from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const props = defineProps<DashboardProps>();
const page = usePage();
const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
];
const selectedPeriod = ref(props.filters.period);
const selectedWebsite = ref(
    props.filters.website_id === null ? '' : String(props.filters.website_id),
);
const selectedCurrency = ref(
    props.revenue.find((row) => row.currency === 'USD')?.currency ??
        props.revenue[0]?.currency ??
        '',
);
const filtering = ref(false);
const filterError = ref('');
const chartMode = ref<'activity' | 'revenue'>('activity');
const periods = [
    { value: 'today', label: 'Today' },
    { value: '7d', label: '7 days' },
    { value: '30d', label: '30 days' },
    { value: 'month', label: 'This month' },
] as const;
const {
    connectionState,
    refreshState,
    isRefreshing,
    refresh,
    refreshAfterMutation,
} = useDashboardLive({
    getUserId: () => page.props.auth?.user?.id,
    getWebsiteId: () => props.filters.website_id,
});
watch(
    () => [props.filters.period, props.filters.website_id] as const,
    ([period, website]) => {
        selectedPeriod.value = period;
        selectedWebsite.value = website === null ? '' : String(website);
    },
);
watch(
    () => props.revenue,
    (revenue) => {
        if (!revenue.some((row) => row.currency === selectedCurrency.value))
            selectedCurrency.value =
                revenue.find((row) => row.currency === 'USD')?.currency ??
                revenue[0]?.currency ??
                '';
    },
);
function applyFilters(period = selectedPeriod.value) {
    if (filtering.value) return;
    selectedPeriod.value = period;
    filtering.value = true;
    filterError.value = '';
    router.get(
        '/dashboard',
        {
            period,
            ...(selectedWebsite.value
                ? { website_id: Number(selectedWebsite.value) }
                : {}),
        },
        {
            preserveState: true,
            preserveScroll: true,
            onError: () => {
                filterError.value =
                    'The dashboard could not be updated. Please try again.';
            },
            onFinish: () => {
                filtering.value = false;
                selectedPeriod.value = props.filters.period;
                selectedWebsite.value =
                    props.filters.website_id === null
                        ? ''
                        : String(props.filters.website_id);
            },
        },
    );
}
const scopeName = computed(
    () =>
        props.websites.find(
            (website) => website.id === props.filters.website_id,
        )?.name ?? 'All websites',
);
const activeSites = computed(
    () =>
        props.websites.filter((website) => website.status === 'active').length,
);
const revenueMetric = computed<DashboardRevenue>(
    () =>
        props.revenue.find(
            (row) => row.currency === selectedCurrency.value,
        ) ?? {
            currency: '',
            current: 0,
            previous: 0,
            change: 0,
            change_percent: null,
            completed_orders: 0,
            previous_completed_orders: 0,
            average_order_value: 0,
            previous_average_order_value: 0,
        },
);
const completionRate = computed(() =>
    props.summary.orders.current
        ? Math.round(
              ((props.statusBreakdown.find((row) => row.status === 'completed')
                  ?.count ?? 0) /
                  props.summary.orders.current) *
                  100,
          )
        : 0,
);
const comparisonRange = computed(
    () =>
        `${dashboardDay(props.period.previous_start_date)} – ${dashboardDay(props.period.previous_end_date)}`,
);
const selectedRange = computed(
    () =>
        `${dashboardDay(props.period.start_date)} – ${dashboardDay(props.period.end_date)}`,
);
const submissionsUrl = computed(
    () =>
        `/submissions${props.filters.website_id ? `?website_id=${props.filters.website_id}` : ''}`,
);
function reportUrl(path: string) {
    const params = new URLSearchParams({
        start_date: props.period.start_date.slice(0, 10),
        end_date: props.period.end_date.slice(0, 10),
    });
    if (props.filters.website_id)
        params.set('website_ids[0]', String(props.filters.website_id));
    return `${path}?${params}`;
}
const metrics = computed(() => [
    {
        key: 'orders',
        title: 'Orders',
        metric: props.summary.orders,
        icon: ShoppingBag,
        color: 'indigo',
        description: `${completionRate.value}% completed`,
        href: dashboardOrderUrl(props.filters.website_id),
    },
    {
        key: 'submissions',
        title: 'Form submissions',
        metric: props.summary.submissions,
        icon: FileText,
        color: 'teal',
        description: 'Entries received in this period',
        href: submissionsUrl.value,
    },
    {
        key: 'customers',
        title: 'Customers',
        metric: props.summary.customers,
        icon: Users,
        color: 'violet',
        description: 'Unique customer emails on orders',
        href: reportUrl('/customers'),
    },
]);
const websiteRows = computed(() =>
    [...props.websitePerformance]
        .sort((a, b) => b.orders - a.orders || a.name.localeCompare(b.name))
        .map((website) => ({
            ...website,
            health: props.websiteHealth.find((row) => row.id === website.id),
        })),
);
const busiestSiteOrders = computed(() =>
    Math.max(1, ...props.websitePerformance.map((website) => website.orders)),
);
const hasChartData = computed(() =>
    props.trend.some((day) =>
        chartMode.value === 'activity'
            ? day.orders > 0 || day.submissions > 0
            : (day.revenue[selectedCurrency.value] ?? 0) !== 0,
    ),
);
const liveLabel = computed(() =>
    refreshState.value.hasError
        ? 'Refresh needed'
        : isRefreshing.value
          ? 'Updating…'
          : connectionState.value === 'connected'
            ? 'Live order updates'
            : connectionState.value === 'connecting'
              ? 'Connecting…'
              : connectionState.value === 'reconnecting'
                ? 'Reconnecting…'
                : 'Live updates unavailable',
);
function formatDate(value: string | null, compact = false): string {
    if (!value) return 'No activity yet';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '—';
    return new Intl.DateTimeFormat('en-US', {
        ...(compact ? {} : { month: 'short', day: 'numeric' }),
        hour: '2-digit',
        minute: '2-digit',
        timeZone: props.period.timezone,
    }).format(date);
}
function statusDot(status: string): string {
    return (
        (
            {
                completed: 'bg-emerald-500',
                processing: 'bg-indigo-500',
                pending: 'bg-amber-400',
                'on-hold': 'bg-orange-400',
                failed: 'bg-rose-500',
                cancelled: 'bg-zinc-400',
                refunded: 'bg-violet-400',
            } as Record<string, string>
        )[status] ?? 'bg-slate-400'
    );
}
</script>

<template>
    <Head title="Dashboard" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <main
            class="dashboard-shell mx-auto flex w-full max-w-[1600px] min-w-0 flex-col gap-6 p-4 pb-10 sm:p-6 lg:p-8"
            :aria-busy="filtering"
        >
            <header class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div
                        class="mb-2 flex items-center gap-2 text-[11px] font-semibold tracking-[0.16em] text-muted-foreground uppercase"
                    >
                        <Globe2 class="size-3.5" aria-hidden="true" />Workspace
                        overview
                    </div>
                    <h1
                        class="text-3xl font-semibold tracking-tight sm:text-[32px]"
                    >
                        Dashboard
                    </h1>
                    <p class="mt-2 text-sm text-muted-foreground">
                        A clear view of your reservations, customers, and
                        website activity.
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="filtering || isRefreshing"
                        @click="refresh()"
                        ><RefreshCw
                            class="size-3.5"
                            :class="{ 'animate-spin': isRefreshing }"
                            aria-hidden="true"
                        />Refresh</Button
                    ><Button as-child size="sm"
                        ><Link :href="reportUrl('/analytics')"
                            >Open analytics<ArrowUpRight
                                class="size-3.5"
                                aria-hidden="true" /></Link
                    ></Button>
                </div>
            </header>
            <section
                aria-label="Dashboard filters"
                class="flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-card p-3 shadow-sm"
            >
                <div class="flex w-full flex-wrap items-center gap-3 sm:w-auto">
                    <label class="sr-only" for="dashboard-website"
                        >Website</label
                    ><select
                        id="dashboard-website"
                        v-model="selectedWebsite"
                        class="dashboard-select w-full sm:w-52"
                        :disabled="filtering"
                        @change="applyFilters()"
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
                    <div
                        class="grid w-full grid-cols-4 gap-1 rounded-lg bg-muted/60 p-1 sm:flex sm:w-auto"
                        aria-label="Reporting period"
                    >
                        <button
                            v-for="item in periods"
                            :key="item.value"
                            type="button"
                            class="rounded-md px-3 py-1.5 text-xs font-medium whitespace-nowrap transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring disabled:opacity-50 sm:text-sm"
                            :class="
                                selectedPeriod === item.value
                                    ? 'bg-background text-foreground shadow-sm'
                                    : 'text-muted-foreground hover:text-foreground'
                            "
                            :aria-pressed="selectedPeriod === item.value"
                            :disabled="filtering"
                            @click="applyFilters(item.value)"
                        >
                            {{ item.label }}
                        </button>
                    </div>
                </div>
                <div
                    class="flex items-center gap-2 px-1 text-xs text-muted-foreground"
                >
                    <CalendarDays class="size-3.5" aria-hidden="true" /><span>{{
                        selectedRange
                    }}</span
                    ><span class="text-border">/</span
                    ><span>{{ period.timezone }}</span>
                </div>
            </section>
            <p v-if="filterError" role="alert" class="text-sm text-destructive">
                {{ filterError }}
            </p>
            <div
                class="-mb-3 flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground"
            >
                <p>
                    <span class="font-medium text-foreground">{{
                        period.label
                    }}</span
                    ><span class="px-1">·</span>{{ scopeName }}
                </p>
                <p>
                    Compared with {{ comparisonRange
                    }}<span class="hidden sm:inline">
                        · same-length period</span
                    >
                </p>
            </div>
            <section
                aria-label="Performance totals"
                class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4"
            >
                <article
                    class="dashboard-metric relative overflow-hidden border-emerald-500/20"
                >
                    <div class="flex items-center justify-between gap-2">
                        <h2 class="text-sm font-medium text-muted-foreground">
                            Completed revenue
                        </h2>
                        <span
                            class="rounded-lg bg-emerald-500/10 p-2 text-emerald-600 dark:text-emerald-400"
                            ><Wallet class="size-4" aria-hidden="true"
                        /></span>
                    </div>
                    <div class="mt-3 flex flex-wrap items-baseline gap-2">
                        <strong
                            class="text-[30px] font-semibold tracking-tight tabular-nums"
                            >{{
                                revenueMetric.currency
                                    ? dashboardMoney(
                                          revenueMetric.current,
                                          revenueMetric.currency,
                                      )
                                    : '—'
                            }}</strong
                        ><span class="text-xs text-muted-foreground">{{
                            revenueMetric.currency || 'No completed orders'
                        }}</span>
                    </div>
                    <div class="mt-2 flex items-center gap-1.5 text-xs">
                        <span
                            :class="
                                revenueMetric.change > 0
                                    ? 'text-emerald-600 dark:text-emerald-400'
                                    : revenueMetric.change < 0
                                      ? 'text-rose-600 dark:text-rose-400'
                                      : 'text-muted-foreground'
                            "
                            >{{ dashboardChange(revenueMetric) }}</span
                        ><span class="text-muted-foreground"
                            >vs previous period</span
                        >
                    </div>
                    <div
                        class="mt-5 flex min-h-8 flex-wrap items-center justify-between gap-2 border-t pt-3 text-xs text-muted-foreground"
                    >
                        <span
                            >{{
                                revenueMetric.completed_orders.toLocaleString()
                            }}
                            completed orders</span
                        ><span
                            v-if="revenueMetric.currency"
                            :title="
                                'Average value of completed orders in ' +
                                revenueMetric.currency
                            "
                            >Avg.
                            {{
                                dashboardMoney(
                                    revenueMetric.average_order_value,
                                    revenueMetric.currency,
                                )
                            }}</span
                        >
                    </div>
                    <div
                        v-if="revenue.length > 1"
                        class="mt-2 flex items-center gap-2"
                    >
                        <label
                            for="dashboard-currency"
                            class="text-xs text-muted-foreground"
                            >Revenue currency</label
                        ><select
                            id="dashboard-currency"
                            v-model="selectedCurrency"
                            class="rounded border bg-background px-2 py-1 text-xs"
                        >
                            <option
                                v-for="row in revenue"
                                :key="row.currency"
                                :value="row.currency"
                            >
                                {{ row.currency }}
                            </option>
                        </select>
                    </div>
                </article>
                <article
                    v-for="metric in metrics"
                    :key="metric.key"
                    class="dashboard-metric"
                >
                    <div class="flex items-center justify-between gap-2">
                        <h2 class="text-sm font-medium text-muted-foreground">
                            {{ metric.title }}
                        </h2>
                        <span
                            class="rounded-lg p-2"
                            :class="
                                metric.color === 'indigo'
                                    ? 'bg-indigo-500/10 text-indigo-600 dark:text-indigo-400'
                                    : metric.color === 'teal'
                                      ? 'bg-teal-500/10 text-teal-600 dark:text-teal-400'
                                      : 'bg-violet-500/10 text-violet-600 dark:text-violet-400'
                            "
                            ><component
                                :is="metric.icon"
                                class="size-4"
                                aria-hidden="true"
                        /></span>
                    </div>
                    <strong
                        class="mt-3 block text-[30px] font-semibold tracking-tight tabular-nums"
                        >{{ metric.metric.current.toLocaleString() }}</strong
                    >
                    <div
                        class="mt-2 flex items-center gap-1 text-xs"
                        :class="
                            metric.metric.change > 0
                                ? 'text-emerald-600 dark:text-emerald-400'
                                : metric.metric.change < 0
                                  ? 'text-rose-600 dark:text-rose-400'
                                  : 'text-muted-foreground'
                        "
                    >
                        <ArrowUpRight
                            v-if="metric.metric.change > 0"
                            class="size-3.5"
                            aria-hidden="true"
                        /><ArrowDownRight
                            v-else-if="metric.metric.change < 0"
                            class="size-3.5"
                            aria-hidden="true"
                        /><span>{{ dashboardChange(metric.metric) }}</span
                        ><span class="ml-0.5 text-muted-foreground"
                            >vs
                            {{ metric.metric.previous.toLocaleString() }}
                            previously</span
                        >
                    </div>
                    <Link
                        :href="metric.href"
                        class="mt-5 flex min-h-8 items-center justify-between gap-2 border-t pt-3 text-xs text-muted-foreground hover:text-foreground"
                        ><span>{{ metric.description }}</span
                        ><ArrowRight
                            class="size-3.5 shrink-0"
                            aria-hidden="true"
                    /></Link>
                </article>
            </section>
            <section
                aria-labelledby="attention-title"
                class="grid gap-4 rounded-xl border bg-card p-4 lg:grid-cols-[1fr_2fr] lg:items-center lg:px-5"
            >
                <div class="flex items-center gap-3">
                    <span
                        class="rounded-full p-2.5"
                        :class="
                            operations.open_orders ||
                            operations.failed_webhooks_24h
                                ? 'bg-amber-500/10 text-amber-600 dark:text-amber-400'
                                : 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
                        "
                        ><CircleAlert
                            v-if="
                                operations.open_orders ||
                                operations.failed_webhooks_24h
                            "
                            class="size-5"
                            aria-hidden="true" /><Check
                            v-else
                            class="size-5"
                            aria-hidden="true"
                    /></span>
                    <div>
                        <h2 id="attention-title" class="text-sm font-semibold">
                            {{
                                operations.open_orders
                                    ? 'Orders to follow up'
                                    : 'No open orders'
                            }}
                        </h2>
                        <p class="mt-0.5 text-xs text-muted-foreground">
                            Current backlog · {{ scopeName }} · all dates
                        </p>
                        <Link
                            :href="
                                filters.website_id
                                    ? `/order-work-queue?website_id=${filters.website_id}`
                                    : '/order-work-queue'
                            "
                            class="mt-2 inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline"
                        >
                            Open work queue
                            <ChevronRight class="size-3" aria-hidden="true" />
                        </Link>
                    </div>
                </div>
                <div class="grid grid-cols-3 divide-x">
                    <Link
                        v-for="item in [
                            {
                                label: 'Pending payment',
                                status: 'pending',
                                count: operations.pending,
                            },
                            {
                                label: 'On hold',
                                status: 'on-hold',
                                count: operations.on_hold,
                            },
                            {
                                label: 'Processing',
                                status: 'processing',
                                count: operations.processing,
                            },
                        ]"
                        :key="item.status"
                        :href="
                            dashboardOrderUrl(filters.website_id, item.status)
                        "
                        class="group px-3 text-center lg:px-5 lg:text-left"
                        ><span
                            class="block text-xl font-semibold tabular-nums"
                            >{{ item.count.toLocaleString() }}</span
                        ><span
                            class="mt-0.5 inline-flex items-center gap-1 text-[11px] text-muted-foreground group-hover:text-foreground sm:text-xs"
                            >{{ item.label
                            }}<ChevronRight
                                class="hidden size-3 sm:block"
                                aria-hidden="true" /></span
                    ></Link>
                </div>
            </section>
            <div
                class="grid min-w-0 gap-5 xl:grid-cols-[minmax(0,2fr)_minmax(280px,1fr)]"
            >
                <section
                    class="dashboard-panel min-w-0"
                    aria-labelledby="activity-title"
                >
                    <div
                        class="flex flex-wrap items-start justify-between gap-3 px-5 pt-5"
                    >
                        <div>
                            <h2 id="activity-title" class="font-semibold">
                                {{
                                    chartMode === 'activity'
                                        ? 'Reservation activity'
                                        : 'Completed revenue'
                                }}
                            </h2>
                            <p class="mt-1 text-xs text-muted-foreground">
                                {{
                                    chartMode === 'activity'
                                        ? 'Daily orders and form submissions'
                                        : `Daily completed orders · ${selectedCurrency || 'no revenue'}`
                                }}
                            </p>
                        </div>
                        <div class="flex rounded-lg bg-muted/60 p-1">
                            <button
                                v-for="mode in ['activity', 'revenue'] as const"
                                :key="mode"
                                type="button"
                                :aria-pressed="chartMode === mode"
                                class="rounded-md px-3 py-1 text-xs font-medium capitalize"
                                :class="
                                    chartMode === mode
                                        ? 'bg-background shadow-sm'
                                        : 'text-muted-foreground'
                                "
                                @click="chartMode = mode"
                            >
                                {{ mode }}
                            </button>
                        </div>
                    </div>
                    <div class="px-4 pt-4 sm:px-5">
                        <div
                            v-if="chartMode === 'activity'"
                            class="mb-4 flex gap-5 text-xs text-muted-foreground"
                        >
                            <span class="inline-flex items-center gap-2"
                                ><span
                                    class="size-2 rounded-full bg-indigo-500"
                                />Orders</span
                            ><span class="inline-flex items-center gap-2"
                                ><span
                                    class="size-2 rounded-full bg-teal-500"
                                />Submissions</span
                            >
                        </div>
                        <div v-else class="mb-4 text-xs text-muted-foreground">
                            {{
                                selectedCurrency
                                    ? `Revenue shown in ${selectedCurrency}. Currencies are kept separate.`
                                    : 'Revenue appears when orders are completed.'
                            }}
                        </div>
                        <DashboardTrend
                            v-if="hasChartData"
                            :data="trend"
                            :mode="chartMode"
                            :currency="selectedCurrency"
                        />
                        <div
                            v-else
                            class="flex h-[260px] flex-col items-center justify-center gap-2 text-center sm:h-[290px]"
                        >
                            <Activity
                                class="mb-1 size-7 text-muted-foreground/50"
                                aria-hidden="true"
                            />
                            <p class="text-sm font-medium">
                                {{
                                    chartMode === 'activity'
                                        ? 'No activity in this period'
                                        : 'No completed revenue in this period'
                                }}
                            </p>
                            <p
                                class="max-w-xs text-xs leading-relaxed text-muted-foreground"
                            >
                                Try another period or website to explore earlier
                                activity.
                            </p>
                        </div>
                    </div>
                    <details class="mt-3 border-t px-5 py-3">
                        <summary
                            class="cursor-pointer text-xs font-medium text-muted-foreground hover:text-foreground"
                        >
                            View daily values
                        </summary>
                        <div class="mt-3 max-h-60 overflow-auto">
                            <table class="w-full text-left text-xs">
                                <caption class="sr-only">
                                    Daily dashboard values for
                                    {{
                                        selectedRange
                                    }}
                                </caption>
                                <thead
                                    class="sticky top-0 bg-card text-muted-foreground"
                                >
                                    <tr>
                                        <th
                                            class="py-2 font-medium"
                                            scope="col"
                                        >
                                            Date
                                        </th>
                                        <th
                                            class="py-2 text-right font-medium"
                                            scope="col"
                                        >
                                            Orders
                                        </th>
                                        <th
                                            class="py-2 text-right font-medium"
                                            scope="col"
                                        >
                                            Submissions
                                        </th>
                                        <th
                                            v-if="selectedCurrency"
                                            class="py-2 text-right font-medium"
                                            scope="col"
                                        >
                                            {{ selectedCurrency }}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="day in trend"
                                        :key="day.date"
                                        class="border-t"
                                    >
                                        <td class="py-2">
                                            {{ dashboardDay(day.date) }}
                                        </td>
                                        <td
                                            class="py-2 text-right tabular-nums"
                                        >
                                            {{ day.orders }}
                                        </td>
                                        <td
                                            class="py-2 text-right tabular-nums"
                                        >
                                            {{ day.submissions }}
                                        </td>
                                        <td
                                            v-if="selectedCurrency"
                                            class="py-2 text-right tabular-nums"
                                        >
                                            {{
                                                dashboardMoney(
                                                    day.revenue[
                                                        selectedCurrency
                                                    ] ?? 0,
                                                    selectedCurrency,
                                                )
                                            }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </details>
                </section>
                <section
                    class="dashboard-panel p-5"
                    aria-labelledby="status-title"
                >
                    <div class="flex items-center justify-between">
                        <h2 id="status-title" class="font-semibold">
                            Order breakdown
                        </h2>
                        <ShoppingBag
                            class="size-4 text-muted-foreground"
                            aria-hidden="true"
                        />
                    </div>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{ period.label }} ·
                        {{ summary.orders.current.toLocaleString() }} orders
                    </p>
                    <div class="mt-6 flex items-baseline gap-2">
                        <span
                            class="text-4xl font-semibold tracking-tight tabular-nums"
                            >{{ completionRate
                            }}<span class="text-2xl">%</span></span
                        ><span class="text-xs text-muted-foreground"
                            >completed</span
                        >
                    </div>
                    <div
                        class="mt-3 flex h-2.5 overflow-hidden rounded-full bg-muted"
                        aria-hidden="true"
                    >
                        <span
                            v-for="item in statusBreakdown"
                            :key="item.status"
                            :class="statusDot(item.status)"
                            :style="{
                                width: `${summary.orders.current ? (item.count / summary.orders.current) * 100 : 0}%`,
                            }"
                        />
                    </div>
                    <div class="mt-5 space-y-0.5">
                        <Link
                            v-for="item in statusBreakdown"
                            :key="item.status"
                            :href="
                                dashboardOrderUrl(
                                    filters.website_id,
                                    item.status,
                                )
                            "
                            class="group flex items-center justify-between rounded-lg py-2 text-sm hover:bg-muted/50"
                            ><span class="flex items-center gap-2.5"
                                ><span
                                    class="size-2 rounded-full"
                                    :class="statusDot(item.status)"
                                /><span
                                    class="text-muted-foreground group-hover:text-foreground"
                                    >{{ dashboardStatus(item.status) }}</span
                                ></span
                            ><span class="flex items-center gap-3"
                                ><strong class="font-medium tabular-nums">{{
                                    item.count.toLocaleString()
                                }}</strong
                                ><span
                                    class="w-9 text-right text-xs text-muted-foreground tabular-nums"
                                    >{{
                                        summary.orders.current
                                            ? Math.round(
                                                  (item.count /
                                                      summary.orders.current) *
                                                      100,
                                              )
                                            : 0
                                    }}%</span
                                ></span
                            ></Link
                        >
                        <p
                            v-if="!statusBreakdown.length"
                            class="py-7 text-sm text-muted-foreground"
                        >
                            No orders in the selected period.
                        </p>
                    </div>
                    <p
                        class="mt-4 border-t pt-3 text-[11px] leading-relaxed text-muted-foreground"
                    >
                        Status links open matching orders across all dates.
                    </p>
                </section>
            </div>
            <section
                class="dashboard-panel min-w-0"
                aria-labelledby="websites-title"
            >
                <div
                    class="flex flex-wrap items-center justify-between gap-3 p-5"
                >
                    <div>
                        <h2 id="websites-title" class="font-semibold">
                            Website performance
                        </h2>
                        <p class="mt-1 text-xs text-muted-foreground">
                            {{ period.label }} · {{ activeSites }} of
                            {{ websites.length }} connected websites active
                        </p>
                    </div>
                    <Link
                        href="/websites"
                        class="inline-flex items-center gap-1 text-xs font-medium text-muted-foreground hover:text-foreground"
                        >Manage websites<ArrowUpRight
                            class="size-3.5"
                            aria-hidden="true"
                    /></Link>
                </div>
                <div
                    class="hidden grid-cols-[minmax(180px,1.4fr)_minmax(90px,0.8fr)_minmax(130px,1fr)_minmax(90px,0.7fr)_minmax(140px,1fr)] gap-5 border-y bg-muted/30 px-5 py-2.5 text-[11px] font-medium text-muted-foreground xl:grid"
                >
                    <span>Website</span><span>Orders</span
                    ><span>Completed revenue</span><span>Submissions</span
                    ><span>Last webhook · all dates</span>
                </div>
                <div class="divide-y">
                    <article
                        v-for="website in websiteRows"
                        :key="website.id"
                        class="grid min-w-0 gap-4 p-5 xl:grid-cols-[minmax(180px,1.4fr)_minmax(90px,0.8fr)_minmax(130px,1fr)_minmax(90px,0.7fr)_minmax(140px,1fr)] xl:items-center xl:gap-5"
                    >
                        <div class="flex min-w-0 items-center gap-3">
                            <span
                                class="flex size-9 shrink-0 items-center justify-center rounded-lg border bg-muted/40 text-xs font-semibold uppercase"
                                >{{ website.name.slice(0, 2) }}</span
                            >
                            <div class="min-w-0">
                                <Link
                                    :href="`/websites/${website.id}`"
                                    class="block truncate text-sm font-semibold hover:underline"
                                    >{{ website.name }}</Link
                                ><span
                                    class="mt-1 flex items-center gap-1.5 text-[11px] text-muted-foreground"
                                    ><span
                                        class="size-1.5 rounded-full"
                                        :class="
                                            website.status === 'active'
                                                ? 'bg-emerald-500'
                                                : 'bg-zinc-400'
                                        "
                                    />{{ dashboardStatus(website.status)
                                    }}<span
                                        v-if="website.health?.open_orders"
                                        class="ml-1"
                                        >·
                                        {{ website.health.open_orders }}
                                        open</span
                                    ></span
                                >
                            </div>
                        </div>
                        <div class="grid grid-cols-3 gap-4 xl:contents">
                            <div>
                                <span class="dashboard-mobile-label"
                                    >Orders</span
                                ><Link
                                    :href="dashboardOrderUrl(website.id)"
                                    class="text-sm font-semibold tabular-nums hover:underline"
                                    >{{ website.orders.toLocaleString() }}</Link
                                >
                                <div
                                    class="mt-2 h-1 max-w-24 overflow-hidden rounded-full bg-muted"
                                    aria-hidden="true"
                                >
                                    <div
                                        class="h-full rounded-full bg-indigo-400"
                                        :style="{
                                            width: `${(website.orders / busiestSiteOrders) * 100}%`,
                                        }"
                                    />
                                </div>
                            </div>
                            <div>
                                <span class="dashboard-mobile-label"
                                    >Revenue</span
                                >
                                <p
                                    v-for="row in website.revenue"
                                    :key="row.currency"
                                    class="text-sm font-medium tabular-nums"
                                >
                                    {{
                                        dashboardMoney(row.total, row.currency)
                                    }}
                                    <span
                                        class="text-[10px] font-normal text-muted-foreground"
                                        >{{ row.currency }}</span
                                    >
                                </p>
                                <p
                                    v-if="!website.revenue.length"
                                    class="text-sm text-muted-foreground"
                                >
                                    —
                                </p>
                            </div>
                            <div>
                                <span class="dashboard-mobile-label"
                                    >Submissions</span
                                ><Link
                                    :href="`/submissions?website_id=${website.id}`"
                                    class="text-sm font-medium tabular-nums hover:underline"
                                    >{{
                                        website.submissions.toLocaleString()
                                    }}</Link
                                >
                            </div>
                        </div>
                        <div
                            class="flex flex-wrap items-center justify-between gap-2 border-t pt-3 xl:block xl:border-0 xl:pt-0"
                        >
                            <span class="text-xs text-muted-foreground">{{
                                formatDate(
                                    website.health?.last_webhook_at ?? null,
                                )
                            }}</span>
                            <p
                                class="text-[11px] text-muted-foreground xl:mt-1"
                            >
                                <span v-if="website.health?.queued_webhooks"
                                    >{{ website.health.queued_webhooks }} queued
                                    · </span
                                >{{ website.health?.failed_webhooks ?? 0 }}
                                historical failures
                            </p>
                        </div>
                    </article>
                </div>
                <div v-if="!websiteRows.length" class="px-5 py-12 text-center">
                    <Globe2
                        class="mx-auto mb-3 size-7 text-muted-foreground/50"
                        aria-hidden="true"
                    />
                    <p class="text-sm font-medium">
                        Connect your first website
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        Your orders, forms, and activity will appear here.
                    </p>
                    <Button as-child variant="outline" size="sm" class="mt-4"
                        ><Link href="/websites/create"
                            >Add website<ArrowRight
                                class="size-3.5"
                                aria-hidden="true" /></Link
                    ></Button>
                </div>
            </section>
            <div
                class="grid min-w-0 gap-5 xl:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)]"
            >
                <section
                    class="dashboard-panel min-w-0"
                    aria-labelledby="recent-orders-title"
                >
                    <div class="flex items-center justify-between gap-3 p-5">
                        <div>
                            <h2 id="recent-orders-title" class="font-semibold">
                                Recent orders
                            </h2>
                            <p class="mt-1 text-xs text-muted-foreground">
                                Latest in the selected period · Click a status
                                to update
                            </p>
                        </div>
                        <Link
                            :href="dashboardOrderUrl(filters.website_id)"
                            class="inline-flex shrink-0 items-center gap-1 text-xs font-medium text-muted-foreground hover:text-foreground"
                            >View all<ArrowRight
                                class="size-3.5"
                                aria-hidden="true"
                        /></Link>
                    </div>
                    <div class="divide-y border-t">
                        <article
                            v-for="order in recentOrders"
                            :key="order.id"
                            class="group flex min-w-0 items-start justify-between gap-3 px-5 py-4 transition hover:bg-muted/30"
                        >
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
                                        :website-name="order.website_name"
                                        :disabled="
                                            filtering ||
                                            !order.can_update_status
                                        "
                                        @settled="refreshAfterMutation"
                                    />
                                </div>
                                <p
                                    class="mt-1.5 truncate text-xs text-muted-foreground"
                                >
                                    {{
                                        order.customer_name ||
                                        order.customer_email ||
                                        'Guest customer'
                                    }}
                                </p>
                                <p
                                    class="mt-1 truncate text-[11px] text-muted-foreground/80"
                                >
                                    {{ order.website_name }}
                                </p>
                            </div>
                            <div class="shrink-0 text-right">
                                <p class="text-sm font-semibold tabular-nums">
                                    {{
                                        dashboardMoney(
                                            order.total,
                                            order.currency,
                                        )
                                    }}
                                </p>
                                <p
                                    class="mt-1 text-[10px] text-muted-foreground"
                                >
                                    {{ order.currency }}
                                </p>
                                <p
                                    class="mt-2 text-[10px] text-muted-foreground"
                                >
                                    {{ formatDate(order.created_at_wp) }}
                                </p>
                            </div>
                        </article>
                    </div>
                    <div
                        v-if="!recentOrders.length"
                        class="px-5 py-12 text-center text-sm text-muted-foreground"
                    >
                        No orders in this period.
                    </div>
                </section>
                <section
                    class="dashboard-panel min-w-0"
                    aria-labelledby="recent-entries-title"
                >
                    <div class="flex items-center justify-between gap-3 p-5">
                        <div>
                            <h2 id="recent-entries-title" class="font-semibold">
                                Recent submissions
                            </h2>
                            <p class="mt-1 text-xs text-muted-foreground">
                                Latest form entries in this period
                            </p>
                        </div>
                        <Link
                            :href="submissionsUrl"
                            class="inline-flex shrink-0 items-center gap-1 text-xs font-medium text-muted-foreground hover:text-foreground"
                            >View all<ArrowRight
                                class="size-3.5"
                                aria-hidden="true"
                        /></Link>
                    </div>
                    <div class="divide-y border-t">
                        <Link
                            v-for="entry in recentSubmissions"
                            :key="entry.id"
                            :href="`/submissions/entries/${entry.id}`"
                            class="group flex min-w-0 items-start gap-3 px-5 py-4 transition hover:bg-muted/30"
                            ><span
                                class="mt-0.5 rounded-lg bg-teal-500/10 p-2 text-teal-600 dark:text-teal-400"
                                ><FileText class="size-3.5" aria-hidden="true"
                            /></span>
                            <div class="min-w-0 flex-1">
                                <div
                                    class="flex flex-wrap items-center justify-between gap-1.5"
                                >
                                    <span
                                        class="text-xs font-semibold group-hover:underline"
                                        >Entry #{{ entry.entry_id }}</span
                                    ><span
                                        class="text-[10px] text-muted-foreground"
                                        >{{
                                            formatDate(entry.created_at_wp)
                                        }}</span
                                    >
                                </div>
                                <p
                                    class="mt-1.5 truncate text-xs"
                                    :title="
                                        entry.form_title ||
                                        `Form #${entry.form_id}`
                                    "
                                >
                                    {{
                                        entry.form_title ||
                                        `Form #${entry.form_id}`
                                    }}
                                </p>
                                <p
                                    class="mt-1 truncate text-[11px] text-muted-foreground"
                                >
                                    {{ entry.website_name
                                    }}<span v-if="entry.email">
                                        · {{ entry.email }}</span
                                    >
                                </p>
                            </div></Link
                        >
                    </div>
                    <div
                        v-if="!recentSubmissions.length"
                        class="px-5 py-12 text-center text-sm text-muted-foreground"
                    >
                        No submissions in this period.
                    </div>
                </section>
            </div>
            <section class="dashboard-panel" aria-labelledby="sync-title">
                <div
                    class="flex flex-wrap items-center justify-between gap-3 p-5"
                >
                    <div>
                        <h2
                            id="sync-title"
                            class="flex items-center gap-2 font-semibold"
                        >
                            <Activity
                                class="size-4 text-muted-foreground"
                                aria-hidden="true"
                            />Website connections
                        </h2>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Webhook delivery activity · {{ scopeName }} · all
                            dates unless noted
                        </p>
                    </div>
                    <Badge
                        variant="outline"
                        :class="
                            operations.failed_webhooks_24h
                                ? 'border-amber-500/30 text-amber-700 dark:text-amber-300'
                                : 'text-muted-foreground'
                        "
                        >{{ operations.failed_webhooks_24h }} failed deliveries
                        received in the last 24 hours</Badge
                    >
                </div>
                <div
                    class="grid gap-5 border-t px-5 py-4 sm:grid-cols-2 xl:grid-cols-4"
                >
                    <div>
                        <p class="text-xs text-muted-foreground">
                            Queued deliveries
                        </p>
                        <p class="mt-1 text-xl font-semibold tabular-nums">
                            {{ operations.queued_webhooks.toLocaleString() }}
                        </p>
                        <p class="mt-1 text-[11px] text-muted-foreground">
                            {{
                                operations.oldest_queued_at
                                    ? `Oldest: ${formatDate(operations.oldest_queued_at)}`
                                    : 'No deliveries waiting'
                            }}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">
                            Processed deliveries
                        </p>
                        <p class="mt-1 text-xl font-semibold tabular-nums">
                            {{ operations.processed_webhooks.toLocaleString() }}
                        </p>
                        <p class="mt-1 text-[11px] text-muted-foreground">
                            {{ operations.failed_webhooks.toLocaleString() }}
                            historical failures recorded
                        </p>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">
                            Last order webhook
                        </p>
                        <p class="mt-2 text-sm font-medium">
                            {{ formatDate(operations.last_woo_received_at) }}
                        </p>
                        <p class="mt-1 text-[11px] text-muted-foreground">
                            WooCommerce
                        </p>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">
                            Last submission webhook
                        </p>
                        <p class="mt-2 text-sm font-medium">
                            {{ formatDate(operations.last_fluent_received_at) }}
                        </p>
                        <p class="mt-1 text-[11px] text-muted-foreground">
                            Fluent Forms
                        </p>
                    </div>
                </div>
            </section>
            <footer
                class="flex flex-wrap items-center justify-between gap-2 text-[11px] text-muted-foreground"
            >
                <div class="flex items-center gap-2" role="status">
                    <span
                        class="size-1.5 rounded-full"
                        :class="
                            connectionState === 'connected' &&
                            !refreshState.hasError
                                ? 'bg-emerald-500'
                                : 'bg-amber-500'
                        "
                    /><span>{{ liveLabel }}</span
                    ><span>·</span
                    ><span class="inline-flex items-center gap-1"
                        ><Clock3 class="size-3" aria-hidden="true" />Updated
                        {{ formatDate(generated_at, true) }}</span
                    >
                </div>
                <p>
                    Revenue uses completed orders. Times shown in
                    {{ period.timezone }}.
                </p>
            </footer>
        </main>
    </AppLayout>
</template>

<style scoped>
@reference "../../css/app.css";
.dashboard-shell {
    background: linear-gradient(
        180deg,
        color-mix(in oklab, var(--muted) 35%, transparent),
        transparent 520px
    );
}
.dashboard-panel {
    @apply rounded-xl border bg-card shadow-sm;
}
.dashboard-metric {
    @apply min-w-0 rounded-xl border bg-card p-5 shadow-sm;
}
.dashboard-select {
    @apply h-9 min-w-0 rounded-lg border bg-background px-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-50;
}
.dashboard-mobile-label {
    @apply mb-1 block text-[10px] text-muted-foreground xl:hidden;
}
</style>
