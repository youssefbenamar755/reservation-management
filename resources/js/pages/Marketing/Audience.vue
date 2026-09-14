<script setup lang="ts">
import MarketingLayout from '@/components/MarketingLayout.vue';
import MarketingPagination from '@/components/MarketingPagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    marketingDate,
    marketingSegments,
    marketingStatus,
    marketingTone,
} from '@/lib/marketing';
import type {
    MarketingCommon,
    MarketingContact,
    MarketingPage,
} from '@/types/marketing';
import { router, useForm } from '@inertiajs/vue3';
import { Download, Plus, Search, Upload, Users } from 'lucide-vue-next';
import { reactive, ref, watch } from 'vue';
const props = defineProps<
    MarketingCommon & {
        contacts: MarketingPage<MarketingContact>;
        summary: Record<string, number>;
        filters: {
            website_id: number;
            search?: string;
            locale?: string;
            segment?: string;
            status?: string;
        };
    }
>();
const draft = reactive({
    search: props.filters.search || '',
    locale: props.filters.locale || '',
    segment: props.filters.segment || 'all',
    status: props.filters.status || '',
});
watch(
    () => props.filters,
    (f) =>
        Object.assign(draft, {
            search: f.search || '',
            locale: f.locale || '',
            segment: f.segment || 'all',
            status: f.status || '',
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
        .post('/marketing/websites/' + props.filters.website_id + '/contacts', {
            preserveScroll: true,
            onSuccess: () => {
                panel.value = '';
                form.reset();
            },
        });
const apply = () =>
    router.get(
        '/marketing/audience',
        { website_id: props.filters.website_id, ...draft },
        { preserveScroll: true, preserveState: true },
    );
const csvExample =
    'data:text/csv;charset=utf-8,' +
    encodeURIComponent(
        'email,name,locale,status,consent_source,consented_at\ncustomer@example.com,Example Customer,fr,unknown,,\n',
    );
</script>
<template>
    <MarketingLayout
        title="Marketing audience"
        description="Manage subscribers and preferences for each website."
        active="Audience"
        :connected="connected"
    >
        <div class="grid grid-cols-2 gap-4 xl:grid-cols-4">
            <div
                v-for="item in [
                    { key: 'total', label: 'Website contacts' },
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
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <label
                    for="audience-site"
                    class="mb-2 block text-sm font-medium"
                    >Website</label
                ><select
                    id="audience-site"
                    :value="filters.website_id"
                    class="h-10 max-w-full rounded-lg border bg-background px-3 text-sm"
                    @change="
                        router.get('/marketing/audience', {
                            website_id: ($event.target as HTMLSelectElement)
                                .value,
                        })
                    "
                >
                    <option v-if="!websites.length" value="0">
                        Add a website first
                    </option>
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
                    :disabled="!filters.website_id || discoverForm.processing"
                    @click="
                        discoverForm.post(
                            '/marketing/websites/' +
                                filters.website_id +
                                '/discover',
                            { preserveScroll: true },
                        )
                    "
                    ><Users class="size-4" />{{
                        discoverForm.processing
                            ? 'Adding…'
                            : 'Add existing customers'
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
            Existing customers are added with no marketing permission.
            Permission is specific to this website; a Brevo unsubscribe, hard
            bounce or complaint excludes the address from campaigns on that
            connection.
        </p>
        <section
            v-if="panel === 'contact'"
            aria-label="Contact preferences"
            class="rounded-xl border border-teal-300 bg-card p-5 sm:p-6"
        >
            <div class="mb-5 flex items-center justify-between">
                <h2 class="text-lg font-semibold">
                    {{ selected ? 'Contact preferences' : 'Add a contact' }}
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
                        summary.total
                            ? 'No contacts match these filters'
                            : 'Start with your website’s customers'
                    }}
                </h2>
                <p class="mt-2 text-sm text-muted-foreground">
                    {{
                        summary.total
                            ? 'Adjust your filters to see more contacts.'
                            : 'Add existing customers, import a permission-based list, or add a contact individually.'
                    }}
                </p>
            </div>
            <div v-else class="relative overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-muted/50 text-muted-foreground">
                        <tr>
                            <th class="px-5 py-3 font-medium">Contact</th>
                            <th class="px-5 py-3 font-medium">Preference</th>
                            <th class="px-5 py-3 font-medium">Language</th>
                            <th class="px-5 py-3 font-medium">
                                Completed orders
                            </th>
                            <th class="px-5 py-3 font-medium">Last order</th>
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
                                {{ contact.completed_count }}
                            </td>
                            <td
                                class="px-5 py-4 whitespace-nowrap text-muted-foreground"
                            >
                                {{ marketingDate(contact.last_order_at) }}
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
