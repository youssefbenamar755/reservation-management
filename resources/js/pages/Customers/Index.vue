<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowUp,
    ArrowUpDown,
    ChevronLeft,
    ChevronRight,
    Download,
    X,
} from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface Customer {
    email: string;
    orders_count: number;
    total_spent: number;
    average_order_value: number;
    websites: string[];
    country: string | null;
    first_order_at: string | null;
    last_order_at: string | null;
}

interface Props {
    customers?: {
        data: Customer[];
        links: any[];
        current_page?: number;
        last_page?: number;
        per_page?: number;
        total?: number;
        from?: number;
        to?: number;
        meta?: {
            current_page: number;
            last_page: number;
            per_page: number;
            total: number;
        };
    };
    websites: Array<{ id: number; name: string }>;
    countries: string[];
    filters: {
        search?: string | null;
        start_date?: string | null;
        end_date?: string | null;
        website_ids?: number[] | string;
        country?: string | null;
        min_spend?: string | number | null;
        payment_status?: string | null;
        sort_by?: string | null;
        sort_dir?: string | null;
    };
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Customers',
        href: '/customers',
    },
];

type FilterParams = Record<string, string | number[]>;

function normalizeFilters(filters: Props['filters']): FilterParams {
    const params: FilterParams = {};
    if (filters.search?.trim()) params.search = filters.search.trim();
    if (filters.start_date) params.start_date = filters.start_date;
    if (filters.end_date) params.end_date = filters.end_date;
    const ids = Array.isArray(filters.website_ids)
        ? filters.website_ids
        : (filters.website_ids || '').split(',');
    const websiteIds = [
        ...new Set(
            ids.map(Number).filter((id) => Number.isInteger(id) && id > 0),
        ),
    ].sort((a, b) => a - b);
    if (websiteIds.length) params.website_ids = websiteIds;
    if (filters.country) params.country = filters.country;
    if (
        filters.min_spend !== null &&
        filters.min_spend !== undefined &&
        filters.min_spend !== ''
    ) {
        params.min_spend = String(filters.min_spend);
    }
    if (filters.payment_status && filters.payment_status !== 'all')
        params.payment_status = filters.payment_status;
    params.sort_by = filters.sort_by || 'last_order_at';
    params.sort_dir = filters.sort_dir || 'desc';
    return params;
}

// Drafts change locally. Applied props are the source of truth for the table and export.
const startDate = ref('');
const search = ref('');
const endDate = ref('');
const selectedWebsiteIds = ref<number[]>([]);
const selectedCountry = ref('');
const minSpend = ref<string | number>('');
const paymentStatus = ref('all');
const isLoading = ref(false);
const filterError = ref('');
const appliedFilters = computed(() => normalizeFilters(props.filters));
const sortBy = computed(() => String(appliedFilters.value.sort_by));
const sortDir = computed(() => String(appliedFilters.value.sort_dir));

function setDraftFilters(filters: Props['filters']) {
    const normalized = normalizeFilters(filters);
    search.value = filters.search || '';
    startDate.value = filters.start_date || '';
    endDate.value = filters.end_date || '';
    selectedWebsiteIds.value = Array.isArray(normalized.website_ids)
        ? [...normalized.website_ids]
        : [];
    selectedCountry.value = filters.country || '';
    minSpend.value = filters.min_spend ?? '';
    paymentStatus.value = filters.payment_status || 'all';
}

watch(
    () => JSON.stringify(appliedFilters.value),
    () => {
        setDraftFilters(props.filters);
        filterError.value = '';
    },
    { immediate: true },
);

const draftFilters = computed(() =>
    normalizeFilters({
        search: search.value,
        start_date: startDate.value,
        end_date: endDate.value,
        website_ids: selectedWebsiteIds.value,
        country: selectedCountry.value,
        min_spend: minSpend.value,
        payment_status: paymentStatus.value,
        sort_by: sortBy.value,
        sort_dir: sortDir.value,
    }),
);
const hasUnappliedFilters = computed(
    () =>
        JSON.stringify(draftFilters.value) !==
        JSON.stringify(appliedFilters.value),
);
const resultsActionsDisabled = computed(
    () => isLoading.value || hasUnappliedFilters.value,
);
const totalCustomers = computed(
    () => props.customers?.total ?? props.customers?.meta?.total ?? 0,
);
function queryString(params: FilterParams) {
    const query = new URLSearchParams();
    for (const [key, value] of Object.entries(params)) {
        if (Array.isArray(value))
            value.forEach((id) => query.append(`${key}[]`, String(id)));
        else query.set(key, value);
    }
    return query.toString();
}
const exportUrl = computed(
    () => `/customers/export?${queryString(appliedFilters.value)}`,
);
function customerUrl(email: string) {
    return `/customers/${encodeURIComponent(email)}?${queryString({
        ...appliedFilters.value,
        page: String(
            props.customers?.current_page ??
                props.customers?.meta?.current_page ??
                1,
        ),
        per_page: String(
            props.customers?.per_page ?? props.customers?.meta?.per_page ?? 15,
        ),
    })}`;
}
const websiteScope = computed(() => {
    const ids = appliedFilters.value.website_ids;
    return Array.isArray(ids) && ids.length
        ? ids
              .map(
                  (id) =>
                      props.websites.find((website) => website.id === id)
                          ?.name || `Website #${id}`,
              )
              .join(', ')
        : 'All websites';
});
const appliedFilterSummary = computed(() => {
    const filters = appliedFilters.value;
    const dates =
        filters.start_date && filters.end_date
            ? `${filters.start_date} to ${filters.end_date}`
            : filters.start_date
              ? `From ${filters.start_date}`
              : filters.end_date
                ? `Until ${filters.end_date}`
                : 'All dates';
    return [
        ...(filters.search ? [`Search: ${filters.search}`] : []),
        dates,
        filters.country ? `Country: ${filters.country}` : 'All countries',
        filters.payment_status === 'paid'
            ? 'Paid orders'
            : filters.payment_status === 'pending'
              ? 'Pending orders'
              : 'All order statuses',
        ...(filters.min_spend !== undefined
            ? [`Minimum spend: ${formatCurrency(Number(filters.min_spend))}`]
            : []),
    ].join(' · ');
});

function visitCustomers(params: FilterParams) {
    if (isLoading.value) return;
    isLoading.value = true;
    filterError.value = '';
    router.get('/customers', params, {
        preserveState: true,
        preserveScroll: true,
        onError: (errors) => {
            filterError.value =
                Object.values(errors).filter(Boolean).join(' ') ||
                'The filters could not be applied. Check the values and try again.';
        },
        onFinish: () => {
            isLoading.value = false;
        },
    });
}

function applyFilters() {
    visitCustomers(draftFilters.value);
}

function resetFilters() {
    if (isLoading.value) return;
    setDraftFilters({});
    visitCustomers(normalizeFilters({}));
}

function addWebsite(event: Event) {
    const select = event.target as HTMLSelectElement;
    if (select.value === 'all') selectedWebsiteIds.value = [];
    else {
        const id = Number(select.value);
        if (id > 0 && !selectedWebsiteIds.value.includes(id))
            selectedWebsiteIds.value.push(id);
    }
    select.value = '';
}

function removeWebsite(websiteId: number) {
    selectedWebsiteIds.value = selectedWebsiteIds.value.filter(
        (id) => id !== websiteId,
    );
}

function toggleSort(column: string) {
    if (resultsActionsDisabled.value) return;
    visitCustomers({
        ...appliedFilters.value,
        sort_by: column,
        sort_dir:
            sortBy.value === column && sortDir.value === 'desc'
                ? 'asc'
                : 'desc',
    });
}

function getSortIcon(column: string) {
    if (sortBy.value !== column) {
        return ArrowUpDown;
    }
    return sortDir.value === 'asc' ? ArrowUp : ArrowDown;
}

function formatCurrency(amount: number): string {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(amount);
}

function formatDate(dateString: string | null): string {
    if (!dateString) return '—';
    const date = new Date(dateString);
    return new Intl.DateTimeFormat('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    }).format(date);
}

function goToPage(url: string | null) {
    if (!url || resultsActionsDisabled.value) return;
    isLoading.value = true;
    router.visit(url, {
        preserveState: true,
        preserveScroll: true,
        onFinish: () => {
            isLoading.value = false;
        },
    });
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
</script>

<template>
    <Head title="Customers" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full min-w-0 flex-1 flex-col gap-4 rounded-xl p-4">
            <div
                class="flex min-w-0 flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
            >
                <div class="min-w-0">
                    <h1 class="text-xl font-semibold">Customers</h1>
                    <p
                        id="customer-export-scope"
                        class="mt-1 text-sm break-words text-muted-foreground"
                    >
                        {{ totalCustomers.toLocaleString() }} matching
                        {{ totalCustomers === 1 ? 'customer' : 'customers' }} ·
                        {{ websiteScope }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{ appliedFilterSummary }}
                    </p>
                </div>
                <div class="shrink-0 space-y-1 sm:text-right">
                    <Button
                        v-if="resultsActionsDisabled"
                        disabled
                        class="w-full gap-2 sm:w-auto"
                    >
                        <Download class="h-4 w-4" /> Export CSV
                    </Button>
                    <Button v-else as-child class="w-full gap-2 sm:w-auto">
                        <a
                            :href="exportUrl"
                            aria-describedby="customer-export-scope customer-export-note"
                        >
                            <Download class="h-4 w-4" /> Export CSV
                        </a>
                    </Button>
                    <p
                        id="customer-export-note"
                        class="text-xs text-muted-foreground"
                    >
                        Includes all matching customers, across every page.
                    </p>
                </div>
            </div>

            <!-- FILTERS -->
            <form
                class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border dark:[color-scheme:dark]"
                @submit.prevent="applyFilters"
            >
                <fieldset
                    :disabled="isLoading"
                    class="grid min-w-0 grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-6"
                >
                    <legend class="sr-only">Customer filters</legend>
                    <div class="min-w-0 sm:col-span-2 xl:col-span-6">
                        <label
                            for="customer-search"
                            class="mb-1 block text-xs font-medium text-muted-foreground"
                            >Search customers</label
                        >
                        <input
                            id="customer-search"
                            v-model="search"
                            type="search"
                            maxlength="200"
                            placeholder="Name or email"
                            class="w-full min-w-0 rounded-md border bg-background px-3 py-2 text-sm"
                        />
                    </div>
                    <div class="min-w-0 sm:col-span-2">
                        <div
                            class="grid min-w-0 grid-cols-1 gap-2 min-[420px]:grid-cols-2"
                        >
                            <div class="min-w-0">
                                <label
                                    for="customer-start-date"
                                    class="mb-1 block text-xs font-medium text-muted-foreground"
                                    >Start date</label
                                >
                                <input
                                    id="customer-start-date"
                                    v-model="startDate"
                                    type="date"
                                    :max="endDate || undefined"
                                    class="w-full min-w-0 rounded-md border bg-background px-3 py-2 text-sm"
                                />
                            </div>
                            <div class="min-w-0">
                                <label
                                    for="customer-end-date"
                                    class="mb-1 block text-xs font-medium text-muted-foreground"
                                    >End date</label
                                >
                                <input
                                    id="customer-end-date"
                                    v-model="endDate"
                                    type="date"
                                    :min="startDate || undefined"
                                    class="w-full min-w-0 rounded-md border bg-background px-3 py-2 text-sm"
                                />
                            </div>
                        </div>
                    </div>

                    <div class="min-w-0">
                        <label
                            for="customer-websites"
                            class="mb-1 block text-xs font-medium text-muted-foreground"
                        >
                            Websites
                        </label>
                        <select
                            id="customer-websites"
                            class="w-full rounded-md border bg-background px-3 py-2 text-sm"
                            value=""
                            @change="addWebsite"
                        >
                            <option value="" disabled>
                                {{
                                    selectedWebsiteIds.length
                                        ? 'Add a website'
                                        : 'All websites'
                                }}
                            </option>
                            <option value="all">All websites</option>
                            <option
                                v-for="w in websites"
                                :key="w.id"
                                :value="w.id"
                                :disabled="selectedWebsiteIds.includes(w.id)"
                            >
                                {{ w.name }}
                            </option>
                        </select>
                        <div
                            v-if="selectedWebsiteIds.length > 0"
                            class="mt-2 flex flex-wrap gap-1"
                        >
                            <button
                                v-for="id in selectedWebsiteIds"
                                :key="id"
                                type="button"
                                class="inline-flex max-w-full items-center gap-1 rounded-md bg-secondary px-2 py-1 text-xs text-secondary-foreground hover:bg-secondary/80 focus-visible:outline focus-visible:outline-2"
                                :aria-label="`Remove ${websites.find((w) => w.id === id)?.name || `website ${id}`}`"
                                @click="removeWebsite(id)"
                            >
                                <span class="min-w-0 break-words">{{
                                    websites.find((w) => w.id === id)?.name ||
                                    `Website #${id}`
                                }}</span>
                                <X
                                    class="h-3 w-3 shrink-0"
                                    aria-hidden="true"
                                />
                            </button>
                        </div>
                    </div>

                    <div class="min-w-0">
                        <label
                            for="customer-country"
                            class="mb-1 block text-xs font-medium text-muted-foreground"
                        >
                            Country
                        </label>
                        <select
                            id="customer-country"
                            v-model="selectedCountry"
                            class="w-full rounded-md border bg-background px-3 py-2 text-sm"
                        >
                            <option value="">All Countries</option>
                            <option v-for="c in countries" :key="c" :value="c">
                                {{ c }}
                            </option>
                        </select>
                    </div>

                    <div class="min-w-0">
                        <label
                            for="customer-min-spend"
                            class="mb-1 block text-xs font-medium text-muted-foreground"
                        >
                            Minimum spend
                        </label>
                        <input
                            id="customer-min-spend"
                            v-model="minSpend"
                            type="number"
                            min="0"
                            step="0.01"
                            class="w-full rounded-md border bg-background px-3 py-2 text-sm"
                            placeholder="0.00"
                        />
                    </div>

                    <div class="min-w-0">
                        <label
                            for="customer-payment-status"
                            class="mb-1 block text-xs font-medium text-muted-foreground"
                        >
                            Order status
                        </label>
                        <select
                            id="customer-payment-status"
                            v-model="paymentStatus"
                            class="w-full rounded-md border bg-background px-3 py-2 text-sm"
                        >
                            <option value="all">All</option>
                            <option value="paid">Paid</option>
                            <option value="pending">Pending</option>
                        </select>
                    </div>
                </fieldset>
                <div
                    class="mt-4 flex flex-wrap items-center gap-2 border-t pt-4"
                >
                    <Button
                        type="submit"
                        :disabled="isLoading || !hasUnappliedFilters"
                        >{{ isLoading ? 'Updating…' : 'Apply filters' }}</Button
                    >
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="isLoading"
                        @click="resetFilters"
                        >Reset filters</Button
                    >
                    <p
                        v-if="hasUnappliedFilters && !isLoading"
                        class="text-xs text-muted-foreground"
                        role="status"
                    >
                        Apply your changes before exporting, sorting, or
                        changing pages.
                    </p>
                    <p
                        v-else-if="isLoading"
                        class="text-xs text-muted-foreground"
                        role="status"
                    >
                        Updating customers…
                    </p>
                    <p v-else class="text-xs text-muted-foreground">
                        Select one or more websites, or choose All websites,
                        then apply.
                    </p>
                </div>
                <p
                    v-if="filterError"
                    class="mt-2 text-sm text-destructive"
                    role="alert"
                >
                    {{ filterError }}
                </p>
            </form>

            <!-- CUSTOMERS TABLE -->
            <div
                class="relative min-w-0 flex-1 overflow-hidden rounded-lg border bg-card shadow-sm"
                :aria-busy="isLoading"
            >
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse text-sm">
                        <thead>
                            <tr class="border-b bg-muted/50">
                                <th
                                    class="px-6 py-4 text-left font-semibold text-foreground"
                                >
                                    Customer Email
                                </th>
                                <th
                                    class="px-6 py-4 text-left font-semibold text-foreground"
                                    :aria-sort="
                                        sortBy === 'orders_count'
                                            ? sortDir === 'asc'
                                                ? 'ascending'
                                                : 'descending'
                                            : 'none'
                                    "
                                >
                                    <button
                                        type="button"
                                        class="flex items-center gap-2 whitespace-nowrap hover:text-primary disabled:cursor-not-allowed disabled:opacity-60"
                                        :disabled="resultsActionsDisabled"
                                        @click="toggleSort('orders_count')"
                                    >
                                        Total Orders
                                        <component
                                            :is="getSortIcon('orders_count')"
                                            class="h-4 w-4"
                                        />
                                    </button>
                                </th>
                                <th
                                    class="px-6 py-4 text-left font-semibold text-foreground"
                                    :aria-sort="
                                        sortBy === 'total_spent'
                                            ? sortDir === 'asc'
                                                ? 'ascending'
                                                : 'descending'
                                            : 'none'
                                    "
                                >
                                    <button
                                        type="button"
                                        class="flex items-center gap-2 whitespace-nowrap hover:text-primary disabled:cursor-not-allowed disabled:opacity-60"
                                        :disabled="resultsActionsDisabled"
                                        @click="toggleSort('total_spent')"
                                    >
                                        Total Spend
                                        <component
                                            :is="getSortIcon('total_spent')"
                                            class="h-4 w-4"
                                        />
                                    </button>
                                </th>
                                <th
                                    class="px-6 py-4 text-left font-semibold text-foreground"
                                >
                                    AOV
                                    <span
                                        class="mt-1 block text-xs font-normal text-muted-foreground"
                                        >Completed orders</span
                                    >
                                </th>
                                <th
                                    class="px-6 py-4 text-left font-semibold text-foreground"
                                >
                                    Website(s)
                                </th>
                                <th
                                    class="px-6 py-4 text-left font-semibold text-foreground"
                                >
                                    Country
                                </th>
                                <th
                                    class="px-6 py-4 text-left font-semibold whitespace-nowrap text-foreground"
                                >
                                    First Order
                                </th>
                                <th
                                    class="px-6 py-4 text-left font-semibold whitespace-nowrap text-foreground"
                                    :aria-sort="
                                        sortBy === 'last_order_at'
                                            ? sortDir === 'asc'
                                                ? 'ascending'
                                                : 'descending'
                                            : 'none'
                                    "
                                >
                                    <button
                                        type="button"
                                        class="flex items-center gap-2 whitespace-nowrap hover:text-primary disabled:cursor-not-allowed disabled:opacity-60"
                                        :disabled="resultsActionsDisabled"
                                        @click="toggleSort('last_order_at')"
                                    >
                                        Last Order
                                        <component
                                            :is="getSortIcon('last_order_at')"
                                            class="h-4 w-4"
                                        />
                                    </button>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="customer in customers?.data || []"
                                :key="customer.email"
                                class="cursor-pointer border-b transition-colors hover:bg-muted/50"
                                @click="
                                    router.visit(customerUrl(customer.email))
                                "
                            >
                                <td class="px-6 py-4">
                                    <Link
                                        :href="customerUrl(customer.email)"
                                        class="font-medium text-primary hover:underline"
                                        @click.stop
                                    >
                                        {{ customer.email }}
                                    </Link>
                                </td>
                                <td class="px-6 py-4">
                                    {{ customer.orders_count }}
                                </td>
                                <td class="px-6 py-4">
                                    {{ formatCurrency(customer.total_spent) }}
                                </td>
                                <td class="px-6 py-4">
                                    {{
                                        formatCurrency(
                                            customer.average_order_value,
                                        )
                                    }}
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex flex-wrap gap-1">
                                        <Badge
                                            v-for="website in customer.websites"
                                            :key="website"
                                            variant="outline"
                                        >
                                            {{ website }}
                                        </Badge>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    {{ customer.country || '—' }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    {{ formatDate(customer.first_order_at) }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    {{ formatDate(customer.last_order_at) }}
                                </td>
                            </tr>
                            <tr
                                v-if="
                                    !customers?.data ||
                                    customers.data.length === 0
                                "
                            >
                                <td
                                    colspan="8"
                                    class="px-6 py-8 text-center text-muted-foreground"
                                >
                                    No customers found
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- PAGINATION -->
                <div
                    v-if="
                        customers &&
                        (customers.total || (customers.meta?.total ?? 0)) > 0
                    "
                    class="flex flex-col gap-4 border-t bg-muted/30 px-6 py-4 sm:flex-row sm:items-center sm:justify-between"
                >
                    <!-- Pagination Info -->
                    <div
                        v-if="customers.total || customers.meta?.total"
                        class="text-sm text-muted-foreground"
                    >
                        Showing
                        <span class="font-semibold text-foreground">
                            {{
                                customers.from ||
                                (customers.meta?.current_page
                                    ? (customers.meta.current_page - 1) *
                                          (customers.meta.per_page || 15) +
                                      1
                                    : 1)
                            }}
                        </span>
                        to
                        <span class="font-semibold text-foreground">
                            {{
                                customers.to ||
                                (customers.meta?.current_page
                                    ? Math.min(
                                          customers.meta.current_page *
                                              (customers.meta.per_page || 15),
                                          customers.meta.total ||
                                              customers.total ||
                                              0,
                                      )
                                    : 0)
                            }}
                        </span>
                        of
                        <span class="font-semibold text-foreground">{{
                            customers.total || customers.meta?.total || 0
                        }}</span>
                        customers
                        <span
                            v-if="
                                (customers.last_page ||
                                    customers.meta?.last_page ||
                                    1) > 1
                            "
                            class="ml-2"
                        >
                            (Page
                            {{
                                customers.current_page ||
                                customers.meta?.current_page ||
                                1
                            }}
                            of
                            {{
                                customers.last_page ||
                                customers.meta?.last_page ||
                                1
                            }})
                        </span>
                    </div>

                    <!-- Pagination Controls -->
                    <div
                        v-if="customers.links && customers.links.length > 1"
                        class="flex max-w-full items-center gap-2 overflow-x-auto"
                    >
                        <!-- Previous Button -->
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="
                                resultsActionsDisabled ||
                                !customers.links[0]?.url ||
                                (customers.current_page ||
                                    customers.meta?.current_page ||
                                    1) === 1
                            "
                            @click="goToPage(customers.links[0]?.url)"
                            class="gap-1"
                        >
                            <ChevronLeft class="h-4 w-4" />
                            <span class="hidden sm:inline">Previous</span>
                        </Button>

                        <!-- Page Numbers -->
                        <div class="flex items-center gap-1">
                            <template
                                v-for="(link, index) in customers.links"
                                :key="index"
                            >
                                <Button
                                    v-if="
                                        link.label &&
                                        Number(index) > 0 &&
                                        Number(index) <
                                            customers.links.length - 1
                                    "
                                    variant="outline"
                                    size="sm"
                                    :disabled="
                                        resultsActionsDisabled ||
                                        !link.url ||
                                        link.active
                                    "
                                    :class="{
                                        'bg-primary text-primary-foreground hover:bg-primary/90':
                                            link.active,
                                        'pointer-events-none opacity-50':
                                            !link.url,
                                        'min-w-[2.5rem]': true,
                                    }"
                                    @click="goToPage(link.url)"
                                >
                                    <span>{{
                                        decodeHtmlEntities(link.label)
                                    }}</span>
                                </Button>
                                <span
                                    v-else-if="
                                        link.label === '...' &&
                                        Number(index) > 0 &&
                                        Number(index) <
                                            customers.links.length - 1
                                    "
                                    class="px-2 py-1 text-muted-foreground"
                                >
                                    ...
                                </span>
                            </template>
                        </div>

                        <!-- Next Button -->
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="
                                resultsActionsDisabled ||
                                !customers.links[customers.links.length - 1]
                                    ?.url ||
                                (customers.current_page ||
                                    customers.meta?.current_page ||
                                    1) ===
                                    (customers.last_page ||
                                        customers.meta?.last_page ||
                                        1)
                            "
                            @click="
                                goToPage(
                                    customers.links[customers.links.length - 1]
                                        ?.url,
                                )
                            "
                            class="gap-1"
                        >
                            <span class="hidden sm:inline">Next</span>
                            <ChevronRight class="h-4 w-4" />
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
