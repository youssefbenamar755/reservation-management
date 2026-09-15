<script setup lang="ts">
import MarketingLayout from '@/components/MarketingLayout.vue';
import MarketingPagination from '@/components/MarketingPagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    marketingDate,
    marketingSegments,
    marketingSources,
    marketingStatus,
    marketingTone,
} from '@/lib/marketing';
import type {
    MarketingCommon,
    MarketingContact,
    MarketingPage,
} from '@/types/marketing';
import { router, useForm } from '@inertiajs/vue3';
import {
    Download,
    Plus,
    RefreshCw,
    Search,
    Upload,
    Users,
} from 'lucide-vue-next';
import { computed, reactive, ref, watch } from 'vue';
const props = defineProps<
    MarketingCommon & {
        contacts: MarketingPage<MarketingContact>;
        summary: Record<string, number>;
        websiteSummary: Record<
            number,
            {
                total: number;
                forms: number;
                orders: number;
                both: number;
                subscribed: number;
            }
        >;
        filters: {
            website_id: number | null;
            search?: string;
            locale?: string;
            segment?: string;
            status?: string;
            source?: string;
        };
    }
>();
const draft = reactive({
    search: props.filters.search || '',
    locale: props.filters.locale || '',
    segment: props.filters.segment || 'all',
    status: props.filters.status || '',
    source: props.filters.source || 'all',
});
const hasContacts = computed(() =>
    props.websites.some(
        (site) =>
            (!props.filters.website_id ||
                site.id === props.filters.website_id) &&
            Number(props.websiteSummary[site.id]?.total || 0) > 0,
    ),
);
watch(
    () => props.filters,
    (f) =>
        Object.assign(draft, {
            search: f.search || '',
            locale: f.locale || '',
            segment: f.segment || 'all',
            status: f.status || '',
            source: f.source || 'all',
        }),
);
const panel = ref('');
const selected = ref<MarketingContact | null>(null);
const form = useForm({
    email: '',
    name: '',
    locale: '',
    status: 'unknown',
    consent_source: '',
    consented_at: '',
    confirm_consent: false,
});
const importForm = useForm<{ file: File | null; confirm_consent: boolean }>({
    file: null,
    confirm_consent: false,
});
const discoverForm = useForm({});
const dateInput = (value: string | null) => {
    if (!value) return '';
    const d = new Date(value);
    return new Date(d.getTime() - d.getTimezoneOffset() * 60000)
        .toISOString()
        .slice(0, 16);
};
const edit = (contact?: MarketingContact) => {
    selected.value = contact || null;
    form.reset();
    form.clearErrors();
    if (contact)
        Object.assign(form, {
            email: contact.email,
            name: contact.name || '',
            locale: contact.locale || '',
            status: contact.status,
            consent_source: contact.consent_source || '',
            consented_at: dateInput(contact.consented_at),
            confirm_consent: false,
        });
    panel.value = 'contact';
};
const save = () =>
    form
        .transform((data) => ({
            ...data,
            consented_at: data.consented_at
                ? new Date(data.consented_at).toISOString()
                : null,
        }))
        .post(
            '/marketing/websites/' +
                (selected.value?.website_id || props.filters.website_id) +
                '/contacts',
            {
                preserveScroll: true,
                onSuccess: () => {
                    panel.value = '';
                    form.reset();
                },
            },
        );
const apply = () =>
    router.get(
        '/marketing/audience',
        { website_id: props.filters.website_id, ...draft },
        { preserveScroll: true, preserveState: true },
    );
const websiteName = (id: number) =>
    props.websites.find((site) => site.id === id)?.name || 'Website';
const chooseWebsite = (id: number | null) => {
    panel.value = '';
    router.get(
        '/marketing/audience',
        { ...draft, website_id: id },
        { preserveScroll: true },
    );
};
const refreshContacts = () =>
    discoverForm
        .transform(() => ({ website_id: props.filters.website_id }))
        .post('/marketing/audience/discover', { preserveScroll: true });
const csvExample =
    'data:text/csv;charset=utf-8,' +
    encodeURIComponent(
        'email,name,locale,status,consent_source,consented_at\ncustomer@example.com,Example Customer,fr,unknown,,\n',
    );
</script>
<template>
    <MarketingLayout
        title="Marketing audience"
        description="Know which website each contact belongs to and how they found you."
        active="Audience"
        :connected="connected"
    >
        <div class="grid grid-cols-2 gap-4 xl:grid-cols-4">
            <div
                v-for="item in [
                    { key: 'total', label: 'Matching contacts' },
                    { key: 'subscribed', label: 'Permission recorded' },
                    { key: 'unknown', label: 'No permission' },
                    { key: 'unsubscribed', label: 'Unsubscribed' },
                ]"
                :key="item.key"
                class="rounded-xl border bg-card p-5"
            >
                <p class="text-sm text-muted-foreground">{{ item.label }}</p>
                <p class="mt-3 text-3xl font-semibold tabular-nums">
                    {{ summary[item.key] || 0 }}
                </p>
            </div>
        </div>
        <section aria-label="Audience by website" class="space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="font-semibold">Audience by website</h2>
                <Button
                    v-if="filters.website_id"
                    variant="ghost"
                    size="sm"
                    @click="chooseWebsite(null)"
                    >Show all websites</Button
                >
            </div>
            <p class="text-xs text-muted-foreground">
                Website totals before filters. Each email appears once per
                website; contacts found in both sources count once in the total.
            </p>
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <button
                    v-for="site in websites"
                    :key="site.id"
                    type="button"
                    :aria-pressed="filters.website_id === site.id"
                    class="rounded-xl border bg-card p-4 text-left transition-colors hover:border-teal-500 focus-visible:ring-2 focus-visible:ring-teal-500 focus-visible:outline-none"
                    :class="
                        filters.website_id === site.id
                            ? 'border-teal-500 ring-1 ring-teal-500'
                            : ''
                    "
                    @click="chooseWebsite(site.id)"
                >
                    <div class="flex items-center justify-between gap-3">
                        <span class="truncate font-medium">{{ site.name }}</span
                        ><span class="text-xl font-semibold tabular-nums">{{
                            websiteSummary[site.id]?.total || 0
                        }}</span>
                    </div>
                    <p class="mt-1 truncate text-xs text-muted-foreground">
                        {{ site.base_url }}
                    </p>
                    <div
                        class="mt-4 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground"
                    >
                        <span
                            ><strong class="font-medium text-foreground">{{
                                websiteSummary[site.id]?.forms || 0
                            }}</strong>
                            form contacts</span
                        >
                        <span
                            ><strong class="font-medium text-foreground">{{
                                websiteSummary[site.id]?.orders || 0
                            }}</strong>
                            order customers</span
                        >
                        <span
                            ><strong class="font-medium text-foreground">{{
                                websiteSummary[site.id]?.both || 0
                            }}</strong>
                            both</span
                        >
                    </div>
                </button>
            </div>
        </section>
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <label
                    for="audience-site"
                    class="mb-2 block text-sm font-medium"
                    >Website</label
                ><select
                    id="audience-site"
                    :value="filters.website_id || ''"
                    class="h-10 max-w-full rounded-lg border bg-background px-3 text-sm"
                    @change="
                        chooseWebsite(
                            Number(
                                ($event.target as HTMLSelectElement).value,
                            ) || null,
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
                </select>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button
                    variant="outline"
                    :disabled="!websites.length || discoverForm.processing"
                    @click="refreshContacts"
                    ><RefreshCw
                        class="size-4"
                        :class="discoverForm.processing ? 'animate-spin' : ''"
                    />{{
                        discoverForm.processing
                            ? 'Refreshing…'
                            : 'Refresh contacts'
                    }}</Button
                ><Button
                    variant="outline"
                    :disabled="!filters.website_id"
                    @click="panel = panel === 'import' ? '' : 'import'"
                    ><Upload class="size-4" />Import CSV</Button
                ><Button :disabled="!filters.website_id" @click="edit()"
                    ><Plus class="size-4" />Add contact</Button
                >
            </div>
        </div>
        <p class="text-xs text-muted-foreground">
            New synced orders and Fluent Forms entries add contacts
            automatically. Refresh contacts includes older synced records.
            Select a website to add a contact or import a CSV. New contacts
            start with no marketing permission. Permission is specific to this
            website; a Brevo unsubscribe, hard bounce or complaint excludes the
            address from campaigns on that connection.
        </p>
        <section
            v-if="panel === 'contact'"
            aria-label="Contact preferences"
            class="rounded-xl border border-teal-300 bg-card p-5 sm:p-6"
        >
            <div class="mb-5 flex items-center justify-between">
                <h2 class="text-lg font-semibold">
                    {{ selected ? 'Contact preferences' : 'Add a contact' }} ·
                    {{
                        websiteName(
                            selected?.website_id || filters.website_id || 0,
                        )
                    }}
                </h2>
                <Button variant="ghost" size="sm" @click="panel = ''"
                    >Close</Button
                >
            </div>
            <p
                v-if="selected?.suppression"
                class="mb-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-200"
            >
                Brevo has suppressed this address ({{ selected.suppression }}).
                Recording permission here will not override that suppression.
            </p>
            <form class="space-y-4" @submit.prevent="save">
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <div>
                        <label
                            for="contact-email"
                            class="mb-2 block text-sm font-medium"
                            >Email</label
                        ><Input
                            id="contact-email"
                            v-model="form.email"
                            type="email"
                            required
                            :readonly="!!selected"
                        />
                    </div>
                    <div>
                        <label
                            for="contact-name"
                            class="mb-2 block text-sm font-medium"
                            >Name</label
                        ><Input id="contact-name" v-model="form.name" />
                    </div>
                    <div>
                        <label
                            for="contact-language"
                            class="mb-2 block text-sm font-medium"
                            >Language</label
                        ><select
                            id="contact-language"
                            v-model="form.locale"
                            class="h-10 w-full rounded-md border bg-background px-3 text-sm"
                        >
                            <option value="">Unknown</option>
                            <option value="fr">French</option>
                            <option value="en">English</option>
                        </select>
                    </div>
                    <div>
                        <label
                            for="contact-status"
                            class="mb-2 block text-sm font-medium"
                            >Marketing preference</label
                        ><select
                            id="contact-status"
                            v-model="form.status"
                            class="h-10 w-full rounded-md border bg-background px-3 text-sm"
                        >
                            <option value="unknown">No permission</option>
                            <option value="subscribed">Subscribed</option>
                            <option value="unsubscribed">Unsubscribed</option>
                        </select>
                    </div>
                </div>
                <div
                    v-if="form.status === 'subscribed'"
                    class="space-y-4 rounded-lg bg-muted/40 p-4"
                >
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label
                                for="contact-source"
                                class="mb-2 block text-sm font-medium"
                                >Where was permission given?</label
                            ><Input
                                id="contact-source"
                                v-model="form.consent_source"
                                required
                                minlength="5"
                                maxlength="500"
                                placeholder="Newsletter signup · confirmation record reference"
                            />
                        </div>
                        <div>
                            <label
                                for="contact-consented"
                                class="mb-2 block text-sm font-medium"
                                >Permission date and time (your local
                                time)</label
                            ><input
                                id="contact-consented"
                                v-model="form.consented_at"
                                type="datetime-local"
                                required
                                class="h-10 w-full rounded-md border bg-background px-3 text-sm"
                            />
                        </div>
                    </div>
                    <label class="flex items-start gap-2 text-sm"
                        ><input
                            v-model="form.confirm_consent"
                            type="checkbox"
                            required
                            class="mt-1"
                        />I have evidence that this person agreed to marketing
                        from this website. A purchase alone is not
                        evidence.</label
                    >
                </div>
                <Button :disabled="form.processing">{{
                    form.processing ? 'Saving…' : 'Save preferences'
                }}</Button>
            </form>
        </section>
        <section
            v-if="panel === 'import'"
            aria-label="Import contacts"
            class="rounded-xl border bg-card p-5"
        >
            <div class="mb-4 flex items-center justify-between">
                <h2 class="font-semibold">Import contacts from CSV</h2>
                <Button variant="ghost" size="sm" @click="panel = ''"
                    >Close</Button
                >
            </div>
            <p class="text-sm text-muted-foreground">
                Use columns email, name, locale, status, consent_source and
                consented_at. Status defaults to unknown. Subscribers need a
                permission source and date. Maximum 5,000 rows / 2 MB.
            </p>
            <a
                :href="csvExample"
                download="wphub-marketing-contacts.csv"
                class="my-4 inline-flex items-center gap-2 text-sm underline underline-offset-4"
                ><Download class="size-4" />Download CSV example</a
            >
            <form
                class="space-y-4"
                @submit.prevent="
                    importForm.post(
                        '/marketing/websites/' + filters.website_id + '/import',
                        {
                            preserveScroll: true,
                            onSuccess: () => {
                                panel = '';
                                importForm.reset();
                            },
                        },
                    )
                "
            >
                <label for="contact-csv" class="block text-sm font-medium"
                    >CSV file</label
                ><input
                    id="contact-csv"
                    type="file"
                    accept=".csv,text/csv"
                    required
                    class="max-w-full text-sm"
                    @change="
                        importForm.file =
                            ($event.target as HTMLInputElement).files?.[0] ||
                            null
                    "
                /><label class="flex items-start gap-2 text-sm"
                    ><input
                        v-model="importForm.confirm_consent"
                        type="checkbox"
                        required
                        class="mt-1"
                    />I confirm that any subscribed rows have recorded marketing
                    permission for this website. Previously unsubscribed
                    contacts cannot be resubscribed by import.</label
                ><Button
                    :disabled="
                        !importForm.file ||
                        !importForm.confirm_consent ||
                        importForm.processing
                    "
                    >{{
                        importForm.processing ? 'Importing…' : 'Import contacts'
                    }}</Button
                >
            </form>
        </section>
        <form
            class="flex flex-wrap items-end gap-3 rounded-xl border bg-card p-4"
            @submit.prevent="apply"
        >
            <div class="min-w-48 flex-1">
                <label
                    for="audience-search"
                    class="mb-2 block text-xs font-medium"
                    >Find a contact</label
                ><Input
                    id="audience-search"
                    v-model="draft.search"
                    placeholder="Name or email"
                />
            </div>
            <div>
                <label
                    for="audience-source"
                    class="mb-2 block text-xs font-medium"
                    >Contact source</label
                >
                <select
                    id="audience-source"
                    v-model="draft.source"
                    class="h-10 max-w-full rounded-md border bg-background px-3 text-sm"
                >
                    <option
                        v-for="source in marketingSources"
                        :key="source.value"
                        :value="source.value"
                    >
                        {{ source.label }}
                    </option>
                </select>
            </div>
            <div>
                <label
                    for="audience-status"
                    class="mb-2 block text-xs font-medium"
                    >Preference</label
                ><select
                    id="audience-status"
                    v-model="draft.status"
                    class="h-10 rounded-md border bg-background px-3 text-sm"
                >
                    <option value="">All preferences</option>
                    <option value="subscribed">Subscribed</option>
                    <option value="unknown">No permission</option>
                    <option value="unsubscribed">Unsubscribed</option>
                </select>
            </div>
            <div>
                <label
                    for="audience-language"
                    class="mb-2 block text-xs font-medium"
                    >Language</label
                ><select
                    id="audience-language"
                    v-model="draft.locale"
                    class="h-10 rounded-md border bg-background px-3 text-sm"
                >
                    <option value="">All languages</option>
                    <option value="fr">French</option>
                    <option value="en">English</option>
                </select>
            </div>
            <div>
                <label
                    for="audience-segment"
                    class="mb-2 block text-xs font-medium"
                    >Order history</label
                ><select
                    id="audience-segment"
                    v-model="draft.segment"
                    class="h-10 max-w-full rounded-md border bg-background px-3 text-sm"
                >
                    <option
                        v-for="segment in marketingSegments"
                        :key="segment.value"
                        :value="segment.value"
                    >
                        {{
                            segment.value === 'all'
                                ? 'All contacts'
                                : segment.label
                        }}
                    </option>
                </select>
            </div>
            <Button variant="outline"
                ><Search class="size-4" />Apply filters</Button
            >
        </form>
        <div class="overflow-hidden rounded-xl border bg-card">
            <div v-if="!contacts.data.length" class="px-6 py-14 text-center">
                <Users class="mx-auto mb-4 size-9 text-muted-foreground" />
                <h2 class="text-lg font-semibold">
                    {{
                        hasContacts
                            ? 'No contacts match these filters'
                            : 'Bring your audience together'
                    }}
                </h2>
                <p class="mt-2 text-sm text-muted-foreground">
                    {{
                        hasContacts
                            ? 'Adjust your filters to see more contacts.'
                            : 'Refresh contacts to include synced orders and Fluent Forms entries, or select a website to import contacts.'
                    }}
                </p>
            </div>
            <div v-else class="relative overflow-x-auto">
                <table class="w-full min-w-[1000px] text-left text-sm">
                    <thead class="bg-muted/50 text-muted-foreground">
                        <tr>
                            <th class="px-5 py-3 font-medium">Contact</th>
                            <th class="px-5 py-3 font-medium">
                                Website & source
                            </th>
                            <th class="px-5 py-3 font-medium">Preference</th>
                            <th class="px-5 py-3 font-medium">Language</th>
                            <th class="px-5 py-3 font-medium">Activity</th>
                            <th class="px-5 py-3 font-medium">
                                Latest activity
                            </th>
                            <th class="px-5 py-3">
                                <span class="sr-only">Manage</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="contact in contacts.data"
                            :key="contact.id"
                            class="border-t"
                        >
                            <td class="px-5 py-4">
                                <p class="font-medium">
                                    {{ contact.name || 'Unnamed contact' }}
                                </p>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    {{ contact.email }}
                                </p>
                            </td>
                            <td class="px-5 py-4">
                                <p class="font-medium">
                                    {{ websiteName(contact.website_id) }}
                                </p>
                                <div class="mt-2 flex flex-wrap gap-1.5">
                                    <span
                                        v-if="contact.submissions_count > 0"
                                        class="rounded-full bg-violet-50 px-2 py-1 text-xs text-violet-800 dark:bg-violet-950 dark:text-violet-200"
                                        >Fluent Forms</span
                                    >
                                    <span
                                        v-if="contact.orders_count > 0"
                                        class="rounded-full bg-sky-50 px-2 py-1 text-xs text-sky-800 dark:bg-sky-950 dark:text-sky-200"
                                        >Orders</span
                                    >
                                    <span
                                        v-if="
                                            !Number(contact.orders_count) &&
                                            !Number(contact.submissions_count)
                                        "
                                        class="text-xs text-muted-foreground"
                                        >No linked activity</span
                                    >
                                </div>
                            </td>
                            <td class="px-5 py-4">
                                <span
                                    class="rounded-full px-2.5 py-1 text-xs font-medium whitespace-nowrap"
                                    :class="marketingTone(contact.status)"
                                    >{{ marketingStatus(contact.status) }}</span
                                >
                                <p
                                    v-if="contact.suppression"
                                    class="mt-2 text-xs text-amber-700 dark:text-amber-300"
                                >
                                    Excluded by Brevo
                                </p>
                            </td>
                            <td class="px-5 py-4">
                                {{
                                    contact.locale === 'fr'
                                        ? 'French'
                                        : contact.locale === 'en'
                                          ? 'English'
                                          : 'Unknown'
                                }}
                            </td>
                            <td class="px-5 py-4 tabular-nums">
                                <p>
                                    {{ contact.orders_count }} orders
                                    <span class="text-xs text-muted-foreground"
                                        >·
                                        {{ contact.completed_count }}
                                        completed</span
                                    >
                                </p>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    {{ contact.submissions_count }} form entries
                                </p>
                            </td>
                            <td
                                class="px-5 py-4 whitespace-nowrap text-muted-foreground"
                            >
                                <p v-if="contact.last_order_at">
                                    <span class="text-xs">Order · </span
                                    >{{ marketingDate(contact.last_order_at) }}
                                </p>
                                <p
                                    v-if="contact.last_submission_at"
                                    class="mt-1"
                                >
                                    <span class="text-xs">Form · </span
                                    >{{
                                        marketingDate(
                                            contact.last_submission_at,
                                        )
                                    }}
                                </p>
                                <span
                                    v-if="
                                        !contact.last_order_at &&
                                        !contact.last_submission_at
                                    "
                                    >—</span
                                >
                            </td>
                            <td class="px-5 py-4">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    :aria-label="
                                        'Preferences for ' + contact.email
                                    "
                                    @click="edit(contact)"
                                    >Preferences</Button
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <MarketingPagination :page="contacts" />
        </div>
    </MarketingLayout>
</template>
