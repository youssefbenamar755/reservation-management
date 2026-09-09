<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import type { TrafficSettings } from '@/types/traffic';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import {
    Check,
    Copy,
    ExternalLink,
    Globe,
    LoaderCircle,
    ShieldCheck,
} from 'lucide-vue-next';
import { computed, onUnmounted, reactive, ref, watch } from 'vue';

const props = defineProps<{ trafficSettings: TrafficSettings }>();
const page = usePage();
type Action = 'application' | 'connect' | 'disconnect' | 'catalog' | 'website';
type Mapping = { ga4_property_id: string; gsc_site_url: string };
type FlashPage = { props: { flash?: { error?: string; success?: string } } };
const initialFlash = page.props.flash as
    | { error?: string; success?: string }
    | undefined;
const errorMessage = ref(initialFlash?.error ?? '');
const successMessage = ref(
    initialFlash?.error ? '' : (initialFlash?.success ?? ''),
);
const busy = ref<Action | null>(null);
const appForm = reactive({
    client_id: props.trafficSettings.app.client_id ?? '',
    client_secret: '',
});
const appErrors = ref<Record<string, string>>({});
const drafts = reactive<Record<number, Mapping>>({});
const websiteErrors = reactive<Record<number, Record<string, string>>>({});
const selectedWebsiteId = ref<number | null>(
    props.trafficSettings.websites[0]?.id ?? null,
);
const copyMessage = ref('');
const baselines = new Map<number, string>();
let disposed = false,
    generation = 0;
let cancelVisit: (() => void) | null = null;
let connectRequest: AbortController | null = null;
watch(
    () => props.trafficSettings,
    (settings, previous) => {
        const changedAccount =
            previous &&
            (settings.connection.connected !== previous.connection.connected ||
                settings.connection.email !== previous.connection.email ||
                settings.app.client_id !== previous.app.client_id);
        for (const website of settings.websites) {
            const mapping = {
                ga4_property_id: website.ga4_property_id ?? '',
                gsc_site_url: website.gsc_site_url ?? '',
            };
            const baseline = JSON.stringify(mapping);
            if (
                !drafts[website.id] ||
                changedAccount ||
                baseline !== baselines.get(website.id)
            ) {
                drafts[website.id] = mapping;
                websiteErrors[website.id] = {};
            }
            baselines.set(website.id, baseline);
        }
        for (const id of Object.keys(drafts).map(Number))
            if (!settings.websites.some((website) => website.id === id)) {
                delete drafts[id];
                baselines.delete(id);
            }
        if (
            !settings.websites.some(
                (website) => website.id === selectedWebsiteId.value,
            )
        )
            selectedWebsiteId.value = settings.websites[0]?.id ?? null;
    },
    { immediate: true },
);
const selectedWebsite = computed(() =>
    props.trafficSettings.websites.find(
        (website) => website.id === selectedWebsiteId.value,
    ),
);
const websiteForm = computed(() =>
    selectedWebsiteId.value === null ? null : drafts[selectedWebsiteId.value],
);
const selectedErrors = computed(() =>
    selectedWebsiteId.value === null
        ? {}
        : (websiteErrors[selectedWebsiteId.value] ?? {}),
);
const secretRequired = computed(
    () =>
        !props.trafficSettings.app.has_secret ||
        appForm.client_id.trim() !== props.trafficSettings.app.client_id,
);
const reconnectRequired = computed(
    () =>
        !props.trafficSettings.connection.connected &&
        Boolean(
            props.trafficSettings.connection.email ||
            props.trafficSettings.connection.reconnect_required,
        ),
);
const connectLabel = computed(() =>
    reconnectRequired.value
        ? 'Reconnect Google'
        : props.trafficSettings.connection.connected
          ? 'Change Google account'
          : 'Connect reporting account',
);
const mappingIsValid = computed(() => {
    const mapping = websiteForm.value;
    return (
        !!mapping &&
        (!mapping.ga4_property_id ||
            props.trafficSettings.catalog.ga4.some(
                (property) => property.id === mapping.ga4_property_id,
            )) &&
        (!mapping.gsc_site_url ||
            props.trafficSettings.catalog.gsc.some(
                (site) => site.url === mapping.gsc_site_url,
            ))
    );
});
const mappedCount = computed(
    () =>
        props.trafficSettings.websites.filter(
            (website) => website.ga4_property_id || website.gsc_site_url,
        ).length,
);
function start(action: Action): number | null {
    if (disposed || busy.value) return null;
    busy.value = action;
    errorMessage.value = '';
    successMessage.value = '';
    return ++generation;
}
const current = (token: number) => !disposed && generation === token;
function result(response: FlashPage, fallback: string): boolean {
    if (response.props.flash?.error) {
        errorMessage.value = response.props.flash.error;
        return false;
    }
    successMessage.value = response.props.flash?.success ?? fallback;
    return true;
}
function options(
    token: number,
    fallback: string,
    onSaved?: () => void,
    onErrors?: (errors: Record<string, string>) => void,
) {
    return {
        preserveScroll: true,
        preserveState: true,
        onCancelToken: (token: { cancel: () => void }) => {
            cancelVisit = token.cancel;
        },
        onSuccess: (response: FlashPage) => {
            if (current(token) && result(response, fallback)) onSaved?.();
        },
        onError: (errors: Record<string, string>) => {
            if (!current(token)) return;
            onErrors?.(errors);
            errorMessage.value =
                Object.values(errors)[0] ??
                'Settings could not be saved. Please try again.';
        },
        onFinish: () => {
            if (current(token)) {
                busy.value = null;
                cancelVisit = null;
            }
        },
    };
}
function saveApplication() {
    if (!props.trafficSettings.app.can_manage) return;
    const token = start('application');
    if (token === null) return;
    appErrors.value = {};
    router.put(
        '/settings/traffic/application',
        { ...appForm },
        options(
            token,
            'Reporting application saved.',
            () => {
                appForm.client_secret = '';
            },
            (errors) => {
                appErrors.value = errors;
            },
        ),
    );
}
async function connectAccount() {
    if (!props.trafficSettings.app.configured) return;
    const token = start('connect');
    if (token === null) return;
    connectRequest = new AbortController();
    try {
        const { data } = await axios.post(
            '/settings/traffic/connect',
            {},
            {
                headers: { Accept: 'application/json' },
                signal: connectRequest.signal,
                timeout: 20_000,
            },
        );
        if (!current(token)) return;
        const destination = new URL(data.url);
        if (
            destination.origin !== 'https://accounts.google.com' ||
            destination.username ||
            destination.password
        )
            throw new Error('Invalid authorization URL');
        window.location.assign(destination.href);
    } catch {
        if (current(token)) {
            errorMessage.value =
                'Google connection could not be started. Reload the page if your session expired, then try again.';
            busy.value = null;
        }
    }
}
function changeConnection(action: 'disconnect' | 'catalog') {
    if (
        !props.trafficSettings.connection.connected &&
        !(action === 'disconnect' && reconnectRequired.value)
    )
        return;
    const token = start(action);
    if (token === null) return;
    const callbacks = options(
        token,
        action === 'disconnect'
            ? 'Reporting account disconnected.'
            : 'Available properties refreshed.',
    );
    if (action === 'disconnect')
        router.delete('/settings/traffic/connection', callbacks);
    else router.post('/settings/traffic/catalog', {}, callbacks);
}
function saveWebsite() {
    const website = selectedWebsite.value,
        mapping = websiteForm.value;
    if (
        !props.trafficSettings.connection.connected ||
        !website ||
        !mapping ||
        !mappingIsValid.value
    )
        return;
    const token = start('website');
    if (token === null) return;
    websiteErrors[website.id] = {};
    router.put(
        `/settings/traffic/websites/${website.id}`,
        {
            ga4_property_id: mapping.ga4_property_id || null,
            gsc_site_url: mapping.gsc_site_url || null,
        },
        options(
            token,
            `Reporting sources saved for ${website.name}.`,
            undefined,
            (errors) => {
                websiteErrors[website.id] = errors;
            },
        ),
    );
}
async function copyCallback() {
    try {
        await navigator.clipboard.writeText(
            props.trafficSettings.app.redirect_uri,
        );
        if (!disposed) copyMessage.value = 'Callback URL copied.';
    } catch {
        if (!disposed)
            copyMessage.value = 'Select the callback URL and copy it manually.';
    }
}
onUnmounted(() => {
    disposed = true;
    generation++;
    cancelVisit?.();
    connectRequest?.abort();
    appForm.client_secret = '';
});
</script>

<template>
    <AppLayout
        :breadcrumbs="[
            { title: 'Traffic & SEO settings', href: '/settings/traffic' },
        ]"
    >
        <Head title="Traffic & SEO settings" />
        <SettingsLayout>
            <div class="space-y-8">
                <HeadingSmall
                    title="Traffic & SEO"
                    description="Connect the Google account that owns your Analytics and Search Console properties."
                />
                <p
                    v-if="errorMessage"
                    role="alert"
                    class="rounded-lg border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive"
                >
                    {{ errorMessage }}
                </p>
                <p
                    v-else-if="successMessage"
                    role="status"
                    class="flex gap-2 rounded-lg border border-emerald-500/30 bg-emerald-500/5 p-3 text-sm text-emerald-700 dark:text-emerald-300"
                >
                    <Check class="size-4 shrink-0" />{{ successMessage }}
                </p>

                <section
                    class="space-y-4 rounded-xl border p-5"
                    aria-labelledby="reporting-account-heading"
                >
                    <div class="flex items-start gap-3">
                        <span
                            class="rounded-lg bg-indigo-500/10 p-2 text-indigo-600 dark:text-indigo-300"
                            ><ShieldCheck class="size-5"
                        /></span>
                        <div class="min-w-0">
                            <h2
                                id="reporting-account-heading"
                                class="font-medium"
                            >
                                Your reporting account
                            </h2>
                            <p
                                class="mt-1 text-sm break-all text-muted-foreground"
                            >
                                {{
                                    trafficSettings.connection.email
                                        ? trafficSettings.connection.email
                                        : 'No Google reporting account connected'
                                }}
                            </p>
                        </div>
                    </div>
                    <p class="text-sm leading-relaxed text-muted-foreground">
                        This connection reads Google Analytics and Search
                        Console reports. You can use a different Google account
                        from the one connected for Gmail.
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <Button
                            :disabled="
                                !!busy || !trafficSettings.app.configured
                            "
                            @click="connectAccount"
                            ><LoaderCircle
                                v-if="busy === 'connect'"
                                class="size-4 animate-spin"
                            />{{ connectLabel }}</Button
                        >
                        <Button
                            v-if="
                                trafficSettings.connection.connected ||
                                reconnectRequired
                            "
                            variant="outline"
                            :disabled="!!busy"
                            @click="changeConnection('disconnect')"
                            >Disconnect</Button
                        >
                    </div>
                    <p
                        v-if="reconnectRequired"
                        role="status"
                        class="text-sm text-amber-800 dark:text-amber-300"
                    >
                        Google access needs to be renewed. Reconnect the same
                        account to keep the website sources it can still access.
                    </p>
                    <p
                        v-if="!trafficSettings.app.configured"
                        class="text-xs text-muted-foreground"
                    >
                        {{
                            trafficSettings.app.can_manage
                                ? 'Complete the Google application setup below first.'
                                : 'Ask an administrator to configure the reporting application first.'
                        }}
                    </p>
                    <p
                        v-if="
                            trafficSettings.connection.connected ||
                            reconnectRequired
                        "
                        class="text-xs text-muted-foreground"
                    >
                        Reconnecting the same account keeps confirmed website
                        sources. Choosing a different account or disconnecting
                        clears the mappings. Your Gmail connection is separate.
                    </p>
                    <Link
                        href="/traffic"
                        class="inline-flex items-center gap-1 text-sm font-medium text-primary underline underline-offset-4"
                        >Open Traffic & SEO<ExternalLink class="size-3"
                    /></Link>
                </section>

                <section
                    class="space-y-5"
                    aria-labelledby="reporting-properties-heading"
                >
                    <div
                        class="flex flex-wrap items-start justify-between gap-3"
                    >
                        <div>
                            <h2
                                id="reporting-properties-heading"
                                class="font-medium"
                            >
                                Website sources
                            </h2>
                            <p class="mt-1 text-sm text-muted-foreground">
                                {{ mappedCount }} of
                                {{ trafficSettings.websites.length }} websites
                                linked. You can connect either service or both.
                            </p>
                        </div>
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="
                                !!busy || !trafficSettings.connection.connected
                            "
                            @click="changeConnection('catalog')"
                            ><LoaderCircle
                                v-if="busy === 'catalog'"
                                class="size-4 animate-spin"
                            />Refresh properties</Button
                        >
                    </div>
                    <div
                        v-if="trafficSettings.catalog.errors.length"
                        role="alert"
                        class="space-y-2 rounded-lg border border-amber-500/30 bg-amber-500/5 p-3 text-sm"
                    >
                        <p
                            v-for="(message, index) in trafficSettings.catalog
                                .errors"
                            :key="index"
                        >
                            {{ message }}
                        </p>
                    </div>
                    <p
                        v-if="
                            trafficSettings.connection.connected &&
                            !trafficSettings.catalog.loaded_at
                        "
                        class="text-sm text-muted-foreground"
                    >
                        Refresh properties to load the GA4 properties and
                        verified Search Console sites available to this account.
                    </p>
                    <p
                        v-if="!trafficSettings.websites.length"
                        class="rounded-lg border p-4 text-sm text-muted-foreground"
                    >
                        Add a website to WP Hub before linking reporting
                        sources.
                    </p>
                    <template v-else>
                        <div class="space-y-2">
                            <Label for="traffic-website">Website</Label
                            ><select
                                id="traffic-website"
                                v-model="selectedWebsiteId"
                                class="traffic-settings-select"
                                :disabled="!!busy"
                            >
                                <option
                                    v-for="website in trafficSettings.websites"
                                    :key="website.id"
                                    :value="website.id"
                                >
                                    {{ website.name }}
                                </option>
                            </select>
                        </div>
                        <form
                            v-if="selectedWebsite && websiteForm"
                            class="space-y-5 rounded-xl border p-5"
                            @submit.prevent="saveWebsite"
                        >
                            <p
                                class="flex items-center gap-2 text-sm break-all text-muted-foreground"
                            >
                                <Globe class="size-4 shrink-0" />{{
                                    selectedWebsite.base_url
                                }}
                            </p>
                            <fieldset
                                class="space-y-5 disabled:opacity-60"
                                :disabled="
                                    !!busy ||
                                    !trafficSettings.connection.connected
                                "
                            >
                                <div class="space-y-2">
                                    <Label for="traffic-ga4"
                                        >Google Analytics 4 property</Label
                                    ><select
                                        id="traffic-ga4"
                                        v-model="websiteForm.ga4_property_id"
                                        class="traffic-settings-select"
                                    >
                                        <option value="">Not linked</option>
                                        <option
                                            v-if="
                                                websiteForm.ga4_property_id &&
                                                !trafficSettings.catalog.ga4.some(
                                                    (property) =>
                                                        property.id ===
                                                        websiteForm!
                                                            .ga4_property_id,
                                                )
                                            "
                                            :value="websiteForm.ga4_property_id"
                                            disabled
                                        >
                                            Unavailable property
                                            {{ websiteForm.ga4_property_id }}
                                        </option>
                                        <option
                                            v-for="property in trafficSettings
                                                .catalog.ga4"
                                            :key="property.id"
                                            :value="property.id"
                                        >
                                            {{ property.name }} ·
                                            {{ property.id }}
                                        </option></select
                                    ><InputError
                                        :message="
                                            selectedErrors.ga4_property_id
                                        "
                                    />
                                    <p class="text-xs text-muted-foreground">
                                        Choose the property collecting visits
                                        for this website. Each property can be
                                        linked to one website.
                                    </p>
                                </div>
                                <div class="space-y-2">
                                    <Label for="traffic-gsc"
                                        >Search Console property</Label
                                    ><select
                                        id="traffic-gsc"
                                        v-model="websiteForm.gsc_site_url"
                                        class="traffic-settings-select"
                                    >
                                        <option value="">Not linked</option>
                                        <option
                                            v-if="
                                                websiteForm.gsc_site_url &&
                                                !trafficSettings.catalog.gsc.some(
                                                    (site) =>
                                                        site.url ===
                                                        websiteForm!
                                                            .gsc_site_url,
                                                )
                                            "
                                            :value="websiteForm.gsc_site_url"
                                            disabled
                                        >
                                            Unavailable:
                                            {{ websiteForm.gsc_site_url }}
                                        </option>
                                        <option
                                            v-for="site in trafficSettings
                                                .catalog.gsc"
                                            :key="site.url"
                                            :value="site.url"
                                        >
                                            {{ site.url }}
                                        </option></select
                                    ><InputError
                                        :message="selectedErrors.gsc_site_url"
                                    />
                                    <p class="text-xs text-muted-foreground">
                                        Domain and URL-prefix properties appear
                                        exactly as they do in Search Console.
                                    </p>
                                </div>
                            </fieldset>
                            <p
                                v-if="
                                    trafficSettings.connection.connected &&
                                    !mappingIsValid
                                "
                                class="text-xs text-destructive"
                            >
                                A selected property is unavailable to this
                                account. Refresh properties or choose an
                                available property before saving.
                            </p>
                            <Button
                                type="submit"
                                :disabled="
                                    !!busy ||
                                    !trafficSettings.connection.connected ||
                                    !mappingIsValid
                                "
                                ><LoaderCircle
                                    v-if="busy === 'website'"
                                    class="size-4 animate-spin"
                                />Save website sources</Button
                            >
                            <p class="text-xs text-muted-foreground">
                                Select “Not linked” for both services to remove
                                this website’s reporting sources.
                            </p>
                        </form>
                    </template>
                </section>

                <section
                    v-if="trafficSettings.app.can_manage"
                    class="space-y-5 border-t pt-8"
                    aria-labelledby="reporting-application-heading"
                >
                    <div class="flex items-center justify-between gap-3">
                        <h2
                            id="reporting-application-heading"
                            class="font-medium"
                        >
                            Google application
                        </h2>
                        <span
                            class="rounded-full bg-muted px-2.5 py-1 text-xs"
                            >{{
                                trafficSettings.app.configured
                                    ? 'Configured'
                                    : 'Setup required'
                            }}</span
                        >
                    </div>
                    <details
                        :open="!trafficSettings.app.configured"
                        class="rounded-lg border p-4 text-sm"
                    >
                        <summary class="cursor-pointer font-medium">
                            Set up reporting access
                        </summary>
                        <ol
                            class="mt-4 list-decimal space-y-3 pl-5 leading-relaxed text-muted-foreground"
                        >
                            <li>
                                In
                                <a
                                    href="https://console.cloud.google.com/"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="text-foreground underline"
                                    >Google Cloud Console</a
                                >, select your reporting project and enable
                                these APIs:
                                <a
                                    href="https://console.cloud.google.com/apis/library/analyticsdata.googleapis.com"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="text-foreground underline"
                                    >Google Analytics Data</a
                                >,
                                <a
                                    href="https://console.cloud.google.com/apis/library/analyticsadmin.googleapis.com"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="text-foreground underline"
                                    >Google Analytics Admin</a
                                >, and
                                <a
                                    href="https://console.cloud.google.com/apis/library/searchconsole.googleapis.com"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="text-foreground underline"
                                    >Google Search Console</a
                                >.
                            </li>
                            <li>
                                Create a separate OAuth client of type
                                <strong class="text-foreground"
                                    >Web application</strong
                                >. Use a different client ID from the Gmail
                                integration and add the callback below as its
                                authorized redirect URI.
                            </li>
                            <li>
                                Configure the consent screen. While the
                                application is in Testing, add the Google
                                account that owns your reporting properties as a
                                test user.
                            </li>
                            <li>
                                Save the credentials below, connect your
                                reporting account, then review Google’s
                                read-only Analytics and Search Console
                                permissions and account email access.
                            </li>
                        </ol>
                        <p class="mt-4 text-xs text-muted-foreground">
                            Google applications in Testing can require
                            reconnection after seven days.
                        </p>
                    </details>
                    <div class="space-y-2">
                        <Label for="traffic-callback">Callback URL</Label>
                        <div class="flex gap-2">
                            <Input
                                id="traffic-callback"
                                :model-value="trafficSettings.app.redirect_uri"
                                readonly
                                class="min-w-0 font-mono text-xs"
                                @focus="
                                    ($event.target as HTMLInputElement).select()
                                "
                            /><Button
                                variant="outline"
                                size="icon"
                                aria-label="Copy reporting callback URL"
                                @click="copyCallback"
                                ><Copy class="size-4"
                            /></Button>
                        </div>
                        <p
                            v-if="copyMessage"
                            role="status"
                            class="text-xs text-muted-foreground"
                        >
                            {{ copyMessage }}
                        </p>
                    </div>
                    <form class="space-y-5" @submit.prevent="saveApplication">
                        <fieldset
                            class="space-y-5 disabled:opacity-60"
                            :disabled="!!busy"
                        >
                            <div class="space-y-2">
                                <Label for="traffic-client-id"
                                    >Reporting client ID</Label
                                ><Input
                                    id="traffic-client-id"
                                    v-model="appForm.client_id"
                                    required
                                    autocomplete="off"
                                /><InputError :message="appErrors.client_id" />
                            </div>
                            <div class="space-y-2">
                                <Label for="traffic-client-secret"
                                    >Client secret</Label
                                ><Input
                                    id="traffic-client-secret"
                                    v-model="appForm.client_secret"
                                    type="password"
                                    :required="secretRequired"
                                    autocomplete="new-password"
                                    :placeholder="
                                        secretRequired
                                            ? 'Enter the secret for this client'
                                            : 'Leave blank to keep the saved secret'
                                    "
                                /><InputError
                                    :message="appErrors.client_secret"
                                />
                                <p class="text-xs text-muted-foreground">
                                    {{
                                        secretRequired
                                            ? 'A secret is required for this client ID.'
                                            : 'The saved secret is kept when this field is blank and the client ID is unchanged.'
                                    }}
                                </p>
                            </div>
                        </fieldset>
                        <Button type="submit" :disabled="!!busy"
                            ><LoaderCircle
                                v-if="busy === 'application'"
                                class="size-4 animate-spin"
                            />Save reporting application</Button
                        >
                    </form>
                </section>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>

<style scoped>
@reference "../../../css/app.css";
.traffic-settings-select {
    @apply w-full min-w-0 rounded-md border bg-background px-3 py-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-60;
}
</style>
