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
    createSeoPoller,
    readSeoReport,
    readSeoReports,
    seoCategories,
    seoCounts,
    seoDate,
    seoFiltered,
    seoNeedsRefresh,
    seoOpportunities,
    seoPageUrl,
    seoPending,
    seoRange,
} from '@/lib/seo';
import type {
    SeoOpportunityRow,
    SeoOpportunityType,
    SeoPage,
    SeoReport,
} from '@/types/seo';
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import {
    ArrowRight,
    ChevronLeft,
    ChevronRight,
    CircleAlert,
    FileSearch,
    Globe,
    LoaderCircle,
    RefreshCw,
    Search,
    Settings2,
    Target,
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

const props = defineProps<{ seo: SeoPage }>();
const reports = shallowRef<SeoReport[]>([]);
const draft = reactive({
    website_id: String(props.seo.filters.website_id ?? ''),
    start_date: props.seo.filters.start_date,
    end_date: props.seo.filters.end_date,
});
const filterErrors = ref<Record<string, string>>({});
const filterLoading = ref(false),
    refreshing = ref(false),
    waiting = ref(false);
const errorMessage = ref('');
const category = ref<'all' | SeoOpportunityType>('all'),
    search = ref(''),
    pageNumber = ref(1);
const pageSize = 25;
const allRows = computed(() => seoOpportunities(reports.value));
const filteredRows = computed(() =>
    seoFiltered(allRows.value, category.value, search.value),
);
const pageCount = computed(() =>
    Math.max(1, Math.ceil(filteredRows.value.length / pageSize)),
);
const rows = computed(() =>
    filteredRows.value.slice(
        (pageNumber.value - 1) * pageSize,
        pageNumber.value * pageSize,
    ),
);
const counts = computed(() => seoCounts(reports.value));
const readyCount = computed(
    () =>
        reports.value.filter(
            (report) => report.status === 'ready' && report.data,
        ).length,
);
const anyMapped = computed(() =>
    reports.value.some((report) => report.gsc_site_url),
);
const notes = computed(() => [
    ...new Set(reports.value.flatMap((report) => report.data?.notes ?? [])),
]);
const categoryCards = computed(() =>
    seoCategories.map((item) => ({
        ...item,
        count: allRows.value.filter((row) => row.type === item.key).length,
    })),
);
const scopeName = computed(
    () =>
        props.seo.websites.find(
            (site) => site.id === props.seo.filters.website_id,
        )?.name ?? 'All websites',
);
const rangeLabel = computed(
    () =>
        `${shortDate(props.seo.filters.start_date)} – ${shortDate(props.seo.filters.end_date)}`,
);
const previousLabel = computed(() => {
    const data = reports.value.find((report) => report.data)?.data;
    return data
        ? `${shortDate(data.previous_start_date)} – ${shortDate(data.previous_end_date)}`
        : '';
});
const key = computed(() =>
    JSON.stringify([
        props.seo.filters,
        props.seo.connection,
        props.seo.reports.map((report) => [
            report.website_id,
            report.gsc_site_url,
        ]),
    ]),
);
const selectedPage = shallowRef<{
    website_id: number;
    website_name: string;
    page_url: string;
} | null>(null);
const detailOpen = ref(false),
    detailBusy = ref(false),
    detailWaiting = ref(false),
    detailError = ref('');
const detailReport = shallowRef<SeoReport | null>(null);
const querySearch = ref(''),
    queryPage = ref(1);
const detailQueries = computed(() =>
    (detailReport.value?.data?.queries ?? []).filter((row) =>
        row.name
            .toLocaleLowerCase()
            .includes(querySearch.value.trim().toLocaleLowerCase()),
    ),
);
const queryPageCount = computed(() =>
    Math.max(1, Math.ceil(detailQueries.value.length / pageSize)),
);
const visibleQueries = computed(() =>
    detailQueries.value.slice(
        (queryPage.value - 1) * pageSize,
        queryPage.value * pageSize,
    ),
);
let mounted = false,
    disposed = false,
    generation = 0,
    detailGeneration = 0,
    filterGeneration = 0;
let refreshRequest: AbortController | null = null,
    detailRequest: AbortController | null = null;
let filterCancel: (() => void) | null = null,
    removeNavigation: (() => void) | null = null;
let pollStarted = false,
    checkOnVisible = false,
    detailPollStarted = false,
    detailCheckOnVisible = false;
const automaticKeys = new Set<string>();
const query = () => ({
    ...props.seo.filters,
    website_id: props.seo.filters.website_id ?? '',
});
const available = () =>
    mounted &&
    !disposed &&
    !document.hidden &&
    !filterLoading.value &&
    props.seo.connection.connected;
const detailAvailable = () =>
    available() && detailOpen.value && selectedPage.value !== null;
function scopedReports(value: unknown): SeoReport[] {
    const next = readSeoReports(value);
    const ids = new Set(props.seo.websites.map((site) => site.id));
    if (
        next.some(
            (report) =>
                !ids.has(report.website_id) ||
                (props.seo.filters.website_id !== null &&
                    report.website_id !== props.seo.filters.website_id),
        )
    )
        throw new Error('Invalid SEO scope');
    return next;
}
function scopedDetail(value: unknown): SeoReport {
    const report = readSeoReport(value);
    if (report.website_id !== selectedPage.value?.website_id)
        throw new Error('Invalid page scope');
    return report;
}
const poller = createSeoPoller({
    available,
    pending: seoPending,
    read: async (signal) => {
        const { data } = await axios.get('/seo/status', {
            params: query(),
            headers: { Accept: 'application/json' },
            signal,
            timeout: 20_000,
        });
        return scopedReports(data.reports);
    },
    apply: (next) => {
        reports.value = next;
        checkOnVisible = seoPending(next);
    },
    waiting: (value) => {
        waiting.value = value;
    },
    error: (message) => {
        errorMessage.value = message;
        checkOnVisible = false;
    },
    failure: (error) =>
        requestError(
            error,
            'Analysis progress could not be checked. Use Refresh analysis to try again.',
        ),
});
const detailPoller = createSeoPoller({
    available: detailAvailable,
    pending: (report: SeoReport) => seoPending([report]),
    read: async (signal) => {
        const { data } = await axios.get('/seo/page', {
            params: detailParams(),
            headers: { Accept: 'application/json' },
            signal,
            timeout: 20_000,
        });
        return scopedDetail(data.report);
    },
    apply: (report) => {
        detailReport.value = report;
        detailCheckOnVisible = seoPending([report]);
    },
    waiting: (value) => {
        detailWaiting.value = value;
    },
    error: (message) => {
        detailError.value = message;
        detailCheckOnVisible = false;
    },
    failure: (error) =>
        requestError(
            error,
            'Page analysis could not be checked. Use Refresh analysis to try again.',
        ),
});
function stopOverview(reset = true) {
    generation++;
    refreshRequest?.abort();
    refreshRequest = null;
    refreshing.value = false;
    if (reset) {
        poller.stop();
        pollStarted = false;
        checkOnVisible = false;
    } else poller.pause();
}
function stopDetail(reset = true) {
    detailGeneration++;
    detailRequest?.abort();
    detailRequest = null;
    detailBusy.value = false;
    if (reset) {
        detailPoller.stop();
        detailPollStarted = false;
        detailCheckOnVisible = false;
    } else detailPoller.pause();
}
function beginChecks() {
    pollStarted = true;
    checkOnVisible = true;
    poller.start();
}
function beginDetailChecks() {
    detailPollStarted = true;
    detailCheckOnVisible = true;
    detailPoller.start();
}
async function refreshReports() {
    if (!available() || refreshing.value || waiting.value || !anyMapped.value)
        return;
    stopOverview();
    closeDetail();
    const token = generation,
        scope = key.value;
    refreshing.value = true;
    errorMessage.value = '';
    checkOnVisible = true;
    refreshRequest = new AbortController();
    try {
        await axios.post('/seo/refresh', query(), {
            headers: { Accept: 'application/json' },
            signal: refreshRequest.signal,
            timeout: 25_000,
        });
        if (disposed || token !== generation || scope !== key.value) return;
        reports.value = reports.value.map((report) =>
            report.gsc_site_url
                ? { ...report, status: 'queued', data: null, error: null }
                : report,
        );
        if (available()) beginChecks();
    } catch (error) {
        if (disposed || token !== generation) return;
        checkOnVisible = false;
        errorMessage.value = requestError(
            error,
            'Analysis could not be requested. Use Refresh analysis to try again.',
        );
    } finally {
        if (token === generation) {
            refreshing.value = false;
            refreshRequest = null;
        }
    }
}
function reconcile() {
    if (!available()) return;
    if (seoPending(reports.value) && !pollStarted) {
        beginChecks();
        return;
    }
    if (seoNeedsRefresh(reports.value) && !automaticKeys.has(key.value)) {
        automaticKeys.add(key.value);
        void refreshReports();
    }
}
function detailParams() {
    return {
        ...props.seo.filters,
        website_id: selectedPage.value?.website_id,
        page_url: selectedPage.value?.page_url,
    };
}
async function loadDetail() {
    if (!detailAvailable() || detailBusy.value || detailWaiting.value) return;
    stopDetail();
    const token = detailGeneration,
        scope = key.value,
        page = selectedPage.value;
    detailBusy.value = true;
    detailError.value = '';
    detailCheckOnVisible = true;
    detailRequest = new AbortController();
    try {
        const { data } = await axios.get('/seo/page', {
            params: detailParams(),
            headers: { Accept: 'application/json' },
            signal: detailRequest.signal,
            timeout: 20_000,
        });
        if (
            disposed ||
            token !== detailGeneration ||
            scope !== key.value ||
            page !== selectedPage.value
        )
            return;
        detailReport.value = scopedDetail(data.report);
        detailCheckOnVisible = false;
        if (seoPending([detailReport.value])) beginDetailChecks();
        else if (seoNeedsRefresh([detailReport.value])) {
            detailBusy.value = false;
            await refreshDetail();
        }
    } catch (error) {
        if (disposed || token !== detailGeneration) return;
        detailCheckOnVisible = false;
        detailError.value = requestError(
            error,
            'Page queries could not be loaded. Use Refresh analysis to try again.',
        );
    } finally {
        if (token === detailGeneration) {
            detailBusy.value = false;
            detailRequest = null;
        }
    }
}
async function refreshDetail() {
    if (!detailAvailable() || detailBusy.value || detailWaiting.value) return;
    stopDetail();
    const token = detailGeneration,
        scope = key.value;
    detailBusy.value = true;
    detailError.value = '';
    detailCheckOnVisible = true;
    detailRequest = new AbortController();
    try {
        await axios.post('/seo/page/refresh', detailParams(), {
            headers: { Accept: 'application/json' },
            signal: detailRequest.signal,
            timeout: 25_000,
        });
        if (disposed || token !== detailGeneration || scope !== key.value)
            return;
        if (detailReport.value)
            detailReport.value = {
                ...detailReport.value,
                status: 'queued',
                data: null,
                error: null,
            };
        if (detailAvailable()) beginDetailChecks();
    } catch (error) {
        if (disposed || token !== detailGeneration) return;
        detailCheckOnVisible = false;
        detailError.value = requestError(
            error,
            'Page analysis could not be requested. Use Refresh analysis to try again.',
        );
    } finally {
        if (token === detailGeneration) {
            detailBusy.value = false;
            detailRequest = null;
        }
    }
}
function openPage(row: SeoOpportunityRow) {
    if (
        row.dimension !== 'page' ||
        !seoPageUrl(row.name) ||
        !allRows.value.some(
            (item) => item.key === row.key && item.name === row.name,
        )
    )
        return;
    stopDetail();
    detailReport.value = null;
    detailError.value = '';
    querySearch.value = '';
    queryPage.value = 1;
    selectedPage.value = {
        website_id: row.website_id,
        website_name: row.website_name,
        page_url: row.name,
    };
    detailOpen.value = true;
    void loadDetail();
}
function closeDetail() {
    stopDetail();
    detailOpen.value = false;
    selectedPage.value = null;
    detailReport.value = null;
}
function setDetailOpen(value: boolean) {
    if (!value) closeDetail();
}
function applyFilters() {
    filterErrors.value = {};
    if (!seoDate(draft.start_date))
        filterErrors.value.start_date = 'Choose a valid start date.';
    if (!seoDate(draft.end_date))
        filterErrors.value.end_date = 'Choose a valid end date.';
    if (draft.end_date > props.seo.max_end_date)
        filterErrors.value.end_date = `Choose ${shortDate(props.seo.max_end_date)} or earlier to allow Google’s processing buffer.`;
    if (draft.start_date > draft.end_date)
        filterErrors.value.start_date =
            'The start date must be on or before the end date.';
    if (
        (Date.parse(draft.end_date) - Date.parse(draft.start_date)) /
            86_400_000 >=
        93
    )
        filterErrors.value.start_date = 'Choose a range of up to 93 days.';
    if (Object.keys(filterErrors.value).length) return;
    stopOverview();
    closeDetail();
    filterGeneration++;
    filterCancel?.();
    const token = filterGeneration;
    filterLoading.value = true;
    errorMessage.value = '';
    router.get(
        '/seo',
        { ...draft },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onCancelToken: (cancel) => {
                if (token === filterGeneration)
                    filterCancel = () => cancel.cancel();
                else cancel.cancel();
            },
            onError: (errors) => {
                if (token === filterGeneration) filterErrors.value = errors;
            },
            onFinish: () => {
                if (token !== filterGeneration || disposed) return;
                filterLoading.value = false;
                filterCancel = null;
                reconcile();
            },
        },
    );
}
function applyPreset(days: number) {
    Object.assign(draft, seoRange(days, props.seo.max_end_date));
    applyFilters();
}
function visibilityChanged() {
    if (document.hidden) {
        stopOverview(false);
        stopDetail(false);
        return;
    }
    if (checkOnVisible) {
        if (pollStarted) poller.resume();
        else beginChecks();
    } else reconcile();
    if (detailOpen.value && detailCheckOnVisible) {
        if (detailPollStarted) detailPoller.resume();
        else void loadDetail();
    }
}
function stopForNavigation() {
    stopOverview();
    closeDetail();
}
function requestError(error: unknown, fallback: string): string {
    if (axios.isAxiosError(error)) {
        if ([401, 419].includes(error.response?.status ?? 0))
            return 'Your session expired. Reload WP Hub and sign in again.';
        if (error.response?.status === 409)
            return typeof error.response.data?.message === 'string'
                ? error.response.data.message
                : 'This overview changed. Close the page details and refresh the overview before trying again.';
        if (error.response?.status === 429)
            return 'Please wait a moment before requesting another analysis.';
        if (typeof error.response?.data?.message === 'string')
            return error.response.data.message;
    }
    return fallback;
}
function count(value: number | null | undefined, digits = 0) {
    return value == null
        ? '—'
        : value.toLocaleString('en-US', { maximumFractionDigits: digits });
}
function percent(value: number) {
    return `${(value * 100).toFixed(1)}%`;
}
function change(value: number | null) {
    return value === null ? '—' : `${value > 0 ? '+' : ''}${count(value)}`;
}
function shortDate(value: string) {
    return new Intl.DateTimeFormat('en-US', {
        timeZone: 'UTC',
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    }).format(new Date(`${value}T00:00:00Z`));
}
function timestamp(value: string) {
    return new Date(value).toLocaleString();
}
function categoryLabel(type: SeoOpportunityType) {
    return seoCategories.find((item) => item.key === type)?.label ?? type;
}
function statusLabel(report: SeoReport) {
    return {
        unlinked: 'Not linked',
        missing: 'Not analyzed',
        queued: 'Queued',
        running: 'Analyzing',
        ready: 'Available',
        failed: 'Needs attention',
    }[report.status];
}
watch([category, search], () => {
    pageNumber.value = 1;
});
watch(pageCount, (value) => {
    pageNumber.value = Math.min(pageNumber.value, value);
});
watch(querySearch, () => {
    queryPage.value = 1;
});
watch(queryPageCount, (value) => {
    queryPage.value = Math.min(queryPage.value, value);
});
watch(
    () => props.seo,
    (value) => {
        stopOverview();
        closeDetail();
        errorMessage.value = '';
        Object.assign(draft, {
            website_id: String(value.filters.website_id ?? ''),
            start_date: value.filters.start_date,
            end_date: value.filters.end_date,
        });
        try {
            reports.value = scopedReports(value.reports);
        } catch {
            reports.value = [];
            errorMessage.value =
                'The SEO analysis could not be read. Reload the page to try again.';
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
        if (!visit.async) stopForNavigation();
    });
    reconcile();
});
onUnmounted(() => {
    disposed = true;
    mounted = false;
    filterGeneration++;
    stopOverview();
    closeDetail();
    filterCancel?.();
    removeNavigation?.();
    document.removeEventListener('visibilitychange', visibilityChanged);
    window.removeEventListener('popstate', stopForNavigation);
});
</script>

<template>
    <Head title="SEO opportunities" />
    <AppLayout :breadcrumbs="[{ title: 'SEO opportunities', href: '/seo' }]">
        <main
            class="mx-auto flex w-full max-w-[1600px] min-w-0 flex-col gap-6 p-4 pb-10 sm:p-6 lg:p-8"
            :aria-busy="filterLoading"
        >
            <header class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p
                        class="mb-2 text-xs font-semibold tracking-widest text-indigo-600 uppercase dark:text-indigo-300"
                    >
                        Search performance · next steps
                    </p>
                    <h1
                        class="text-2xl font-semibold tracking-tight sm:text-3xl"
                    >
                        SEO opportunities
                    </h1>
                    <p class="mt-2 max-w-2xl text-sm text-muted-foreground">
                        Find pages and search queries worth reviewing, using
                        your Search Console data.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button variant="outline" as-child
                        ><Link href="/settings/traffic"
                            ><Settings2 class="size-4" />Manage sources</Link
                        ></Button
                    ><Button
                        :disabled="
                            !seo.connection.connected ||
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
                        analysis</Button
                    >
                </div>
            </header>
            <section
                v-if="!seo.connection.connected"
                class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-indigo-500/20 bg-indigo-500/5 p-5"
            >
                <div class="flex gap-3">
                    <Globe class="mt-1 size-5 shrink-0 text-indigo-600" />
                    <div>
                        <h2 class="font-semibold">
                            {{
                                seo.connection.email ||
                                seo.connection.reconnect_required
                                    ? 'Reconnect your reporting account'
                                    : 'Connect Search Console'
                            }}
                        </h2>
                        <p class="mt-1 max-w-2xl text-sm text-muted-foreground">
                            Use your Google reporting connection and link each
                            website’s Search Console property to prepare its
                            analysis.
                        </p>
                    </div>
                </div>
                <Button as-child
                    ><Link href="/settings/traffic">{{
                        seo.connection.email ||
                        seo.connection.reconnect_required
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
                v-else-if="waiting || refreshing"
                role="status"
                class="flex gap-2 rounded-lg border bg-muted/20 p-3 text-sm text-muted-foreground"
            >
                <LoaderCircle class="size-4 shrink-0 animate-spin" />Analysis is
                being prepared. This page will update when it is ready.
            </div>

            <section
                class="rounded-xl border bg-card p-4"
                aria-label="SEO filters"
            >
                <form
                    class="flex flex-wrap items-end gap-3"
                    @submit.prevent="applyFilters"
                >
                    <div class="min-w-40 flex-1">
                        <label for="seo-website" class="seo-label"
                            >Website</label
                        ><select
                            id="seo-website"
                            v-model="draft.website_id"
                            class="seo-input"
                            @change="applyFilters"
                        >
                            <option value="">All websites</option>
                            <option
                                v-for="site in seo.websites"
                                :key="site.id"
                                :value="String(site.id)"
                            >
                                {{ site.name }}
                            </option>
                        </select>
                    </div>
                    <div class="min-w-36 flex-1">
                        <label for="seo-from" class="seo-label">From</label
                        ><input
                            id="seo-from"
                            v-model="draft.start_date"
                            type="date"
                            required
                            :max="seo.max_end_date"
                            class="seo-input"
                            :aria-invalid="!!filterErrors.start_date"
                        />
                    </div>
                    <div class="min-w-36 flex-1">
                        <label for="seo-to" class="seo-label">Through</label
                        ><input
                            id="seo-to"
                            v-model="draft.end_date"
                            type="date"
                            required
                            :max="seo.max_end_date"
                            class="seo-input"
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
                    <button
                        v-for="days in [7, 28, 90]"
                        :key="days"
                        type="button"
                        class="rounded-md border px-3 py-1.5 text-xs font-medium transition-colors hover:bg-muted"
                        :class="
                            draft.start_date ===
                                seoRange(days, seo.max_end_date).start_date &&
                            draft.end_date === seo.max_end_date
                                ? 'border-indigo-500/40 bg-indigo-500/10 text-indigo-700 dark:text-indigo-300'
                                : 'text-muted-foreground'
                        "
                        @click="applyPreset(days)"
                    >
                        Last {{ days }} available days</button
                    ><span class="text-xs text-muted-foreground"
                        >Available through {{ shortDate(seo.max_end_date) }} ·
                        up to 93 days</span
                    >
                </div>
                <p
                    v-for="(message, field) in filterErrors"
                    :key="field"
                    role="alert"
                    class="mt-2 text-sm text-red-600 dark:text-red-400"
                >
                    {{ message }}
                </p>
                <p class="mt-3 text-xs leading-relaxed text-muted-foreground">
                    Google Search dates use Pacific time. The latest three days
                    are excluded to allow processing; only final data is
                    requested.
                </p>
            </section>

            <section
                class="overflow-hidden rounded-xl border bg-card"
                aria-label="Analysis summary"
            >
                <div
                    class="flex flex-wrap items-start justify-between gap-4 border-b bg-gradient-to-r from-indigo-500/5 to-transparent p-5"
                >
                    <div>
                        <p class="text-sm font-medium">{{ scopeName }}</p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            {{ rangeLabel
                            }}<span v-if="previousLabel">
                                · Compared with {{ previousLabel }}</span
                            >
                        </p>
                    </div>
                    <span
                        class="rounded-full border bg-background px-3 py-1 text-xs text-muted-foreground"
                        >{{ readyCount }} of {{ reports.length }} website
                        analyses available</span
                    >
                </div>
                <div
                    class="grid divide-y sm:grid-cols-3 sm:divide-x sm:divide-y-0"
                >
                    <div class="p-5">
                        <div
                            class="flex items-center gap-2 text-sm text-muted-foreground"
                        >
                            <Target class="size-4" />Review opportunities
                        </div>
                        <p class="mt-2 text-3xl font-semibold tracking-tight">
                            {{ readyCount ? count(counts.returned) : '—' }}
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            {{
                                counts.matched > counts.returned
                                    ? `${count(counts.returned)} shown of ${count(counts.matched)} matches`
                                    : 'Matched review rules in the returned data'
                            }}
                        </p>
                    </div>
                    <div class="p-5">
                        <p class="text-sm text-muted-foreground">
                            Affected pages
                        </p>
                        <p class="mt-2 text-3xl font-semibold tracking-tight">
                            {{ readyCount ? count(counts.pages) : '—' }}
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Unique pages within the shown opportunities
                        </p>
                    </div>
                    <div class="p-5">
                        <p class="text-sm text-muted-foreground">
                            Affected search queries
                        </p>
                        <p class="mt-2 text-3xl font-semibold tracking-tight">
                            {{ readyCount ? count(counts.queries) : '—' }}
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Unique queries within the shown opportunities
                        </p>
                    </div>
                </div>
            </section>

            <section
                class="grid gap-3 md:grid-cols-3"
                aria-label="Opportunity categories"
            >
                <button
                    v-for="item in categoryCards"
                    :key="item.key"
                    type="button"
                    class="rounded-xl border bg-card p-4 text-left transition-colors hover:border-indigo-400/60 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :class="
                        category === item.key
                            ? 'border-indigo-500/60 bg-indigo-500/5 ring-1 ring-indigo-500/20'
                            : ''
                    "
                    :aria-pressed="category === item.key"
                    @click="category = category === item.key ? 'all' : item.key"
                >
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-sm font-semibold">{{
                            item.label
                        }}</span
                        ><span
                            class="rounded-md bg-muted px-2.5 py-1 text-sm font-semibold tabular-nums"
                            >{{ readyCount ? count(item.count) : '—' }}</span
                        >
                    </div>
                    <p
                        class="mt-2 text-xs leading-relaxed text-muted-foreground"
                    >
                        {{ item.description }}
                    </p>
                </button>
            </section>

            <section
                class="min-w-0 overflow-hidden rounded-xl border bg-card"
                aria-label="SEO opportunities table"
            >
                <div
                    class="flex flex-wrap items-center justify-between gap-3 border-b p-4"
                >
                    <div>
                        <h2 class="font-semibold">
                            {{
                                category === 'all'
                                    ? 'Prioritized opportunities'
                                    : categoryLabel(category)
                            }}
                        </h2>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Review suggestions, not guaranteed improvements. A
                            page or query can match more than one rule.
                        </p>
                    </div>
                    <div
                        class="flex w-full flex-wrap items-center gap-2 sm:w-auto"
                    >
                        <Button
                            v-if="category !== 'all'"
                            variant="ghost"
                            size="sm"
                            @click="category = 'all'"
                            >All categories</Button
                        >
                        <div class="relative min-w-0 flex-1 sm:w-64">
                            <Search
                                class="pointer-events-none absolute top-2.5 left-3 size-4 text-muted-foreground"
                            /><input
                                v-model="search"
                                aria-label="Search opportunities"
                                placeholder="Search pages, queries, websites…"
                                class="seo-input seo-search-input"
                            />
                        </div>
                    </div>
                </div>
                <div v-if="rows.length" class="overflow-x-auto">
                    <table class="w-full min-w-[1060px] text-sm">
                        <thead
                            class="bg-muted/30 text-xs text-muted-foreground"
                        >
                            <tr>
                                <th class="seo-th w-[36%]">
                                    Page or search query
                                </th>
                                <th class="seo-th text-right">Clicks</th>
                                <th class="seo-th text-right">Impressions</th>
                                <th class="seo-th text-right">CTR</th>
                                <th class="seo-th text-right">Avg. position</th>
                                <th class="seo-th text-right">Click change</th>
                                <th class="seo-th w-[27%]">
                                    Suggested next step
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="row in rows"
                                :key="row.key"
                                class="align-top hover:bg-muted/15"
                            >
                                <td class="seo-td">
                                    <div
                                        class="mb-2 flex flex-wrap items-center gap-2"
                                    >
                                        <span
                                            class="seo-tag"
                                            :class="
                                                row.type === 'declining'
                                                    ? 'bg-rose-500/10 text-rose-700 dark:text-rose-300'
                                                    : row.type === 'low_ctr'
                                                      ? 'bg-amber-500/10 text-amber-700 dark:text-amber-300'
                                                      : 'bg-indigo-500/10 text-indigo-700 dark:text-indigo-300'
                                            "
                                            >{{ categoryLabel(row.type) }}</span
                                        ><span
                                            class="text-xs text-muted-foreground"
                                            >{{
                                                row.dimension === 'page'
                                                    ? 'Page'
                                                    : 'Query'
                                            }}
                                            · {{ row.website_name }}</span
                                        >
                                    </div>
                                    <p
                                        class="max-w-md font-medium [overflow-wrap:anywhere] break-words"
                                    >
                                        {{ row.name }}
                                    </p>
                                    <p
                                        class="mt-2 max-w-md text-xs leading-relaxed text-muted-foreground"
                                    >
                                        {{ row.reason }}
                                    </p>
                                    <button
                                        v-if="
                                            row.dimension === 'page' &&
                                            seoPageUrl(row.name)
                                        "
                                        type="button"
                                        class="mt-3 inline-flex items-center gap-1 text-xs font-medium text-indigo-700 hover:underline focus-visible:ring-2 focus-visible:ring-ring dark:text-indigo-300"
                                        :disabled="
                                            filterLoading ||
                                            !seo.connection.connected ||
                                            refreshing ||
                                            waiting
                                        "
                                        @click="openPage(row)"
                                    >
                                        <FileSearch class="size-3.5" />View page
                                        queries<ArrowRight class="size-3" />
                                    </button>
                                </td>
                                <td
                                    class="seo-td text-right font-medium tabular-nums"
                                >
                                    {{ count(row.current.clicks) }}
                                </td>
                                <td class="seo-td text-right tabular-nums">
                                    {{ count(row.current.impressions) }}
                                </td>
                                <td class="seo-td text-right tabular-nums">
                                    {{ percent(row.current.ctr) }}
                                </td>
                                <td class="seo-td text-right tabular-nums">
                                    {{ count(row.current.position, 1) }}
                                </td>
                                <td class="seo-td text-right">
                                    <p
                                        class="font-medium tabular-nums"
                                        :class="
                                            row.click_change === null
                                                ? 'text-muted-foreground'
                                                : row.click_change < 0
                                                  ? 'text-rose-600 dark:text-rose-400'
                                                  : row.click_change > 0
                                                    ? 'text-emerald-600 dark:text-emerald-400'
                                                    : ''
                                        "
                                    >
                                        {{ change(row.click_change) }}
                                    </p>
                                    <p
                                        class="mt-1 text-xs text-muted-foreground"
                                    >
                                        {{
                                            row.previous === null
                                                ? 'Previous unknown'
                                                : `from ${count(row.previous.clicks)} clicks`
                                        }}
                                    </p>
                                    <p
                                        v-if="row.decline_percent !== null"
                                        class="mt-1 text-xs text-rose-600 dark:text-rose-400"
                                    >
                                        {{ count(row.decline_percent, 1) }}%
                                        fewer
                                    </p>
                                </td>
                                <td
                                    class="seo-td text-xs leading-relaxed text-muted-foreground"
                                >
                                    {{ row.action }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div
                    v-else
                    class="flex flex-col items-center px-6 py-14 text-center"
                >
                    <LoaderCircle
                        v-if="refreshing || waiting || seoPending(reports)"
                        class="mb-4 size-8 animate-spin text-indigo-500"
                    /><Search
                        v-else
                        class="mb-4 size-8 text-muted-foreground/60"
                    />
                    <h3 class="font-semibold">
                        {{
                            refreshing || waiting || seoPending(reports)
                                ? 'Preparing your analysis'
                                : search || category !== 'all'
                                  ? 'No opportunities match these filters'
                                  : readyCount
                                    ? 'No review rules matched'
                                    : 'No analysis available yet'
                        }}
                    </h3>
                    <p
                        class="mt-2 max-w-lg text-sm leading-relaxed text-muted-foreground"
                    >
                        {{
                            refreshing || waiting || seoPending(reports)
                                ? 'Current and previous period comparisons are being prepared. Results will appear as analyses finish; use Refresh analysis to check again if needed.'
                                : search || category !== 'all'
                                  ? 'Try another search or view all categories.'
                                  : readyCount
                                    ? 'The returned Search Console rows did not meet these thresholds. This does not mean every page or query has been assessed.'
                                    : seo.connection.connected && anyMapped
                                      ? 'Use Refresh analysis to request results. Check the website coverage below for any issues that need attention.'
                                      : 'Connect a Search Console property for each website, then refresh the analysis. Website status below explains any missing results.'
                        }}
                    </p>
                    <Button
                        v-if="search || category !== 'all'"
                        variant="outline"
                        class="mt-4"
                        @click="
                            search = '';
                            category = 'all';
                        "
                        >Clear table filters</Button
                    >
                </div>
                <div
                    class="flex flex-wrap items-center justify-between gap-3 border-t px-4 py-3 text-xs text-muted-foreground"
                >
                    <span
                        >{{
                            filteredRows.length
                                ? `${count((pageNumber - 1) * pageSize + 1)}–${count(Math.min(pageNumber * pageSize, filteredRows.length))} of ${count(filteredRows.length)}`
                                : '0 shown'
                        }}
                        opportunities</span
                    >
                    <div class="flex items-center gap-2">
                        <Button
                            variant="outline"
                            size="icon"
                            class="size-8"
                            aria-label="Previous opportunities page"
                            :disabled="pageNumber === 1"
                            @click="pageNumber--"
                            ><ChevronLeft class="size-4" /></Button
                        ><span>Page {{ pageNumber }} of {{ pageCount }}</span
                        ><Button
                            variant="outline"
                            size="icon"
                            class="size-8"
                            aria-label="Next opportunities page"
                            :disabled="pageNumber === pageCount"
                            @click="pageNumber++"
                            ><ChevronRight class="size-4"
                        /></Button>
                    </div>
                </div>
            </section>

            <section
                class="rounded-xl border bg-card p-5"
                aria-label="Website analysis coverage"
            >
                <h2 class="font-semibold">Website coverage & data notes</h2>
                <p class="mt-1 text-xs leading-relaxed text-muted-foreground">
                    Up to 1,000 returned query rows and 1,000 page rows per
                    website and period; up to 500 prioritized opportunities per
                    website. Counts below describe returned rows, not all Google
                    searches.
                </p>
                <div class="mt-4 grid gap-3 lg:grid-cols-2">
                    <article
                        v-for="report in reports"
                        :key="report.website_id"
                        class="rounded-lg border p-4"
                    >
                        <div
                            class="flex flex-wrap items-center justify-between gap-2"
                        >
                            <h3 class="text-sm font-medium">
                                {{ report.website_name }}
                            </h3>
                            <span
                                class="seo-tag"
                                :class="
                                    report.status === 'ready'
                                        ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'
                                        : report.status === 'failed'
                                          ? 'bg-amber-500/10 text-amber-700 dark:text-amber-300'
                                          : 'bg-muted text-muted-foreground'
                                "
                                >{{ statusLabel(report) }}</span
                            >
                        </div>
                        <p class="mt-1 text-xs break-all text-muted-foreground">
                            {{
                                report.gsc_site_url ||
                                'No Search Console property linked'
                            }}
                        </p>
                        <p
                            v-if="report.error"
                            class="mt-2 text-xs leading-relaxed text-amber-700 dark:text-amber-300"
                        >
                            {{ report.error }}
                        </p>
                        <p
                            v-if="report.data"
                            class="mt-3 text-xs leading-relaxed text-muted-foreground"
                        >
                            Current:
                            {{ count(report.data.coverage.queries) }} queries ·
                            {{ count(report.data.coverage.pages) }}
                            pages<br />Previous:
                            {{ count(report.data.coverage.previous_queries) }}
                            queries ·
                            {{ count(report.data.coverage.previous_pages) }}
                            pages
                        </p>
                        <p
                            v-if="report.updated_at"
                            class="mt-2 text-xs text-muted-foreground"
                        >
                            {{
                                report.status === 'ready'
                                    ? 'Analyzed'
                                    : 'Last completed analysis'
                            }}
                            {{ timestamp(report.updated_at) }}
                        </p>
                        <Link
                            v-if="report.status === 'unlinked'"
                            href="/settings/traffic"
                            class="mt-2 inline-block text-xs font-medium text-indigo-700 hover:underline dark:text-indigo-300"
                            >Link Search Console<ArrowRight
                                class="ml-1 inline size-3"
                        /></Link>
                    </article>
                </div>
                <ul
                    class="mt-4 space-y-2 text-xs leading-relaxed text-muted-foreground"
                >
                    <li>
                        Average position describes the returned search
                        appearances; it is not a fixed ranking. Review the query
                        intent and page content before making changes.
                    </li>
                    <li>
                        Comparisons use the equal-length previous period. A
                        missing previous row is unknown, not zero clicks. Search
                        Console omits some queries for privacy and returns top
                        rows.
                    </li>
                    <li v-for="note in notes" :key="note">{{ note }}</li>
                </ul>
            </section>
        </main>

        <Dialog :open="detailOpen" @update:open="setDetailOpen"
            ><DialogContent
                class="flex max-h-[90dvh] w-[calc(100%-2rem)] max-w-5xl flex-col gap-0 overflow-hidden p-0 sm:max-w-5xl"
                ><DialogHeader class="shrink-0 border-b p-5 pr-12"
                    ><DialogTitle class="flex items-center gap-2"
                        ><FileSearch class="size-5 text-indigo-500" />Page
                        search queries</DialogTitle
                    ><DialogDescription
                        class="[overflow-wrap:anywhere] break-words"
                        >{{ selectedPage?.website_name }} ·
                        {{ selectedPage?.page_url }}</DialogDescription
                    >
                    <p class="text-xs text-muted-foreground">
                        {{ rangeLabel
                        }}<span v-if="detailReport?.data">
                            · Compared with
                            {{
                                shortDate(detailReport.data.previous_start_date)
                            }}
                            –
                            {{
                                shortDate(detailReport.data.previous_end_date)
                            }}</span
                        >
                    </p></DialogHeader
                >
                <div class="min-h-0 overflow-y-auto p-5">
                    <div
                        class="mb-4 flex flex-wrap items-center justify-between gap-3"
                    >
                        <p
                            class="max-w-xl text-xs leading-relaxed text-muted-foreground"
                        >
                            Queries returned for this exact page, ordered by
                            clicks. Previous rows may be unavailable; missing
                            comparisons are shown as unknown.
                        </p>
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="
                                detailBusy ||
                                detailWaiting ||
                                !seo.connection.connected
                            "
                            @click="refreshDetail"
                            ><LoaderCircle
                                v-if="detailBusy || detailWaiting"
                                class="size-4 animate-spin"
                            /><RefreshCw v-else class="size-4" />Refresh
                            analysis</Button
                        >
                    </div>
                    <div
                        v-if="detailError || detailReport?.error"
                        role="alert"
                        class="mb-4 rounded-lg border border-amber-500/30 bg-amber-500/5 p-3 text-sm"
                    >
                        {{ detailError || detailReport?.error }}
                    </div>
                    <div
                        v-if="detailBusy || detailWaiting"
                        role="status"
                        class="flex items-center justify-center gap-2 py-12 text-sm text-muted-foreground"
                    >
                        <LoaderCircle class="size-5 animate-spin" />Preparing
                        this page’s query analysis…
                    </div>
                    <template v-else-if="detailReport?.data"
                        ><div
                            class="mb-3 flex flex-wrap items-center justify-between gap-2"
                        >
                            <p class="text-xs text-muted-foreground">
                                {{ count(detailReport.data.coverage.queries) }}
                                current queries ·
                                {{
                                    count(
                                        detailReport.data.coverage
                                            .previous_queries,
                                    )
                                }}
                                previous queries returned
                            </p>
                            <input
                                v-model="querySearch"
                                aria-label="Search page queries"
                                placeholder="Find a query…"
                                class="seo-input w-full sm:w-56"
                            />
                        </div>
                        <div class="overflow-x-auto rounded-lg border">
                            <table class="w-full min-w-[660px] text-sm">
                                <thead
                                    class="bg-muted/30 text-xs text-muted-foreground"
                                >
                                    <tr>
                                        <th class="seo-th">Search query</th>
                                        <th class="seo-th text-right">
                                            Clicks
                                        </th>
                                        <th class="seo-th text-right">
                                            Impressions
                                        </th>
                                        <th class="seo-th text-right">CTR</th>
                                        <th class="seo-th text-right">
                                            Avg. position
                                        </th>
                                        <th class="seo-th text-right">
                                            Click change
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y">
                                    <tr
                                        v-for="row in visibleQueries"
                                        :key="row.name"
                                    >
                                        <td
                                            class="seo-td max-w-64 font-medium break-words"
                                        >
                                            {{ row.name }}
                                        </td>
                                        <td
                                            class="seo-td text-right tabular-nums"
                                        >
                                            {{ count(row.current.clicks) }}
                                        </td>
                                        <td
                                            class="seo-td text-right tabular-nums"
                                        >
                                            {{ count(row.current.impressions) }}
                                        </td>
                                        <td
                                            class="seo-td text-right tabular-nums"
                                        >
                                            {{ percent(row.current.ctr) }}
                                        </td>
                                        <td
                                            class="seo-td text-right tabular-nums"
                                        >
                                            {{ count(row.current.position, 1) }}
                                        </td>
                                        <td class="seo-td text-right">
                                            <p
                                                class="tabular-nums"
                                                :class="
                                                    row.click_change !== null &&
                                                    row.click_change < 0
                                                        ? 'text-rose-600 dark:text-rose-400'
                                                        : row.click_change !==
                                                                null &&
                                                            row.click_change > 0
                                                          ? 'text-emerald-600 dark:text-emerald-400'
                                                          : ''
                                                "
                                            >
                                                {{ change(row.click_change) }}
                                            </p>
                                            <p
                                                class="mt-1 text-xs text-muted-foreground"
                                            >
                                                {{
                                                    row.previous === null
                                                        ? 'Previous unknown'
                                                        : `from ${count(row.previous.clicks)} clicks`
                                                }}
                                            </p>
                                        </td>
                                    </tr>
                                    <tr v-if="!visibleQueries.length">
                                        <td
                                            colspan="6"
                                            class="p-8 text-center text-sm text-muted-foreground"
                                        >
                                            {{
                                                querySearch
                                                    ? 'No queries match your search.'
                                                    : 'No query rows were returned for this page and period. Privacy limits or low search activity can affect availability.'
                                            }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div
                            class="mt-3 flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground"
                        >
                            <span
                                >{{ count(detailQueries.length) }} queries shown
                                in this view</span
                            >
                            <div class="flex items-center gap-2">
                                <Button
                                    size="icon"
                                    variant="outline"
                                    class="size-8"
                                    aria-label="Previous queries page"
                                    :disabled="queryPage === 1"
                                    @click="queryPage--"
                                    ><ChevronLeft class="size-4" /></Button
                                ><span
                                    >{{ queryPage }} /
                                    {{ queryPageCount }}</span
                                ><Button
                                    size="icon"
                                    variant="outline"
                                    class="size-8"
                                    aria-label="Next queries page"
                                    :disabled="queryPage === queryPageCount"
                                    @click="queryPage++"
                                    ><ChevronRight class="size-4"
                                /></Button>
                            </div>
                        </div>
                        <ul
                            class="mt-5 space-y-2 text-xs leading-relaxed text-muted-foreground"
                        >
                            <li
                                v-for="note in detailReport.data.notes"
                                :key="note"
                            >
                                {{ note }}
                            </li>
                        </ul></template
                    >
                    <p
                        v-else-if="!detailError && !detailReport?.error"
                        class="py-10 text-center text-sm text-muted-foreground"
                    >
                        This page’s query analysis is not available yet. Use
                        Refresh analysis to request it.
                    </p>
                </div></DialogContent
            ></Dialog
        >
    </AppLayout>
</template>

<style scoped>
@reference "../../css/app.css";
.seo-label {
    @apply mb-1.5 block text-xs font-medium text-muted-foreground;
}
.seo-input {
    @apply h-9 w-full min-w-0 rounded-md border border-input bg-background px-3 text-sm shadow-xs outline-none focus-visible:ring-2 focus-visible:ring-ring;
}
.seo-search-input {
    @apply pl-9;
}
.seo-th {
    @apply px-4 py-3 text-left font-medium whitespace-nowrap;
}
.seo-th.text-right {
    @apply text-right;
}
.seo-td {
    @apply px-4 py-4;
}
.seo-tag {
    @apply inline-flex rounded-md px-2 py-1 text-[11px] font-medium;
}
</style>
