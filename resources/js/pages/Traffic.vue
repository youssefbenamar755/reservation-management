<script setup lang="ts">
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    createTrafficPoller,
    readTrafficReports,
    trafficBreakdown,
    trafficComparison,
    trafficDaily,
    trafficNeedsRefresh,
    trafficPending,
    trafficRange,
    trafficSearchRows,
    trafficSummary,
} from '@/lib/traffic';
import type { TrafficPage, TrafficReport } from '@/types/traffic';
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import {
    CategoryScale,
    Chart as ChartJS,
    Legend,
    LinearScale,
    LineElement,
    PointElement,
    Tooltip,
} from 'chart.js';
import {
    Activity,
    ArrowUpRight,
    BarChart3,
    CalendarDays,
    CheckCircle2,
    CircleAlert,
    Globe,
    LoaderCircle,
    RefreshCw,
    Search,
    Settings2,
    ShoppingBag,
    Users,
} from 'lucide-vue-next';
import {
    computed,
    onMounted,
    onUnmounted,
    reactive,
    ref,
    shallowRef,
    watch,
} from 'vue';
import { Line } from 'vue-chartjs';

ChartJS.register(
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    Tooltip,
    Legend,
);
const props = defineProps<{ traffic: TrafficPage }>();
const reports = shallowRef<TrafficReport[]>([]);
const draft = reactive({
    website_id: String(props.traffic.filters.website_id ?? ''),
    start_date: props.traffic.filters.start_date,
    end_date: props.traffic.filters.end_date,
});
const filterErrors = ref<Record<string, string>>({});
const filterLoading = ref(false),
    refreshing = ref(false),
    waiting = ref(false);
const errorMessage = ref(''),
    progressMessage = ref('');
const realtime = shallowRef<{
    active_users: number;
    updated_at: string;
} | null>(null);
const realtimeLoading = ref(false),
    realtimeError = ref('');
const chartMetric = ref<'sessions' | 'clicks'>('sessions');
const breakdown = ref<'sources' | 'pages' | 'countries' | 'devices'>('sources');
const searchTable = ref<'queries' | 'pages'>('queries');
let mounted = false,
    disposed = false,
    generation = 0,
    filterGeneration = 0;
let refreshRequest: AbortController | null = null,
    realtimeRequest: AbortController | null = null;
let filterCancel: (() => void) | null = null,
    removeNavigation: (() => void) | null = null;
let lastAutomaticKey = '',
    pollStarted = false,
    checkOnVisible = false;
const key = computed(() =>
    JSON.stringify([
        props.traffic.filters,
        props.traffic.connection,
        props.traffic.reports.map((report) => [
            report.website_id,
            report.ga4_property_id,
            report.gsc_site_url,
        ]),
    ]),
);
const query = () => ({
    ...props.traffic.filters,
    website_id: props.traffic.filters.website_id ?? '',
});
const available = () =>
    mounted &&
    !disposed &&
    !document.hidden &&
    !filterLoading.value &&
    props.traffic.connection.connected;
const summary = computed(() => trafficSummary(reports.value));
const mappedGa4 = computed(
    () => reports.value.filter((report) => report.ga4_property_id).length,
);
const mappedGsc = computed(
    () => reports.value.filter((report) => report.gsc_site_url).length,
);
const anyMapped = computed(() =>
    reports.value.some(
        (report) => report.ga4_property_id || report.gsc_site_url,
    ),
);
const selectedReport = computed(() =>
    reports.value.find(
        (report) => report.website_id === props.traffic.filters.website_id,
    ),
);
const scopeName = computed(
    () =>
        props.traffic.websites.find(
            (site) => site.id === props.traffic.filters.website_id,
        )?.name ?? 'All websites',
);
const reconnectRequired = computed(
    () =>
        !props.traffic.connection.connected &&
        Boolean(
            props.traffic.connection.email ||
            props.traffic.connection.reconnect_required,
        ),
);
const rangeLabel = computed(
    () =>
        `${shortDate(props.traffic.filters.start_date)} – ${shortDate(props.traffic.filters.end_date)}`,
);
const previousDays = computed(
    () =>
        Math.round(
            (Date.parse(props.traffic.filters.end_date) -
                Date.parse(props.traffic.filters.start_date)) /
                86_400_000,
        ) + 1,
);
const rows = computed(() => trafficBreakdown(reports.value, breakdown.value));
const searchRows = computed(() =>
    trafficSearchRows(reports.value, searchTable.value),
);
const series = computed(() => trafficDaily(reports.value, chartMetric.value));
const notes = computed(() => [
    ...new Set(reports.value.flatMap((report) => report.notes ?? [])),
]);
const ga4Timezones = computed(() => [
    ...new Set(
        reports.value.flatMap((report) =>
            report.ga4?.timezone ? [report.ga4.timezone] : [],
        ),
    ),
]);
const chartData = computed(() => ({
    labels: series.value.map((point) => shortDate(point.date, false)),
    datasets: [
        {
            label:
                chartMetric.value === 'sessions'
                    ? 'GA4 sessions'
                    : 'Google Search clicks',
            data: series.value.map((point) => point.value),
            borderColor:
                chartMetric.value === 'sessions' ? '#6366f1' : '#10b981',
            backgroundColor:
                chartMetric.value === 'sessions' ? '#6366f11a' : '#10b9811a',
            tension: 0.25,
            borderWidth: 2,
            pointRadius: series.value.length > 40 ? 0 : 2,
            pointHoverRadius: 4,
        },
    ],
}));
const chartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    interaction: { intersect: false, mode: 'index' as const },
    plugins: { legend: { display: false }, tooltip: { displayColors: false } },
    scales: {
        x: {
            grid: { display: false },
            ticks: { maxTicksLimit: 7, maxRotation: 0 },
        },
        y: { beginAtZero: true, ticks: { precision: 0 } },
    },
};
const ga4Cards = computed(() => [
    {
        title:
            props.traffic.filters.website_id === null &&
            summary.value.ga4Count > 1
                ? 'Users · summed properties'
                : 'Users',
        value: count(summary.value.ga4?.users),
        comparison: trafficComparison(
            summary.value.ga4?.users ?? null,
            summary.value.ga4Previous?.users ?? null,
        ),
        icon: Users,
    },
    {
        title: 'Sessions',
        value: count(summary.value.ga4?.sessions),
        comparison: trafficComparison(
            summary.value.ga4?.sessions ?? null,
            summary.value.ga4Previous?.sessions ?? null,
        ),
        icon: Activity,
    },
    {
        title: 'Page & screen views',
        value: count(summary.value.ga4?.views),
        comparison: trafficComparison(
            summary.value.ga4?.views ?? null,
            summary.value.ga4Previous?.views ?? null,
        ),
        icon: BarChart3,
    },
    {
        title: 'Engagement rate',
        value: percent(summary.value.ga4?.engagement_rate),
        comparison: trafficComparison(
            summary.value.ga4?.engagement_rate ?? null,
            summary.value.ga4Previous?.engagement_rate ?? null,
            true,
        ),
        icon: ArrowUpRight,
    },
]);
const gscCards = computed(() => [
    {
        title: 'Search clicks',
        value: count(summary.value.gsc?.clicks),
        comparison: trafficComparison(
            summary.value.gsc?.clicks ?? null,
            summary.value.gscPrevious?.clicks ?? null,
        ),
    },
    {
        title: 'Impressions',
        value: count(summary.value.gsc?.impressions),
        comparison: trafficComparison(
            summary.value.gsc?.impressions ?? null,
            summary.value.gscPrevious?.impressions ?? null,
        ),
    },
    {
        title: 'Click-through rate',
        value: percent(summary.value.gsc?.ctr),
        comparison: trafficComparison(
            summary.value.gsc?.ctr ?? null,
            summary.value.gscPrevious?.ctr ?? null,
            true,
        ),
    },
    {
        title: 'Average position',
        value: summary.value.gsc?.impressions
            ? count(summary.value.gsc.position, 1)
            : '—',
        comparison:
            summary.value.gsc?.impressions &&
            summary.value.gscPrevious?.impressions
                ? `${summary.value.gsc.position - summary.value.gscPrevious.position > 0 ? '+' : ''}${(summary.value.gsc.position - summary.value.gscPrevious.position).toFixed(1)} vs previous · Lower is better`
                : 'No previous position baseline',
    },
]);
const poller = createTrafficPoller({
    available,
    read: async (signal) => {
        const { data } = await axios.get('/traffic/status', {
            params: query(),
            headers: { Accept: 'application/json' },
            signal,
            timeout: 20_000,
        });
        const next = readTrafficReports(data.reports);
        const ids = new Set(props.traffic.websites.map((site) => site.id));
        if (
            next.some(
                (report) =>
                    !ids.has(report.website_id) ||
                    (props.traffic.filters.website_id !== null &&
                        report.website_id !== props.traffic.filters.website_id),
            )
        )
            throw new Error('Invalid reporting scope');
        return next;
    },
    apply: (next) => {
        reports.value = next;
        checkOnVisible = trafficPending(next);
        progressMessage.value = '';
    },
    error: (message) => {
        errorMessage.value = message;
    },
    waiting: (value) => {
        waiting.value = value;
    },
});
function stopReporting(reset = true) {
    generation++;
    refreshRequest?.abort();
    refreshRequest = null;
    refreshing.value = false;
    realtimeRequest?.abort();
    realtimeRequest = null;
    realtimeLoading.value = false;
    if (reset) {
        poller.stop();
        pollStarted = false;
        checkOnVisible = false;
    } else poller.pause();
}
function beginChecks() {
    pollStarted = true;
    checkOnVisible = true;
    poller.start();
}
async function refreshReports() {
    if (!available() || refreshing.value || waiting.value || !anyMapped.value)
        return;
    stopReporting();
    const token = generation,
        scope = key.value;
    refreshing.value = true;
    errorMessage.value = '';
    progressMessage.value = '';
    refreshRequest = new AbortController();
    checkOnVisible = true;
    try {
        const { data } = await axios.post('/traffic/refresh', query(), {
            headers: { Accept: 'application/json' },
            signal: refreshRequest.signal,
            timeout: 25_000,
        });
        if (disposed || generation !== token || scope !== key.value) return;
        progressMessage.value =
            typeof data?.message === 'string'
                ? data.message
                : 'Report refresh requested.';
        if (available()) beginChecks();
    } catch (exception) {
        if (disposed || generation !== token) return;
        checkOnVisible = false;
        errorMessage.value = requestError(
            exception,
            'The reports could not be refreshed. Please try again.',
        );
    } finally {
        if (generation === token) {
            refreshing.value = false;
            refreshRequest = null;
        }
    }
}
function reconcile() {
    if (!available()) return;
    if (trafficPending(reports.value)) {
        beginChecks();
        return;
    }
    if (trafficNeedsRefresh(reports.value) && lastAutomaticKey !== key.value) {
        lastAutomaticKey = key.value;
        void refreshReports();
    }
}
function visibilityChanged() {
    if (document.hidden) {
        stopReporting(false);
        return;
    }
    if (checkOnVisible || trafficPending(reports.value)) {
        if (pollStarted) poller.resume();
        else beginChecks();
    } else reconcile();
}
function requestError(exception: unknown, fallback: string): string {
    if (axios.isAxiosError(exception)) {
        if ([401, 419].includes(exception.response?.status ?? 0))
            return 'Your session expired. Reload WP Hub and sign in again.';
        if (exception.response?.status === 429)
            return 'Please wait a moment before requesting another refresh.';
        if (typeof exception.response?.data?.message === 'string')
            return exception.response.data.message;
    }
    return fallback;
}
function applyFilters() {
    filterErrors.value = {};
    const start = Date.parse(`${draft.start_date}T00:00:00Z`),
        end = Date.parse(`${draft.end_date}T00:00:00Z`);
    if (
        !/^\d{4}-\d{2}-\d{2}$/.test(draft.start_date) ||
        !Number.isFinite(start) ||
        new Date(start).toISOString().slice(0, 10) !== draft.start_date
    )
        filterErrors.value.start_date = 'Choose a valid start date.';
    if (
        !/^\d{4}-\d{2}-\d{2}$/.test(draft.end_date) ||
        !Number.isFinite(end) ||
        new Date(end).toISOString().slice(0, 10) !== draft.end_date
    )
        filterErrors.value.end_date = 'Choose a valid end date.';
    if (end < start)
        filterErrors.value.end_date =
            'End date must be on or after the start date.';
    if ((end - start) / 86_400_000 + 1 > 93)
        filterErrors.value.end_date = 'Choose a range of up to 93 days.';
    const today = new Date().toISOString().slice(0, 10);
    if (draft.start_date > today)
        filterErrors.value.start_date =
            'Choose today or an earlier date (UTC).';
    if (draft.end_date > today)
        filterErrors.value.end_date = 'Choose today or an earlier date (UTC).';
    if (Object.keys(filterErrors.value).length) return;
    stopReporting();
    filterCancel?.();
    filterCancel = null;
    realtime.value = null;
    realtimeError.value = '';
    const token = ++filterGeneration;
    filterLoading.value = true;
    errorMessage.value = '';
    router.get(
        '/traffic',
        { ...draft },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onCancelToken: (cancel) => {
                filterCancel = cancel.cancel;
            },
            onError: (errors) => {
                if (!disposed && token === filterGeneration) {
                    filterErrors.value = errors;
                    errorMessage.value =
                        'The selected range could not be loaded. The reports below still show the previous range.';
                }
            },
            onFinish: () => {
                if (!disposed && token === filterGeneration) {
                    filterLoading.value = false;
                    filterCancel = null;
                    reconcile();
                }
            },
        },
    );
}
function applyQuick(days: number, now = new Date()) {
    Object.assign(draft, trafficRange(days, now));
    applyFilters();
}
async function loadRealtime() {
    if (
        !available() ||
        realtimeLoading.value ||
        !selectedReport.value?.ga4_property_id
    )
        return;
    const token = generation,
        scope = key.value,
        websiteId = props.traffic.filters.website_id;
    realtimeLoading.value = true;
    realtimeError.value = '';
    realtime.value = null;
    realtimeRequest = new AbortController();
    try {
        const { data } = await axios.get('/traffic/realtime', {
            params: { website_id: websiteId },
            headers: { Accept: 'application/json' },
            signal: realtimeRequest.signal,
            timeout: 25_000,
        });
        if (
            !Number.isSafeInteger(data.active_users) ||
            data.active_users < 0 ||
            typeof data.updated_at !== 'string' ||
            !Number.isFinite(Date.parse(data.updated_at))
        )
            throw new Error('Invalid realtime report');
        if (!disposed && generation === token && key.value === scope)
            realtime.value = {
                active_users: data.active_users,
                updated_at: data.updated_at,
            };
    } catch (exception) {
        if (!disposed && generation === token)
            realtimeError.value = requestError(
                exception,
                'Active users could not be checked. Try again later.',
            );
    } finally {
        if (generation === token) {
            realtimeLoading.value = false;
            realtimeRequest = null;
        }
    }
}
function count(value?: number | null, digits = 0): string {
    return value === null || value === undefined
        ? '—'
        : value.toLocaleString('en-US', { maximumFractionDigits: digits });
}
function percent(value?: number | null): string {
    return value === null || value === undefined
        ? '—'
        : `${(value * 100).toFixed(1)}%`;
}
function shortDate(value: string, year = true): string {
    return new Intl.DateTimeFormat('en-US', {
        timeZone: 'UTC',
        month: 'short',
        day: 'numeric',
        ...(year ? { year: 'numeric' } : {}),
    }).format(new Date(`${value}T00:00:00Z`));
}
function timestamp(value: string): string {
    return new Date(value).toLocaleString();
}
function money(value: number, currency: string): string {
    try {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency,
        }).format(value);
    } catch {
        return `${count(value, 2)} ${currency}`;
    }
}
function statusLabel(report: TrafficReport): string {
    return {
        unlinked: 'Not linked',
        missing: 'Not loaded',
        queued: 'Queued',
        running: 'Preparing',
        ready: 'Available',
        failed: report.ga4 || report.gsc ? 'Partial report' : 'Needs attention',
    }[report.status];
}
watch(
    () => props.traffic,
    (value) => {
        stopReporting();
        realtime.value = null;
        realtimeError.value = '';
        errorMessage.value = '';
        progressMessage.value = '';
        Object.assign(draft, {
            website_id: String(value.filters.website_id ?? ''),
            start_date: value.filters.start_date,
            end_date: value.filters.end_date,
        });
        try {
            reports.value = readTrafficReports(value.reports);
        } catch {
            reports.value = [];
            errorMessage.value =
                'The traffic reports could not be read. Reload the page to try again.';
        }
        if (mounted && !filterLoading.value) reconcile();
    },
    { immediate: true },
);
onMounted(() => {
    mounted = true;
    document.addEventListener('visibilitychange', visibilityChanged);
    window.addEventListener('popstate', stopForNavigation);
    removeNavigation = router.on('before', ({ detail: { visit } }) => {
        if (!visit.async) stopReporting();
    });
    reconcile();
});
function stopForNavigation() {
    stopReporting();
}
onUnmounted(() => {
    disposed = true;
    mounted = false;
    filterGeneration++;
    stopReporting();
    filterCancel?.();
    removeNavigation?.();
    document.removeEventListener('visibilitychange', visibilityChanged);
    window.removeEventListener('popstate', stopForNavigation);
});
</script>

<template>
    <Head title="Traffic & SEO" />
    <AppLayout :breadcrumbs="[{ title: 'Traffic & SEO', href: '/traffic' }]">
        <main
            class="mx-auto flex w-full max-w-[1600px] min-w-0 flex-col gap-6 p-4 pb-10 sm:p-6 lg:p-8"
            :aria-busy="filterLoading"
        >
            <header class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p
                        class="mb-2 text-xs font-semibold tracking-widest text-indigo-600 uppercase dark:text-indigo-300"
                    >
                        Audience & discovery
                    </p>
                    <h1
                        class="text-2xl font-semibold tracking-tight sm:text-3xl"
                    >
                        Traffic & SEO
                    </h1>
                    <p class="mt-2 text-sm text-muted-foreground">
                        See how visitors find your websites and how they perform
                        in Google Search.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button variant="outline" as-child
                        ><Link href="/settings/traffic"
                            ><Settings2 class="size-4" />Manage sources</Link
                        ></Button
                    ><Button
                        :disabled="
                            !traffic.connection.connected ||
                            !anyMapped ||
                            refreshing ||
                            waiting ||
                            filterLoading
                        "
                        @click="refreshReports"
                        ><LoaderCircle
                            v-if="refreshing || waiting"
                            class="size-4 animate-spin"
                        /><RefreshCw v-else class="size-4" />Refresh
                        reports</Button
                    >
                </div>
            </header>

            <section
                v-if="!traffic.connection.connected"
                class="flex flex-col gap-4 rounded-xl border border-indigo-500/20 bg-indigo-500/5 p-5 sm:flex-row sm:items-center sm:justify-between"
            >
                <div class="flex gap-3">
                    <Globe class="mt-0.5 size-6 shrink-0 text-indigo-600" />
                    <div>
                        <h2 class="font-semibold">
                            {{
                                reconnectRequired
                                    ? 'Reconnect your reporting account'
                                    : 'Connect your reporting account'
                            }}
                        </h2>
                        <p class="mt-1 max-w-2xl text-sm text-muted-foreground">
                            {{
                                reconnectRequired
                                    ? 'Renew Google access to refresh your reports. Reconnecting the same account keeps the website sources it can still access.'
                                    : 'Link Google Analytics 4 and Search Console to see traffic and search performance here. Use the Google account that owns your website properties.'
                            }}
                        </p>
                    </div>
                </div>
                <Button as-child
                    ><Link href="/settings/traffic">{{
                        reconnectRequired
                            ? 'Reconnect Google'
                            : 'Connect Google'
                    }}</Link></Button
                >
            </section>
            <div
                v-if="errorMessage"
                role="alert"
                class="flex gap-2 rounded-lg border border-amber-500/30 bg-amber-500/5 p-3 text-sm"
            >
                <CircleAlert class="mt-0.5 size-4 shrink-0" />{{ errorMessage }}
            </div>
            <div
                v-else-if="waiting || refreshing || progressMessage"
                role="status"
                class="flex gap-2 rounded-lg border bg-muted/20 p-3 text-sm text-muted-foreground"
            >
                <LoaderCircle
                    v-if="waiting || refreshing"
                    class="size-4 shrink-0 animate-spin"
                />{{
                    waiting
                        ? 'Reports are being prepared. This page will update when they are ready.'
                        : progressMessage || 'Requesting reports…'
                }}
            </div>

            <section
                class="rounded-xl border bg-card p-4"
                aria-label="Traffic filters"
            >
                <form
                    class="flex flex-wrap items-end gap-3"
                    @submit.prevent="applyFilters"
                >
                    <div class="min-w-40 flex-1">
                        <label
                            for="traffic-filter-website"
                            class="traffic-label"
                            >Website</label
                        ><select
                            id="traffic-filter-website"
                            v-model="draft.website_id"
                            class="traffic-input"
                            @change="applyFilters"
                        >
                            <option value="">All websites</option>
                            <option
                                v-for="site in traffic.websites"
                                :key="site.id"
                                :value="String(site.id)"
                            >
                                {{ site.name }}
                            </option>
                        </select>
                    </div>
                    <div class="min-w-36 flex-1">
                        <label for="traffic-from" class="traffic-label"
                            >From</label
                        ><input
                            id="traffic-from"
                            v-model="draft.start_date"
                            type="date"
                            required
                            class="traffic-input"
                            :aria-invalid="!!filterErrors.start_date"
                        />
                    </div>
                    <div class="min-w-36 flex-1">
                        <label for="traffic-to" class="traffic-label"
                            >Through</label
                        ><input
                            id="traffic-to"
                            v-model="draft.end_date"
                            type="date"
                            required
                            class="traffic-input"
                            :aria-invalid="!!filterErrors.end_date"
                        />
                    </div>
                    <Button type="submit" variant="outline"
                        ><LoaderCircle
                            v-if="filterLoading"
                            class="size-4 animate-spin"
                        />Apply range</Button
                    >
                </form>
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <span class="mr-1 text-xs text-muted-foreground"
                        >Complete days</span
                    ><button
                        v-for="days in [7, 28, 90]"
                        :key="days"
                        type="button"
                        class="rounded-full border px-3 py-1 text-xs hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
                        @click="applyQuick(days)"
                    >
                        Last {{ days }} days</button
                    ><span
                        class="ml-auto flex items-center gap-1.5 text-xs text-muted-foreground"
                        ><CalendarDays class="size-3.5" />{{ rangeLabel }}</span
                    >
                </div>
                <p
                    v-for="(message, field) in filterErrors"
                    :key="field"
                    role="alert"
                    class="mt-2 text-xs text-destructive"
                >
                    {{ message }}
                </p>
            </section>

            <section
                class="space-y-3"
                aria-labelledby="traffic-overview-heading"
            >
                <div class="flex flex-wrap items-end justify-between gap-2">
                    <div>
                        <h2
                            id="traffic-overview-heading"
                            class="text-lg font-semibold"
                        >
                            Website traffic
                        </h2>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Google Analytics 4 · {{ scopeName }} · Compared with
                            the previous {{ previousDays }} days
                        </p>
                    </div>
                    <span class="text-xs text-muted-foreground"
                        >{{ summary.ga4Count }} of {{ mappedGa4 }} linked
                        properties available</span
                    >
                </div>
                <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
                    <article
                        v-for="card in ga4Cards"
                        :key="card.title"
                        class="traffic-metric"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <h3
                                class="text-xs font-medium text-muted-foreground"
                            >
                                {{ card.title }}
                            </h3>
                            <component
                                :is="card.icon"
                                class="size-4 shrink-0 text-indigo-500"
                            />
                        </div>
                        <p
                            class="mt-3 text-2xl font-semibold tracking-tight tabular-nums sm:text-3xl"
                        >
                            {{ card.value }}
                        </p>
                        <p class="mt-2 text-[11px] text-muted-foreground">
                            {{ card.comparison }}
                        </p>
                    </article>
                </div>
                <p
                    v-if="!summary.ga4Count"
                    class="text-xs text-muted-foreground"
                >
                    {{
                        mappedGa4
                            ? 'Traffic metrics will appear when a Google Analytics report is available. Missing reports are not counted as zero.'
                            : 'Link a Google Analytics 4 property in Manage sources to see traffic metrics.'
                    }}
                </p>
                <p
                    v-else-if="
                        traffic.filters.website_id === null &&
                        summary.ga4Count > 1
                    "
                    class="text-xs text-muted-foreground"
                >
                    Users are summed across properties, so the same person may
                    be counted on more than one website. Engagement rate is
                    weighted by sessions.
                </p>
                <p
                    v-if="summary.ga4Count > 0 && summary.ga4Count < mappedGa4"
                    class="text-xs text-amber-700 dark:text-amber-300"
                >
                    Partial coverage: these figures include only the available
                    properties.
                </p>
            </section>

            <div class="grid min-w-0 gap-4 xl:grid-cols-[1.6fr_1fr]">
                <section
                    class="min-w-0 rounded-xl border bg-card p-4 sm:p-5"
                    aria-labelledby="traffic-trend-heading"
                >
                    <div
                        class="flex flex-wrap items-center justify-between gap-3"
                    >
                        <h2 id="traffic-trend-heading" class="font-semibold">
                            Daily trend
                        </h2>
                        <div class="flex rounded-lg bg-muted p-1">
                            <button
                                type="button"
                                class="rounded-md px-3 py-1.5 text-xs"
                                :class="
                                    chartMetric === 'sessions'
                                        ? 'bg-background shadow-sm'
                                        : 'text-muted-foreground'
                                "
                                :aria-pressed="chartMetric === 'sessions'"
                                @click="chartMetric = 'sessions'"
                            >
                                Sessions</button
                            ><button
                                type="button"
                                class="rounded-md px-3 py-1.5 text-xs"
                                :class="
                                    chartMetric === 'clicks'
                                        ? 'bg-background shadow-sm'
                                        : 'text-muted-foreground'
                                "
                                :aria-pressed="chartMetric === 'clicks'"
                                @click="chartMetric = 'clicks'"
                            >
                                Search clicks
                            </button>
                        </div>
                    </div>
                    <div v-if="series.length" class="mt-5 h-64 min-w-0">
                        <Line
                            :data="chartData"
                            :options="chartOptions"
                            :aria-label="
                                chartMetric === 'sessions'
                                    ? 'Daily GA4 sessions'
                                    : 'Daily Google Search clicks'
                            "
                        />
                    </div>
                    <div
                        v-else
                        class="flex h-64 items-center justify-center text-sm text-muted-foreground"
                    >
                        No daily report available for this source and range.
                    </div>
                    <p class="mt-3 text-[11px] text-muted-foreground">
                        {{
                            chartMetric === 'sessions'
                                ? `GA4 dates use ${ga4Timezones.length ? ga4Timezones.join(', ') : 'the property reporting timezone'}.`
                                : 'Search Console dates use Pacific Time. Recent search data can arrive later.'
                        }}
                    </p>
                </section>
                <section
                    class="min-w-0 rounded-xl border bg-card p-4 sm:p-5"
                    aria-labelledby="traffic-breakdown-heading"
                >
                    <h2 id="traffic-breakdown-heading" class="font-semibold">
                        Where traffic comes from
                    </h2>
                    <div class="mt-3 flex flex-wrap gap-1">
                        <button
                            v-for="tab in [
                                { key: 'sources', label: 'Sources' },
                                { key: 'pages', label: 'Pages' },
                                { key: 'countries', label: 'Countries' },
                                { key: 'devices', label: 'Devices' },
                            ]"
                            :key="tab.key"
                            type="button"
                            class="rounded-md px-2.5 py-1.5 text-xs"
                            :class="
                                breakdown === tab.key
                                    ? 'bg-indigo-500/10 font-medium text-indigo-700 dark:text-indigo-300'
                                    : 'text-muted-foreground hover:bg-muted'
                            "
                            :aria-pressed="breakdown === tab.key"
                            @click="breakdown = tab.key as typeof breakdown"
                        >
                            {{ tab.label }}
                        </button>
                    </div>
                    <div
                        class="mt-3 flex justify-between border-b pb-2 text-[11px] text-muted-foreground"
                    >
                        <span>{{
                            breakdown === 'pages'
                                ? 'Page path'
                                : breakdown === 'sources'
                                  ? 'Source / medium'
                                  : breakdown === 'countries'
                                    ? 'Country'
                                    : 'Device'
                        }}</span
                        ><span>{{
                            breakdown === 'sources'
                                ? 'Sessions'
                                : breakdown === 'pages'
                                  ? 'Views'
                                  : 'Users'
                        }}</span>
                    </div>
                    <ol
                        v-if="rows.length"
                        class="max-h-64 divide-y overflow-y-auto"
                    >
                        <li
                            v-for="row in rows"
                            :key="row.name"
                            class="flex min-w-0 items-start justify-between gap-3 py-2.5 text-sm"
                        >
                            <span class="min-w-0 break-all">{{
                                row.name || '(not set)'
                            }}</span
                            ><span class="shrink-0 font-medium tabular-nums">{{
                                count(row.value)
                            }}</span>
                        </li>
                    </ol>
                    <p
                        v-else
                        class="py-12 text-center text-sm text-muted-foreground"
                    >
                        {{
                            summary.ga4Count
                                ? 'No rows returned for this range.'
                                : 'Google Analytics data is not available yet.'
                        }}
                    </p>
                    <p class="mt-3 text-[11px] text-muted-foreground">
                        Top {{ rows.length }} rows from the available
                        properties. Table rows may not add up to report totals.
                    </p>
                </section>
            </div>

            <section class="space-y-3" aria-labelledby="traffic-search-heading">
                <div class="flex flex-wrap items-end justify-between gap-2">
                    <div>
                        <h2
                            id="traffic-search-heading"
                            class="flex items-center gap-2 text-lg font-semibold"
                        >
                            <Search class="size-5 text-emerald-600" />Google
                            Search performance
                        </h2>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Search Console · Organic Google Search ·
                            {{ summary.gscCount }} of {{ mappedGsc }} linked
                            properties available
                        </p>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
                    <article
                        v-for="card in gscCards"
                        :key="card.title"
                        class="traffic-metric"
                    >
                        <h3 class="text-xs font-medium text-muted-foreground">
                            {{ card.title }}
                        </h3>
                        <p
                            class="mt-3 text-2xl font-semibold tracking-tight tabular-nums sm:text-3xl"
                        >
                            {{ card.value }}
                        </p>
                        <p class="mt-2 text-[11px] text-muted-foreground">
                            {{ card.comparison }}
                        </p>
                    </article>
                </div>
                <p
                    v-if="!summary.gscCount"
                    class="text-xs text-muted-foreground"
                >
                    {{
                        mappedGsc
                            ? 'Search metrics will appear when a Search Console report is available.'
                            : 'Link a Search Console property in Manage sources to see search performance.'
                    }}
                </p>
                <p v-else class="text-xs text-muted-foreground">
                    CTR and average position are weighted by impressions. Search
                    clicks and Analytics sessions measure different activity.
                </p>
                <p
                    v-if="summary.gscCount > 0 && summary.gscCount < mappedGsc"
                    class="text-xs text-amber-700 dark:text-amber-300"
                >
                    Partial coverage: some linked Search Console properties are
                    unavailable.
                </p>
                <div class="overflow-hidden rounded-xl border bg-card">
                    <div
                        class="flex flex-wrap items-center justify-between gap-3 border-b p-4"
                    >
                        <h3 class="font-semibold">
                            {{
                                searchTable === 'queries'
                                    ? 'Top search queries'
                                    : 'Top search landing pages'
                            }}
                        </h3>
                        <div class="flex rounded-lg bg-muted p-1">
                            <button
                                v-for="tab in [
                                    { key: 'queries', label: 'Queries' },
                                    { key: 'pages', label: 'Pages' },
                                ]"
                                :key="tab.key"
                                type="button"
                                class="rounded-md px-3 py-1.5 text-xs"
                                :class="
                                    searchTable === tab.key
                                        ? 'bg-background shadow-sm'
                                        : 'text-muted-foreground'
                                "
                                :aria-pressed="searchTable === tab.key"
                                @click="
                                    searchTable = tab.key as typeof searchTable
                                "
                            >
                                {{ tab.label }}
                            </button>
                        </div>
                    </div>
                    <div
                        v-if="searchRows.length"
                        class="max-h-[26rem] overflow-auto"
                    >
                        <table class="w-full min-w-[560px] text-left text-sm">
                            <thead
                                class="sticky top-0 bg-muted text-xs text-muted-foreground"
                            >
                                <tr>
                                    <th scope="col" class="px-4 py-3">
                                        {{
                                            searchTable === 'queries'
                                                ? 'Query'
                                                : 'Landing page'
                                        }}
                                    </th>
                                    <th
                                        scope="col"
                                        class="px-4 py-3 text-right"
                                    >
                                        Clicks
                                    </th>
                                    <th
                                        scope="col"
                                        class="px-4 py-3 text-right"
                                    >
                                        Impressions
                                    </th>
                                    <th
                                        scope="col"
                                        class="px-4 py-3 text-right"
                                    >
                                        CTR
                                    </th>
                                    <th
                                        scope="col"
                                        class="px-4 py-3 text-right"
                                    >
                                        Position
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr v-for="row in searchRows" :key="row.name">
                                    <td class="max-w-96 px-4 py-3 break-all">
                                        {{ row.name }}
                                    </td>
                                    <td
                                        class="px-4 py-3 text-right tabular-nums"
                                    >
                                        {{ count(row.clicks) }}
                                    </td>
                                    <td
                                        class="px-4 py-3 text-right tabular-nums"
                                    >
                                        {{ count(row.impressions) }}
                                    </td>
                                    <td
                                        class="px-4 py-3 text-right tabular-nums"
                                    >
                                        {{ percent(row.ctr) }}
                                    </td>
                                    <td
                                        class="px-4 py-3 text-right tabular-nums"
                                    >
                                        {{
                                            row.impressions
                                                ? count(row.position, 1)
                                                : '—'
                                        }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p
                        v-else
                        class="p-10 text-center text-sm text-muted-foreground"
                    >
                        {{
                            summary.gscCount
                                ? 'No search rows returned for this range.'
                                : 'Search Console data is not available yet.'
                        }}
                    </p>
                    <p
                        class="border-t p-4 text-[11px] leading-relaxed text-muted-foreground"
                    >
                        Up to 50 top rows are shown. Search Console can omit
                        queries for privacy, so these rows do not represent
                        every search or necessarily add up to the totals. No
                        keyword-to-order attribution is implied.
                    </p>
                </div>
            </section>

            <div class="grid gap-4 lg:grid-cols-2">
                <section
                    class="rounded-xl border bg-card p-5"
                    aria-labelledby="traffic-realtime-heading"
                >
                    <div class="flex items-center justify-between gap-3">
                        <h2
                            id="traffic-realtime-heading"
                            class="flex items-center gap-2 font-semibold"
                        >
                            <Activity class="size-4 text-emerald-500" />Active
                            users now
                        </h2>
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="
                                !selectedReport?.ga4_property_id ||
                                !traffic.connection.connected ||
                                realtimeLoading ||
                                filterLoading
                            "
                            @click="loadRealtime"
                            ><LoaderCircle
                                v-if="realtimeLoading"
                                class="size-4 animate-spin"
                            />Check now</Button
                        >
                    </div>
                    <p
                        v-if="realtime"
                        class="mt-3 text-3xl font-semibold tabular-nums"
                    >
                        {{ count(realtime.active_users) }}
                    </p>
                    <p class="mt-2 text-sm text-muted-foreground">
                        {{
                            selectedReport?.ga4_property_id
                                ? 'GA4 active users in the last 30 minutes for this website.'
                                : 'Select one website with a linked GA4 property to check active users.'
                        }}
                    </p>
                    <p
                        v-if="realtime"
                        class="mt-2 text-xs text-muted-foreground"
                    >
                        Checked {{ timestamp(realtime.updated_at) }} · Separate
                        from the date range above.
                    </p>
                    <p
                        v-if="realtimeError"
                        role="alert"
                        class="mt-2 text-xs text-destructive"
                    >
                        {{ realtimeError }}
                    </p>
                </section>
                <section
                    class="rounded-xl border bg-card p-5"
                    aria-labelledby="traffic-orders-heading"
                >
                    <h2
                        id="traffic-orders-heading"
                        class="flex items-center gap-2 font-semibold"
                    >
                        <ShoppingBag
                            class="size-4 text-indigo-500"
                        />WooCommerce orders
                    </h2>
                    <div
                        class="mt-3 flex flex-wrap items-start gap-x-7 gap-y-4"
                    >
                        <div>
                            <p class="text-xs text-muted-foreground">Orders</p>
                            <p class="mt-1 text-2xl font-semibold tabular-nums">
                                {{ count(traffic.orders.total) }}
                            </p>
                        </div>
                        <div>
                            <p class="text-xs text-muted-foreground">
                                Completed
                            </p>
                            <p class="mt-1 text-2xl font-semibold tabular-nums">
                                {{ count(traffic.orders.completed) }}
                            </p>
                        </div>
                        <div>
                            <p class="text-xs text-muted-foreground">
                                Completed revenue
                            </p>
                            <p
                                v-for="amount in traffic.orders.revenue"
                                :key="amount.currency"
                                class="mt-1 text-xl font-semibold tabular-nums"
                            >
                                {{ money(amount.total, amount.currency) }}
                                <span
                                    class="text-xs font-normal text-muted-foreground"
                                    >{{ amount.currency }}</span
                                >
                            </p>
                            <p
                                v-if="!traffic.orders.revenue.length"
                                class="mt-1 text-sm text-muted-foreground"
                            >
                                No completed revenue in this range
                            </p>
                        </div>
                    </div>
                    <p class="mt-3 text-[11px] text-muted-foreground">
                        WP Hub order records · UTC dates · {{ scopeName }}.
                        Currencies remain separate; these totals are not
                        attributed to traffic sources.
                    </p>
                </section>
            </div>

            <section
                class="overflow-hidden rounded-xl border bg-card"
                aria-labelledby="traffic-coverage-heading"
            >
                <div
                    class="flex flex-wrap items-center justify-between gap-3 border-b p-4 sm:px-5"
                >
                    <h2 id="traffic-coverage-heading" class="font-semibold">
                        Website coverage
                    </h2>
                    <span class="text-xs text-muted-foreground">{{
                        traffic.connection.connected
                            ? traffic.connection.email
                            : 'Reporting account not connected'
                    }}</span>
                </div>
                <p
                    v-if="!reports.length"
                    class="p-6 text-sm text-muted-foreground"
                >
                    No websites are available for this selection.
                </p>
                <div class="divide-y">
                    <article
                        v-for="report in reports"
                        :key="report.website_id"
                        class="space-y-3 p-4 sm:px-5"
                    >
                        <div
                            class="flex flex-wrap items-start justify-between gap-3"
                        >
                            <div class="min-w-0">
                                <h3 class="font-medium">
                                    {{ report.website_name }}
                                </h3>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    GA4:
                                    {{ report.ga4_property_id || 'Not linked' }}
                                    · Search Console:
                                    <span class="break-all">{{
                                        report.gsc_site_url || 'Not linked'
                                    }}</span>
                                </p>
                            </div>
                            <span
                                class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs"
                                :class="
                                    report.status === 'ready'
                                        ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'
                                        : report.status === 'failed'
                                          ? 'bg-amber-500/10 text-amber-800 dark:text-amber-300'
                                          : 'bg-muted text-muted-foreground'
                                "
                                ><LoaderCircle
                                    v-if="
                                        report.status === 'queued' ||
                                        report.status === 'running'
                                    "
                                    class="size-3 animate-spin"
                                /><CheckCircle2
                                    v-else-if="report.status === 'ready'"
                                    class="size-3"
                                />{{ statusLabel(report) }}</span
                            >
                        </div>
                        <div class="flex flex-wrap gap-x-6 gap-y-2 text-xs">
                            <span
                                >Users
                                <strong class="ml-1 font-semibold">{{
                                    count(report.ga4?.totals.users)
                                }}</strong></span
                            ><span
                                >Sessions
                                <strong class="ml-1 font-semibold">{{
                                    count(report.ga4?.totals.sessions)
                                }}</strong></span
                            ><span
                                >Search clicks
                                <strong class="ml-1 font-semibold">{{
                                    count(report.gsc?.totals.clicks)
                                }}</strong></span
                            ><span
                                v-if="report.updated_at"
                                class="text-muted-foreground"
                                >Updated
                                {{ timestamp(report.updated_at) }}</span
                            >
                        </div>
                        <p
                            v-if="report.error"
                            class="text-xs text-amber-800 dark:text-amber-300"
                        >
                            {{ report.error }}
                        </p>
                        <Link
                            v-if="
                                report.status === 'unlinked' ||
                                report.status === 'failed'
                            "
                            href="/settings/traffic"
                            class="inline-block text-xs text-primary underline underline-offset-4"
                            >{{
                                report.status === 'unlinked'
                                    ? 'Link reporting sources'
                                    : 'Review reporting connection'
                            }}</Link
                        >
                    </article>
                </div>
            </section>
            <aside
                v-if="notes.length"
                class="rounded-lg bg-muted/30 p-4 text-xs leading-relaxed text-muted-foreground"
                aria-label="Reporting notes"
            >
                <p class="mb-2 font-medium text-foreground">Source notes</p>
                <ul class="list-disc space-y-1 pl-4">
                    <li v-for="note in notes" :key="note">{{ note }}</li>
                </ul>
            </aside>
            <footer
                class="flex flex-wrap justify-between gap-2 text-[11px] leading-relaxed text-muted-foreground"
            >
                <p>
                    Cached reports update when requested. Google can process
                    recent data with a delay.
                </p>
                <p>
                    GA4 property timezones · Search Console Pacific Time ·
                    Orders UTC
                </p>
            </footer>
        </main>
    </AppLayout>
</template>

<style scoped>
@reference "../../css/app.css";
.traffic-metric {
    @apply rounded-xl border bg-card p-4 sm:p-5;
}
.traffic-label {
    @apply mb-1.5 block text-xs font-medium text-muted-foreground;
}
.traffic-input {
    @apply w-full min-w-0 rounded-lg border bg-background px-3 py-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring;
}
</style>
