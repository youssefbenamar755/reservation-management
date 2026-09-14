<script setup lang="ts">
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import type { ActionHistoryProps, HistoryFilters } from '@/types/actionHistory';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowRight,
    BellRing,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    Clock3,
    History,
    Inbox,
    Mail,
    RefreshCw,
    Search,
    ShoppingCart,
    TriangleAlert,
    Webhook,
    X,
} from 'lucide-vue-next';
import { computed, onUnmounted, ref, watch } from 'vue';

const props = defineProps<ActionHistoryProps>();
const inputs = ref<HistoryFilters>({ ...props.filters });
const loading = ref(false);
const errors = ref<Record<string, string>>({});
let generation = 0;
let cancel: (() => void) | undefined;
watch(
    () => JSON.stringify(props.filters),
    () => {
        inputs.value = { ...props.filters };
    },
);
onUnmounted(() => {
    generation++;
    cancel?.();
});
const scopeName = computed(
    () =>
        props.websites.find((site) => site.id === props.filters.website_id)
            ?.name ?? 'All websites',
);
const dirty = computed(
    () => JSON.stringify(inputs.value) !== JSON.stringify(props.filters),
);
const hasFilters = computed(() =>
    Object.entries(props.filters).some(
        ([key, value]) =>
            key !== 'order_id' &&
            value !== null &&
            value !== '' &&
            value !== 'all',
    ),
);
const categories = [
    { value: 'all', label: 'All actions' },
    { value: 'orders', label: 'Order status' },
    { value: 'email', label: 'Document emails' },
    { value: 'webhooks', label: 'Webhook retries' },
    { value: 'alerts', label: 'Alert snooze / resume' },
];
const outcomes = [
    { value: 'all', label: 'All results' },
    { value: 'succeeded', label: 'Succeeded' },
    { value: 'failed', label: 'Failed' },
    { value: 'uncertain', label: 'Unconfirmed' },
    { value: 'pending', label: 'In progress' },
    { value: 'skipped', label: 'Skipped' },
];
const cards = computed(() => [
    {
        label: 'Matching actions',
        value: props.history.summary.total,
        icon: History,
        color: 'text-indigo-500',
    },
    {
        label: 'Succeeded',
        value: props.history.summary.succeeded,
        icon: CheckCircle2,
        color: 'text-emerald-500',
    },
    {
        label: 'Need review',
        value: props.history.summary.attention,
        icon: TriangleAlert,
        color: 'text-amber-500',
    },
    {
        label: 'In progress',
        value: props.history.summary.pending,
        icon: Clock3,
        color: 'text-sky-500',
    },
]);
function visit(filters: HistoryFilters, page = 1) {
    const key = ++generation;
    cancel?.();
    errors.value = {};
    loading.value = true;
    router.get(
        '/settings/action-history',
        { ...filters, page },
        {
            preserveState: true,
            preserveScroll: true,
            onCancelToken: (token) => {
                if (key === generation) cancel = () => token.cancel();
            },
            onError: (value) => {
                if (key === generation) errors.value = value;
            },
            onSuccess: () => {
                if (key === generation) inputs.value = { ...props.filters };
            },
            onFinish: () => {
                if (key === generation) {
                    loading.value = false;
                    cancel = undefined;
                }
            },
        },
    );
}
function clearFilters() {
    visit({
        website_id: props.orderContext?.website_id ?? null,
        actor_id: null,
        order_id: props.orderContext?.id ?? null,
        search: '',
        category: 'all',
        outcome: 'all',
        start_date: '',
        end_date: '',
    });
}
function displayDate(value: string | null) {
    if (!value) return 'Time unavailable';
    return new Intl.DateTimeFormat('en-GB', {
        timeZone: props.history.timezone,
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    }).format(new Date(value));
}
function statusLabel(value: string) {
    return value
        .split('-')
        .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
        .join(' ');
}
function resultLabel(value: string) {
    return outcomes.find((item) => item.value === value)?.label ?? 'Recorded';
}
function resultClass(value: string) {
    if (value === 'succeeded')
        return 'bg-emerald-50 text-emerald-700 ring-emerald-600/15 dark:bg-emerald-500/10 dark:text-emerald-300';
    if (value === 'failed')
        return 'bg-rose-50 text-rose-700 ring-rose-600/15 dark:bg-rose-500/10 dark:text-rose-300';
    if (value === 'uncertain')
        return 'bg-amber-50 text-amber-800 ring-amber-600/15 dark:bg-amber-500/10 dark:text-amber-300';
    if (value === 'pending')
        return 'bg-sky-50 text-sky-700 ring-sky-600/15 dark:bg-sky-500/10 dark:text-sky-300';
    return 'bg-muted text-muted-foreground ring-border';
}
function actionIcon(kind: string) {
    if (kind === 'order_status') return ShoppingCart;
    if (kind.startsWith('email_')) return Mail;
    if (kind === 'webhook_retry') return Webhook;
    return BellRing;
}
const inputClass =
    'h-10 w-full rounded-lg border border-input bg-background px-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-60';
</script>

<template>
    <Head title="Action history" />
    <AppLayout
        :breadcrumbs="[
            { title: 'Settings', href: '/settings/profile' },
            { title: 'Action history', href: '/settings/action-history' },
        ]"
    >
        <SettingsLayout wide>
            <div class="@container flex flex-col gap-6">
                <header
                    class="flex flex-wrap items-start justify-between gap-4"
                >
                    <div>
                        <div
                            class="mb-2 flex items-center gap-2 text-sm font-medium text-muted-foreground"
                        >
                            <History class="size-4" /> Activity & accountability
                        </div>
                        <h1 class="text-3xl font-semibold tracking-tight">
                            Action history
                        </h1>
                        <p
                            class="mt-2 max-w-2xl text-sm text-muted-foreground sm:text-base"
                        >
                            See who took action, what was requested, and how it
                            turned out.
                        </p>
                    </div>
                    <Button
                        variant="outline"
                        :disabled="loading"
                        @click="visit(props.filters, history.current_page)"
                        ><RefreshCw
                            class="size-4"
                            :class="{ 'animate-spin': loading }"
                        />
                        {{ loading ? 'Loading…' : 'Refresh history' }}</Button
                    >
                </header>

                <div
                    v-if="orderContext"
                    class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm dark:border-indigo-500/20 dark:bg-indigo-500/10"
                >
                    <span class="font-medium"
                        >Status and email history for order #{{
                            orderContext.number
                        }}</span
                    >
                    <div class="flex flex-wrap gap-4">
                        <Link
                            :href="`/orders/${orderContext.id}`"
                            class="font-medium underline underline-offset-4"
                            >Back to order</Link
                        ><Link
                            href="/settings/action-history"
                            class="inline-flex items-center gap-1 font-medium"
                            >All action history <X class="size-4"
                        /></Link>
                    </div>
                </div>

                <section
                    aria-label="History summary"
                    class="grid grid-cols-2 gap-3 @3xl:grid-cols-4"
                >
                    <div
                        v-for="card in cards"
                        :key="card.label"
                        class="rounded-xl border bg-card p-4 shadow-sm sm:p-5"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <p
                                class="text-sm font-medium text-muted-foreground"
                            >
                                {{ card.label }}
                            </p>
                            <component
                                :is="card.icon"
                                class="size-5 shrink-0"
                                :class="card.color"
                            />
                        </div>
                        <p class="mt-3 text-3xl font-semibold tabular-nums">
                            {{ card.value.toLocaleString('en-US') }}
                        </p>
                    </div>
                </section>

                <section
                    aria-label="Filter action history"
                    class="rounded-xl border bg-card p-4 sm:p-5"
                >
                    <form @submit.prevent="visit(inputs)">
                        <div
                            class="grid gap-4 @sm:grid-cols-2 @4xl:grid-cols-4"
                        >
                            <div class="grid gap-2 text-sm font-medium">
                                <label for="history-website">Website</label>
                                <select
                                    id="history-website"
                                    v-model="inputs.website_id"
                                    :disabled="!!orderContext || loading"
                                    :class="inputClass"
                                >
                                    <option :value="null">All websites</option>
                                    <option
                                        v-for="site in websites"
                                        :key="site.id"
                                        :value="site.id"
                                    >
                                        {{ site.name }}
                                    </option>
                                </select>
                            </div>
                            <div class="grid gap-2 text-sm font-medium">
                                <label for="history-person">Person</label>
                                <select
                                    id="history-person"
                                    v-model="inputs.actor_id"
                                    :disabled="loading"
                                    :class="inputClass"
                                >
                                    <option :value="null">All people</option>
                                    <option
                                        v-if="
                                            inputs.actor_id &&
                                            !people.some(
                                                (person) =>
                                                    person.id ===
                                                    inputs.actor_id,
                                            )
                                        "
                                        :value="inputs.actor_id"
                                    >
                                        Selected person (no activity in this
                                        view)
                                    </option>
                                    <option
                                        v-for="person in people"
                                        :key="person.id"
                                        :value="person.id"
                                    >
                                        {{ person.name }}
                                    </option>
                                </select>
                            </div>
                            <div class="grid gap-2 text-sm font-medium">
                                <label for="history-category">Action</label>
                                <select
                                    id="history-category"
                                    v-model="inputs.category"
                                    :disabled="loading"
                                    :class="inputClass"
                                >
                                    <option
                                        v-for="item in categories"
                                        :key="item.value"
                                        :value="item.value"
                                    >
                                        {{ item.label }}
                                    </option>
                                </select>
                            </div>
                            <div class="grid gap-2 text-sm font-medium">
                                <label for="history-outcome">Result</label>
                                <select
                                    id="history-outcome"
                                    v-model="inputs.outcome"
                                    :disabled="loading"
                                    :class="inputClass"
                                >
                                    <option
                                        v-for="item in outcomes"
                                        :key="item.value"
                                        :value="item.value"
                                    >
                                        {{ item.label }}
                                    </option>
                                </select>
                            </div>
                            <label
                                class="grid gap-2 text-sm font-medium"
                                for="history-start"
                                >From date<input
                                    id="history-start"
                                    v-model="inputs.start_date"
                                    type="date"
                                    :disabled="loading"
                                    :class="inputClass"
                            /></label>
                            <label
                                class="grid gap-2 text-sm font-medium"
                                for="history-end"
                                >Through date<input
                                    id="history-end"
                                    v-model="inputs.end_date"
                                    type="date"
                                    :min="inputs.start_date || undefined"
                                    :disabled="loading"
                                    :class="inputClass"
                            /></label>
                            <label
                                class="grid gap-2 text-sm font-medium @sm:col-span-2"
                                for="history-search"
                                >Order / webhook reference
                                <div class="relative">
                                    <Search
                                        class="pointer-events-none absolute top-3 left-3 size-4 text-muted-foreground"
                                    /><input
                                        id="history-search"
                                        v-model="inputs.search"
                                        type="search"
                                        inputmode="numeric"
                                        maxlength="21"
                                        placeholder="Search a number, e.g. 4038"
                                        :disabled="loading"
                                        :class="[inputClass, 'pl-9']"
                                    /></div
                            ></label>
                        </div>
                        <div
                            v-if="Object.keys(errors).length"
                            role="alert"
                            class="mt-4 rounded-lg bg-destructive/10 p-3 text-sm text-destructive"
                        >
                            <p v-for="(message, key) in errors" :key="key">
                                {{ message }}
                            </p>
                        </div>
                        <div class="mt-4 flex flex-wrap items-center gap-3">
                            <Button type="submit" :disabled="loading"
                                >Apply filters</Button
                            ><Button
                                v-if="hasFilters || dirty"
                                type="button"
                                variant="ghost"
                                :disabled="loading"
                                @click="clearFilters"
                                >Reset filters</Button
                            ><span
                                v-if="dirty && !loading"
                                class="text-xs text-amber-700 dark:text-amber-300"
                                >Apply to update results.</span
                            >
                            <p class="text-xs text-muted-foreground sm:ml-auto">
                                Dates and times shown in {{ history.timezone }}.
                            </p>
                        </div>
                    </form>
                </section>

                <section
                    aria-label="Recorded actions"
                    :aria-busy="loading"
                    class="overflow-hidden rounded-xl border bg-card"
                >
                    <div
                        class="flex flex-wrap items-start justify-between gap-3 border-b px-4 py-4 sm:px-6"
                    >
                        <div>
                            <h2 class="font-semibold">{{ scopeName }}</h2>
                            <p class="mt-1 text-sm text-muted-foreground">
                                {{
                                    history.total.toLocaleString('en-US')
                                }}
                                matching actions · Newest first
                            </p>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            Updated {{ displayDate(history.generated_at) }}
                        </p>
                    </div>
                    <div
                        v-if="!history.data.length"
                        class="px-6 py-16 text-center"
                    >
                        <Inbox
                            class="mx-auto mb-4 size-9 text-muted-foreground"
                        />
                        <h3 class="font-semibold">
                            No actions match this view
                        </h3>
                        <p
                            class="mx-auto mt-2 max-w-md text-sm text-muted-foreground"
                        >
                            Try another filter. New order-status requests,
                            document email sends, webhook retries, and alert
                            actions will appear here.
                        </p>
                    </div>
                    <ol
                        v-else
                        class="divide-y"
                        :class="{ 'opacity-60': loading }"
                    >
                        <li
                            v-for="entry in history.data"
                            :key="entry.id"
                            class="flex gap-3 p-4 sm:gap-4 sm:p-6"
                        >
                            <div
                                class="hidden size-10 shrink-0 items-center justify-center rounded-xl bg-muted text-muted-foreground sm:flex"
                            >
                                <component
                                    :is="actionIcon(entry.kind)"
                                    class="size-5"
                                />
                            </div>
                            <div class="min-w-0 flex-1">
                                <div
                                    class="flex flex-wrap items-center justify-between gap-2"
                                >
                                    <div
                                        class="flex flex-wrap items-center gap-2"
                                    >
                                        <h3 class="font-semibold">
                                            {{ entry.title
                                            }}<span
                                                v-if="entry.reference"
                                                class="ml-1 text-muted-foreground"
                                                >#{{ entry.reference }}</span
                                            >
                                        </h3>
                                        <span
                                            class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset"
                                            :class="resultClass(entry.outcome)"
                                            >{{
                                                resultLabel(entry.outcome)
                                            }}</span
                                        >
                                    </div>
                                    <time
                                        :datetime="
                                            entry.occurred_at ?? undefined
                                        "
                                        class="text-xs text-muted-foreground tabular-nums"
                                        >{{
                                            displayDate(entry.occurred_at)
                                        }}</time
                                    >
                                </div>
                                <p class="mt-2 text-sm">
                                    <span class="font-medium break-words">{{
                                        entry.actor.name
                                    }}</span
                                    ><span class="mx-2 text-muted-foreground"
                                        >·</span
                                    ><span
                                        class="break-words text-muted-foreground"
                                        >{{ entry.website.name }}</span
                                    ><span
                                        v-if="entry.attempt"
                                        class="ml-2 text-muted-foreground"
                                        >·
                                        {{
                                            entry.kind === 'email_record'
                                                ? 'Recorded attempts:'
                                                : 'Attempt'
                                        }}
                                        {{ entry.attempt }}</span
                                    >
                                </p>
                                <dl
                                    v-if="entry.changes.requested"
                                    class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2 rounded-lg bg-muted/50 px-3 py-2 text-xs"
                                >
                                    <div
                                        v-if="entry.changes.from"
                                        class="flex gap-1.5"
                                    >
                                        <dt class="text-muted-foreground">
                                            Observed before:
                                        </dt>
                                        <dd class="font-medium">
                                            {{
                                                statusLabel(entry.changes.from)
                                            }}
                                        </dd>
                                    </div>
                                    <div class="flex gap-1.5">
                                        <dt class="text-muted-foreground">
                                            Requested:
                                        </dt>
                                        <dd class="font-medium">
                                            {{
                                                statusLabel(
                                                    entry.changes.requested,
                                                )
                                            }}
                                        </dd>
                                    </div>
                                    <div
                                        v-if="entry.changes.confirmed"
                                        class="flex gap-1.5"
                                    >
                                        <dt class="text-muted-foreground">
                                            WooCommerce returned:
                                        </dt>
                                        <dd class="font-medium">
                                            {{
                                                statusLabel(
                                                    entry.changes.confirmed,
                                                )
                                            }}
                                        </dd>
                                    </div>
                                </dl>
                                <p
                                    class="mt-3 max-w-3xl text-sm leading-relaxed text-muted-foreground"
                                >
                                    {{ entry.message }}
                                </p>
                                <div
                                    class="mt-3 flex flex-wrap items-center justify-between gap-2"
                                >
                                    <p
                                        v-if="entry.finished_at"
                                        class="text-xs text-muted-foreground"
                                    >
                                        Result recorded
                                        {{ displayDate(entry.finished_at) }}
                                    </p>
                                    <Link
                                        v-if="entry.action_url"
                                        :href="entry.action_url"
                                        class="inline-flex items-center gap-1.5 text-sm font-medium hover:underline"
                                        >{{ entry.action_label
                                        }}<ArrowRight class="size-3.5"
                                    /></Link>
                                </div>
                            </div>
                        </li>
                    </ol>
                    <div
                        v-if="history.total"
                        class="flex flex-wrap items-center justify-between gap-3 border-t px-4 py-4 sm:px-6"
                    >
                        <p class="text-sm text-muted-foreground">
                            {{ history.from }}–{{ history.to }} of
                            {{ history.total.toLocaleString('en-US') }}
                        </p>
                        <div class="flex items-center gap-2">
                            <Button
                                variant="outline"
                                size="icon"
                                aria-label="Previous page"
                                :disabled="loading || history.current_page <= 1"
                                @click="
                                    visit(
                                        props.filters,
                                        history.current_page - 1,
                                    )
                                "
                                ><ChevronLeft class="size-4" /></Button
                            ><span class="px-1 text-sm"
                                >{{ history.current_page }} /
                                {{ history.last_page }}</span
                            ><Button
                                variant="outline"
                                size="icon"
                                aria-label="Next page"
                                :disabled="
                                    loading ||
                                    history.current_page >= history.last_page
                                "
                                @click="
                                    visit(
                                        props.filters,
                                        history.current_page + 1,
                                    )
                                "
                                ><ChevronRight class="size-4"
                            /></Button>
                        </div>
                    </div>
                </section>
                <p
                    class="max-w-4xl text-xs leading-relaxed text-muted-foreground"
                >
                    Order-status requests and individual email attempts are
                    recorded from this feature’s launch. Available earlier email
                    records and webhook retries are included. Email and alert
                    activity is visible only to the person who performed it;
                    order-status and webhook activity follows website access.
                    History refreshes when you apply filters or choose Refresh
                    history.
                </p>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
