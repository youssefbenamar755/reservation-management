<script setup lang="ts">
import DocumentEmailPreview from '@/components/DocumentEmailPreview.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import type {
    DocumentBrandProfile,
    DocumentLanguage,
    DocumentTemplate,
    DocumentWebsite,
} from '@/types/document-delivery';
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import {
    CheckCircle2,
    ChevronRight,
    Globe,
    Loader2,
    Mail,
    Paintbrush,
    Send,
    ShieldCheck,
} from 'lucide-vue-next';
import { computed, nextTick, onUnmounted, ref, shallowRef, watch } from 'vue';

const props = defineProps<{
    websites: DocumentWebsite[];
    placeholders: string[];
    defaultTemplates: DocumentBrandProfile['templates'];
}>();
const websites = shallowRef(props.websites);
const initialWebsite =
    typeof window !== 'undefined'
        ? Number(new URLSearchParams(window.location.search).get('website_id'))
        : null;
const selectedId = ref<number | null>(
    websites.value.find((site) => site.id === initialWebsite)?.id ??
        websites.value[0]?.id ??
        null,
);
const selected = computed(() =>
    websites.value.find((site) => site.id === selectedId.value),
);
const draft = ref<DocumentBrandProfile | null>(null);
const baseline = ref('');
const tab = ref<'brand' | 'sender' | 'templates' | 'activation'>('brand');
const language = ref<DocumentLanguage>('fr');
const template = ref<DocumentTemplate>('delivery');
const attachmentCount = ref(2);
const testRecipient = ref('');
const testConfirmed = ref(false);
const testAttemptKey = ref<string | null>(null);
const testUnconfirmed = ref(false);
const activationConfirmed = ref(false);
const storeBrandingConfirmed = ref(false);
const busy = ref('');
const errors = ref<string[]>([]);
const notice = ref('');
const preview = shallowRef<{
    subject: string;
    html: string;
    text: string;
} | null>(null);
const previewStale = ref(false);
let disposed = false;
let request: AbortController | null = null;
const dirty = computed(
    () => !!draft.value && JSON.stringify(draft.value) !== baseline.value,
);
const currentTemplate = computed(
    () => draft.value?.templates?.[template.value]?.[language.value],
);
const connection = computed(() =>
    selected.value?.connections.find(
        (item) => item.id === draft.value?.gmail_connection_id,
    ),
);
const aliases = computed(() => connection.value?.aliases ?? []);
const senderVerified = computed(() =>
    aliases.value.some(
        (alias) =>
            alias.email.toLowerCase() ===
            draft.value?.sender_email?.toLowerCase(),
    ),
);
const activeCount = computed(
    () => websites.value.filter((site) => site.profile.workflow_enabled).length,
);
const tabs = [
    { id: 'brand' as const, title: 'Brand identity', icon: Paintbrush },
    { id: 'sender' as const, title: 'Sender & workflow', icon: Mail },
    { id: 'templates' as const, title: 'Templates & preview', icon: Globe },
    { id: 'activation' as const, title: 'Test & activate', icon: ShieldCheck },
];
const templateNames = {
    acknowledgement: 'Order acknowledgement',
    delivery: 'Document delivery',
    correction: 'Corrected documents',
};
const colorFields = [
    { key: 'primary_color' as const, label: 'Primary colour' },
    { key: 'accent_color' as const, label: 'Button / accent colour' },
    { key: 'background_color' as const, label: 'Background colour' },
    { key: 'text_color' as const, label: 'Text colour' },
];
function copyProfile(site: DocumentWebsite) {
    draft.value = JSON.parse(JSON.stringify(site.profile));
    if (!draft.value) return;
    for (const field of [
        'brand_name',
        'logo_url',
        'primary_color',
        'accent_color',
        'background_color',
        'text_color',
        'support_email',
        'website_url',
        'support_phone',
        'footer',
        'sender_email',
        'sender_name',
        'reply_to',
        'service_label',
    ] as const)
        draft.value[field] ??= '';
    draft.value.gmail_connection_id ??= null;
    draft.value.required_services ??= [];
    draft.value.templates = {
        ...JSON.parse(JSON.stringify(props.defaultTemplates)),
        ...draft.value.templates,
    };
    baseline.value = JSON.stringify(draft.value);
    language.value = draft.value.default_language || 'fr';
    preview.value = null;
    activationConfirmed.value = false;
    storeBrandingConfirmed.value = false;
    testConfirmed.value = false;
}
watch(
    selected,
    (site) => {
        if (site) copyProfile(site);
    },
    { immediate: true },
);
watch(
    () => props.websites,
    (value) => {
        websites.value = value;
    },
);
watch(
    [draft, template, language, attachmentCount],
    () => {
        if (preview.value) previewStale.value = true;
    },
    { deep: true },
);
function switchWebsite(id: number) {
    if (busy.value || id === selectedId.value) return;
    if (
        dirty.value &&
        !window.confirm('Discard unsaved changes for this website?')
    )
        return;
    selectedId.value = id;
    errors.value = [];
    notice.value = '';
    testRecipient.value = '';
    testAttemptKey.value = null;
    testUnconfirmed.value = false;
}
function safeError(exception: unknown, fallback: string) {
    if (axios.isAxiosError(exception)) {
        if (exception.response?.status === 422) {
            const values = Object.values(exception.response.data?.errors ?? {})
                .flat()
                .filter((value): value is string => typeof value === 'string');
            if (values.length) return values;
        }
        if (exception.response?.status === 409)
            return [
                'This website configuration changed in another session. Reload before saving to avoid replacing newer settings.',
            ];
        if (
            exception.response?.status === 403 ||
            exception.response?.status === 404
        )
            return ['This website is unavailable, or your access has changed.'];
        if (exception.response?.status === 419)
            return ['Your session expired. Reload WP Hub and sign in again.'];
    }
    return [fallback];
}
async function run(
    action: string,
    callback: (signal: AbortSignal) => Promise<void>,
    fallback: string,
) {
    if (busy.value || disposed || !selected.value) return;
    busy.value = action;
    errors.value = [];
    notice.value = '';
    request = new AbortController();
    try {
        await callback(request.signal);
    } catch (exception) {
        if (
            action === 'test' &&
            axios.isAxiosError(exception) &&
            [400, 401, 403, 404, 409, 419, 422, 429].includes(
                exception.response?.status ?? 0,
            )
        ) {
            testUnconfirmed.value = false;
            testAttemptKey.value = null;
        }
        if (!disposed) errors.value = safeError(exception, fallback);
    } finally {
        if (!disposed) busy.value = '';
    }
}
async function save(enabled?: boolean) {
    if (
        !draft.value ||
        !selected.value ||
        (enabled === true && !activationConfirmed.value)
    )
        return;
    const id = selected.value.id;
    await run(
        'save',
        async (signal) => {
            const { data } = await axios.put(
                `/settings/email-documents/${id}`,
                {
                    ...draft.value,
                    workflow_enabled: enabled ?? draft.value?.workflow_enabled,
                },
                {
                    signal,
                    timeout: 30000,
                    headers: { Accept: 'application/json' },
                },
            );
            if (disposed) return;
            if (data.website?.id === id) {
                websites.value = websites.value.map((site) =>
                    site.id === id ? data.website : site,
                );
            } else {
                router.reload({ only: ['websites'] });
            }
            notice.value =
                enabled === true
                    ? 'Workflow activated for this website. Other websites are unchanged.'
                    : enabled === false
                      ? 'Workflow disabled for this website. Existing delivery history is preserved.'
                      : 'Website configuration saved.';
        },
        'The website configuration could not be saved. Your changes remain in this form.',
    );
}
async function disableWorkflow() {
    if (!selected.value?.profile.workflow_enabled) return;
    const id = selected.value.id;
    const savedVersion = selected.value.profile.config_version;
    const unsaved =
        dirty.value && draft.value
            ? (JSON.parse(JSON.stringify(draft.value)) as DocumentBrandProfile)
            : null;
    await run(
        'disable',
        async (signal) => {
            const { data } = await axios.post(
                `/settings/email-documents/${id}/disable`,
                { confirmed: true },
                {
                    signal,
                    timeout: 30000,
                    headers: { Accept: 'application/json' },
                },
            );
            if (disposed) return;
            if (
                data.website_id !== id ||
                data.workflow_enabled !== false ||
                !Number.isInteger(data.config_version)
            )
                throw new Error('Disable result not confirmed');
            websites.value = websites.value.map((site) =>
                site.id === id
                    ? {
                          ...site,
                          profile: {
                              ...site.profile,
                              ...data.profile,
                              workflow_enabled: false,
                              config_version: data.config_version,
                          },
                          readiness: {
                              ...site.readiness,
                              completion_supported: false,
                          },
                      }
                    : site,
            );
            await nextTick();
            if (disposed) return;
            const changedElsewhere =
                data.previous_config_version !== savedVersion;
            if (unsaved)
                draft.value = {
                    ...unsaved,
                    workflow_enabled: false,
                    config_version: changedElsewhere
                        ? savedVersion
                        : data.config_version,
                };
            notice.value =
                'Workflow disabled in WP Hub. Unsaved edits were not published. Existing history is preserved. Publish the disabled state to the store separately.' +
                (changedElsewhere && unsaved
                    ? ' The saved settings also changed elsewhere; reload before applying your draft.'
                    : '');
        },
        'The disable result could not be confirmed. Reload the saved website state before taking another action.',
    );
}
async function renderPreview() {
    if (!selected.value || !draft.value) return;
    await run(
        'preview',
        async (signal) => {
            const { data } = await axios.post(
                `/settings/email-documents/${selected.value!.id}/preview`,
                {
                    profile: draft.value,
                    template: template.value,
                    language: language.value,
                    sample_document_count: attachmentCount.value,
                },
                {
                    signal,
                    timeout: 30000,
                    headers: { Accept: 'application/json' },
                },
            );
            const rendered = data.rendered ?? data;
            if (
                !rendered ||
                typeof rendered.html !== 'string' ||
                typeof rendered.subject !== 'string'
            )
                throw new Error('Invalid preview');
            if (disposed) return;
            preview.value = rendered;
            previewStale.value = false;
        },
        'This preview could not be rendered. Review the template and brand configuration.',
    );
}
function restoreTemplate() {
    if (!draft.value || busy.value) return;
    if (
        !window.confirm(
            `Restore the default ${language.value === 'fr' ? 'French' : 'English'} ${templateNames[template.value].toLowerCase()} text? This changes your draft only.`,
        )
    )
        return;
    draft.value.templates[template.value][language.value] = JSON.parse(
        JSON.stringify(props.defaultTemplates[template.value][language.value]),
    );
    notice.value = 'Default text restored in this draft. Save when ready.';
}
function connectionChanged() {
    if (!draft.value) return;
    draft.value.sender_email = '';
    draft.value.sender_name = '';
}
function setServices(event: Event) {
    if (draft.value)
        draft.value.required_services = (
            event.target as HTMLTextAreaElement
        ).value
            .split('\n')
            .map((value) => value.trim())
            .filter(Boolean);
}
async function checkStore() {
    if (!selected.value || dirty.value) return;
    await run(
        'capability',
        async (signal) => {
            const { data } = await axios.post(
                `/settings/email-documents/${selected.value!.id}/capability`,
                {},
                {
                    signal,
                    timeout: 30000,
                    headers: { Accept: 'application/json' },
                },
            );
            if (disposed) return;
            if (data.website)
                websites.value = websites.value.map((site) =>
                    site.id === data.website.id ? data.website : site,
                );
            else router.reload({ only: ['websites'] });
            notice.value =
                data.message ||
                'Store capability check finished. Review the status below.';
        },
        'The store integration could not be verified. Completion remains unavailable until verified.',
    );
}
async function sendTest() {
    if (
        !selected.value ||
        !testConfirmed.value ||
        dirty.value ||
        testUnconfirmed.value ||
        !/^[^\s@,;]+@[^\s@,;]+\.[^\s@,;]+$/.test(testRecipient.value.trim())
    )
        return;
    testAttemptKey.value ??= crypto.randomUUID();
    await run(
        'test',
        async (signal) => {
            testUnconfirmed.value = true;
            const { data } = await axios.post(
                `/settings/email-documents/${selected.value!.id}/test`,
                {
                    recipient: testRecipient.value.trim(),
                    language: language.value,
                    template: template.value,
                    sample_document_count: attachmentCount.value,
                    confirmed: true,
                    idempotency_key: testAttemptKey.value,
                },
                {
                    signal,
                    timeout: 30000,
                    headers: { Accept: 'application/json' },
                },
            );
            if (disposed) return;
            notice.value =
                data.message ||
                'Test request finished. Review the last test result below.';
            testUnconfirmed.value = !['sent', 'failed'].includes(data.result);
            if (!testUnconfirmed.value) testAttemptKey.value = null;
            testConfirmed.value = false;
            router.reload({ only: ['websites'] });
        },
        'The test result could not be confirmed. Check the test recipient’s mailbox before trying again. No real order was completed.',
    );
}
async function applyStoreBranding() {
    if (!selected.value || dirty.value || !storeBrandingConfirmed.value) return;
    await run(
        'sync-brand',
        async (signal) => {
            const { data } = await axios.post(
                `/settings/email-documents/${selected.value!.id}/sync-brand`,
                {
                    confirmed: true,
                    config_version: draft.value?.config_version,
                },
                {
                    signal,
                    timeout: 30000,
                    headers: { Accept: 'application/json' },
                },
            );
            if (disposed) return;
            if (data.website)
                websites.value = websites.value.map((site) =>
                    site.id === data.website.id ? data.website : site,
                );
            else router.reload({ only: ['websites'] });
            storeBrandingConfirmed.value = false;
            notice.value =
                data.message ||
                'Saved branding synchronized to the store. Review its capability status before completing orders.';
        },
        'Store branding could not be synchronized. Review the companion plugin and connection before trying again.',
    );
}
function lastTest(site: DocumentWebsite) {
    const result = site.profile.last_test_result;
    if (!result) return 'Not tested';
    return typeof result === 'string'
        ? result
        : typeof result.status === 'string'
          ? result.status
          : 'Review result';
}
onUnmounted(() => {
    disposed = true;
    request?.abort();
});
</script>

<template>
    <Head title="Email & Documents" />
    <AppLayout
        :breadcrumbs="[
            { title: 'Settings', href: '/settings/profile' },
            { title: 'Email & Documents', href: '/settings/email-documents' },
        ]"
    >
        <SettingsLayout wide>
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p
                        class="text-xs font-medium tracking-wider text-primary uppercase"
                    >
                        Customer document delivery
                    </p>
                    <h1 class="mt-2 text-2xl font-semibold tracking-tight">
                        One workflow. Your websites’ own brands.
                    </h1>
                    <p
                        class="mt-2 max-w-2xl text-sm leading-relaxed text-muted-foreground"
                    >
                        Configure a sender, branded templates and document
                        fulfillment for every website. Each workflow stays off
                        until you activate it.
                    </p>
                </div>
                <div class="rounded-xl border bg-muted/20 px-4 py-3">
                    <p class="text-2xl font-semibold">
                        {{ activeCount
                        }}<span
                            class="text-base font-normal text-muted-foreground"
                        >
                            / {{ websites.length }}</span
                        >
                    </p>
                    <p class="text-xs text-muted-foreground">
                        websites activated
                    </p>
                </div>
            </div>
            <div
                v-if="!websites.length"
                class="rounded-xl border border-dashed p-8 text-center"
            >
                <Globe class="mx-auto mb-3 size-8 text-muted-foreground" />
                <h2 class="font-semibold">Register your first website</h2>
                <p class="mt-2 text-sm text-muted-foreground">
                    Email &amp; Documents uses your website registry. Every new
                    website gets its own draft configuration.
                </p>
                <Link
                    href="/websites/create"
                    class="mt-4 inline-block text-sm font-medium underline"
                    >Add website</Link
                >
            </div>
            <section
                v-else
                aria-labelledby="website-readiness-heading"
                class="space-y-3"
            >
                <div class="flex items-baseline justify-between gap-3">
                    <h2 id="website-readiness-heading" class="font-semibold">
                        Website readiness
                    </h2>
                    <p class="text-xs text-muted-foreground">
                        Select a website to configure
                    </p>
                </div>
                <div class="grid gap-3 xl:grid-cols-2">
                    <button
                        v-for="site in websites"
                        :key="site.id"
                        type="button"
                        :disabled="!!busy"
                        class="rounded-xl border p-4 text-left transition-colors focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-60"
                        :class="
                            selectedId === site.id
                                ? 'border-primary bg-primary/5 ring-1 ring-primary/20'
                                : 'hover:bg-muted/30'
                        "
                        :aria-pressed="selectedId === site.id"
                        @click="switchWebsite(site.id)"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate font-semibold">
                                    {{ site.name }}
                                </p>
                                <p
                                    class="mt-1 truncate text-xs text-muted-foreground"
                                >
                                    {{ site.url }}
                                </p>
                            </div>
                            <span
                                class="shrink-0 rounded-full px-2 py-1 text-xs font-medium"
                                :class="
                                    site.profile.workflow_enabled
                                        ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'
                                        : 'bg-muted text-muted-foreground'
                                "
                                >{{
                                    site.profile.workflow_enabled
                                        ? 'Active'
                                        : 'Draft · off'
                                }}</span
                            >
                        </div>
                        <dl
                            class="mt-4 grid gap-x-3 gap-y-2 text-xs sm:grid-cols-2"
                        >
                            <div>
                                <dt class="text-muted-foreground">Brand</dt>
                                <dd class="mt-0.5">
                                    {{
                                        site.readiness.ready
                                            ? 'Ready'
                                            : 'Configuration required'
                                    }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">Sender</dt>
                                <dd class="mt-0.5 break-all">
                                    {{
                                        site.profile.sender_email ||
                                        'Configuration required'
                                    }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">
                                    Gmail / templates
                                </dt>
                                <dd class="mt-0.5">
                                    {{
                                        site.readiness.gmail_connected
                                            ? 'Connected'
                                            : 'Not connected'
                                    }}
                                    ·
                                    {{
                                        site.readiness.templates_ready
                                            ? 'Templates ready'
                                            : 'Templates required'
                                    }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">
                                    Store / suppression
                                </dt>
                                <dd class="mt-0.5">
                                    {{ site.readiness.platform }} ·
                                    {{
                                        site.readiness.completion_supported
                                            ? 'Verified'
                                            : 'Not configured'
                                    }}
                                </dd>
                            </div>
                            <div class="sm:col-span-2">
                                <dt class="text-muted-foreground">Last test</dt>
                                <dd class="mt-0.5">
                                    {{ lastTest(site)
                                    }}<span v-if="site.profile.last_test_at">
                                        ·
                                        {{
                                            new Date(
                                                site.profile.last_test_at,
                                            ).toLocaleString()
                                        }}</span
                                    >
                                </dd>
                            </div>
                        </dl>
                        <span
                            class="mt-3 inline-flex items-center gap-1 text-xs font-medium text-primary"
                            >Configure website<ChevronRight class="size-3"
                        /></span>
                    </button>
                </div>
            </section>
            <section
                v-if="selected && draft"
                class="min-w-0 rounded-2xl border"
                aria-labelledby="profile-editor-heading"
            >
                <header
                    class="flex flex-wrap items-center justify-between gap-3 border-b p-5"
                >
                    <div>
                        <h2
                            id="profile-editor-heading"
                            class="text-lg font-semibold"
                        >
                            {{ selected.name }}
                        </h2>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Configuration version {{ draft.config_version }} ·
                            {{
                                dirty
                                    ? 'Unsaved changes'
                                    : 'Saved configuration'
                            }}
                        </p>
                    </div>
                    <Link
                        :href="`/websites/${selected.id}/edit`"
                        class="text-xs underline underline-offset-4"
                        >Website settings</Link
                    >
                </header>
                <nav
                    class="flex flex-wrap gap-1 border-b p-2"
                    aria-label="Email configuration sections"
                >
                    <button
                        v-for="item in tabs"
                        :key="item.id"
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm"
                        :class="
                            tab === item.id
                                ? 'bg-muted font-medium'
                                : 'text-muted-foreground hover:bg-muted/50'
                        "
                        :aria-current="tab === item.id ? 'page' : undefined"
                        @click="tab = item.id"
                    >
                        <component :is="item.icon" class="size-4" />{{
                            item.title
                        }}
                    </button>
                </nav>
                <div class="space-y-5 p-4 sm:p-6">
                    <div
                        v-if="errors.length"
                        role="alert"
                        class="rounded-lg border border-destructive/20 bg-destructive/5 p-3 text-sm text-destructive"
                    >
                        <p v-for="message in errors" :key="message">
                            {{ message }}
                        </p>
                    </div>
                    <p
                        v-if="notice"
                        role="status"
                        class="rounded-lg border border-emerald-500/20 bg-emerald-500/5 p-3 text-sm"
                    >
                        {{ notice }}
                    </p>
                    <fieldset
                        :disabled="!!busy"
                        class="min-w-0 space-y-5 disabled:opacity-70"
                    >
                        <template v-if="tab === 'brand'">
                            <div>
                                <h3 class="font-medium">
                                    Recognisably {{ selected.name }}
                                </h3>
                                <p class="mt-1 text-sm text-muted-foreground">
                                    Use this website’s real identity. Missing
                                    details never fall back to another brand.
                                </p>
                            </div>
                            <div class="grid gap-4 md:grid-cols-2">
                                <label class="setting-label"
                                    >Customer-facing brand name<input
                                        v-model="draft.brand_name"
                                        class="setting-input"
                                        maxlength="150" /></label
                                ><label class="setting-label"
                                    >Website URL<input
                                        v-model="draft.website_url"
                                        class="setting-input"
                                        type="url" /></label
                                ><label class="setting-label md:col-span-2"
                                    >Logo URL<input
                                        v-model="draft.logo_url"
                                        class="setting-input"
                                        type="url"
                                    /><span class="setting-help"
                                        >Use an existing trusted HTTPS logo.
                                        Customer documents must not be used as
                                        public brand assets.</span
                                    ></label
                                >
                            </div>
                            <div
                                class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
                            >
                                <label
                                    v-for="field in colorFields"
                                    :key="field.key"
                                    class="setting-label"
                                    >{{ field.label }}
                                    <div class="flex items-center gap-2">
                                        <span
                                            class="size-9 shrink-0 rounded-md border"
                                            :style="{
                                                backgroundColor:
                                                    /^#[0-9a-f]{6}$/i.test(
                                                        draft[field.key] || '',
                                                    )
                                                        ? draft[field.key]
                                                        : 'transparent',
                                            }"
                                        /><input
                                            v-model="draft[field.key]"
                                            class="setting-input font-mono"
                                            maxlength="7"
                                            placeholder="#000000"
                                        /></div
                                ></label>
                            </div>
                            <div class="grid gap-4 md:grid-cols-2">
                                <label class="setting-label"
                                    >Support email<input
                                        v-model="draft.support_email"
                                        type="email"
                                        class="setting-input" /></label
                                ><label class="setting-label"
                                    >Support phone or WhatsApp
                                    <span
                                        class="font-normal text-muted-foreground"
                                        >(optional)</span
                                    ><input
                                        v-model="draft.support_phone"
                                        class="setting-input"
                                        maxlength="100"
                                /></label>
                            </div>
                            <label class="setting-label"
                                >Footer / signature<textarea
                                    v-model="draft.footer"
                                    rows="4"
                                    class="setting-input"
                                    maxlength="3000"
                                /><span class="setting-help"
                                    >Plain text. Keep this compact and relevant
                                    to this website’s support.</span
                                ></label
                            >
                        </template>
                        <template v-if="tab === 'sender'">
                            <div>
                                <h3 class="font-medium">
                                    Website-specific sender
                                </h3>
                                <p class="mt-1 text-sm text-muted-foreground">
                                    Only verified addresses available to the
                                    selected Gmail connection can be used.
                                </p>
                            </div>
                            <div class="grid gap-4 md:grid-cols-2">
                                <label class="setting-label"
                                    >Connected Gmail account<select
                                        v-model="draft.gmail_connection_id"
                                        class="setting-input"
                                        @change="connectionChanged"
                                    >
                                        <option :value="null">
                                            Choose a connection
                                        </option>
                                        <option
                                            v-for="account in selected.connections"
                                            :key="account.id"
                                            :value="account.id"
                                        >
                                            {{ account.email
                                            }}{{
                                                account.connected
                                                    ? ''
                                                    : ' — reconnect required'
                                            }}
                                        </option>
                                    </select></label
                                ><label class="setting-label"
                                    >Approved sender address<select
                                        v-model="draft.sender_email"
                                        class="setting-input"
                                    >
                                        <option value="">
                                            Choose a verified sender
                                        </option>
                                        <option
                                            v-for="alias in aliases"
                                            :key="alias.email"
                                            :value="alias.email"
                                        >
                                            {{
                                                alias.name
                                                    ? `${alias.name} — `
                                                    : ''
                                            }}{{ alias.email }}
                                        </option>
                                        <option
                                            v-if="
                                                draft.sender_email &&
                                                !senderVerified
                                            "
                                            :value="draft.sender_email"
                                            disabled
                                        >
                                            {{ draft.sender_email }} —
                                            verification required
                                        </option>
                                    </select></label
                                ><label class="setting-label"
                                    >Sender display name<input
                                        v-model="draft.sender_name"
                                        class="setting-input"
                                        maxlength="150" /></label
                                ><label class="setting-label"
                                    >Reply-To address<input
                                        v-model="draft.reply_to"
                                        class="setting-input"
                                        type="email" /></label
                                ><label class="setting-label"
                                    >Default language<select
                                        v-model="draft.default_language"
                                        class="setting-input"
                                    >
                                        <option value="fr">Français</option>
                                        <option value="en">
                                            English
                                        </option></select
                                    ><span class="setting-help"
                                        >The recorded order language takes
                                        priority. Names are never used to infer
                                        language.</span
                                    ></label
                                ><label class="setting-label"
                                    >Service wording<input
                                        v-model="draft.service_label"
                                        class="setting-input"
                                        placeholder="e.g. flight and hotel reservations"
                                    /><span class="setting-help"
                                        >Describe the real service. A
                                        reservation is not an issued airline
                                        ticket.</span
                                    ></label
                                >
                            </div>
                            <label class="setting-label"
                                >Required services for complete
                                fulfillment<textarea
                                    :value="draft.required_services.join('\n')"
                                    rows="3"
                                    class="setting-input"
                                    placeholder="Flight reservation&#10;Hotel reservation"
                                    @change="setServices"
                                /><span class="setting-help"
                                    >One service per line. Operators must
                                    explicitly confirm all services and required
                                    documents for the actual order before
                                    completion.</span
                                ></label
                            >
                            <div class="rounded-lg bg-muted/30 p-4 text-sm">
                                <p class="font-medium">
                                    Attachment-only delivery
                                </p>
                                <p class="mt-1 text-muted-foreground">
                                    Customer PDFs are attached to the email.
                                    Partial deliveries keep the order’s current
                                    status. The ecommerce store remains the
                                    single owner of the initial order
                                    acknowledgement.
                                </p>
                            </div>
                            <Link
                                href="/settings/email"
                                class="inline-block text-sm underline underline-offset-4"
                                >Manage Gmail connection and permissions</Link
                            >
                        </template>
                        <template v-if="tab === 'templates'">
                            <div class="grid gap-3 sm:grid-cols-3">
                                <label class="setting-label"
                                    >Template<select
                                        v-model="template"
                                        class="setting-input"
                                    >
                                        <option
                                            v-for="(
                                                title, key
                                            ) in templateNames"
                                            :key="key"
                                            :value="key"
                                        >
                                            {{ title }}
                                        </option>
                                    </select></label
                                ><label class="setting-label"
                                    >Language<select
                                        v-model="language"
                                        class="setting-input"
                                    >
                                        <option value="fr">Français</option>
                                        <option value="en">English</option>
                                    </select></label
                                ><label class="setting-label"
                                    >Sample PDFs<select
                                        v-model="attachmentCount"
                                        class="setting-input"
                                    >
                                        <option :value="0">
                                            0 · no documents
                                        </option>
                                        <option :value="1">1 PDF</option>
                                        <option :value="2">2 PDFs</option>
                                        <option :value="3">3 PDFs</option>
                                    </select></label
                                >
                            </div>
                            <div
                                v-if="template === 'acknowledgement'"
                                class="rounded-lg bg-muted/30 p-3 text-sm"
                            >
                                The store sends this acknowledgement. WPHub does
                                not send a duplicate. Install and explicitly
                                configure the companion integration to apply
                                saved branding locally on the store.
                            </div>
                            <div v-if="currentTemplate" class="space-y-4">
                                <label class="setting-label"
                                    >Subject<input
                                        v-model="currentTemplate.subject"
                                        class="setting-input"
                                        maxlength="255" /></label
                                ><label class="setting-label"
                                    >Inbox preview text<input
                                        v-model="currentTemplate.preheader"
                                        class="setting-input"
                                        maxlength="500" /></label
                                ><label class="setting-label"
                                    >Heading<input
                                        v-model="currentTemplate.heading"
                                        class="setting-input"
                                        maxlength="255" /></label
                                ><label class="setting-label"
                                    >Body<textarea
                                        v-model="currentTemplate.body"
                                        class="setting-input"
                                        rows="8"
                                        maxlength="20000"
                                    /></label
                                ><label class="setting-label"
                                    >Optional secondary block<textarea
                                        v-model="currentTemplate.optional_block"
                                        class="setting-input"
                                        rows="3"
                                        maxlength="3000"
                                    /><span class="setting-help"
                                        >Leave blank for the default document
                                        email. Do not condition support or
                                        reservation activation on a
                                        review.</span
                                    ></label
                                >
                            </div>
                            <details class="rounded-lg border p-3">
                                <summary
                                    class="cursor-pointer text-sm font-medium"
                                >
                                    Supported placeholders
                                </summary>
                                <div class="mt-3 flex flex-wrap gap-2">
                                    <code
                                        v-for="placeholder in placeholders"
                                        :key="placeholder"
                                        class="rounded border bg-muted/20 px-2 py-1 text-xs"
                                        >{{ placeholder }}</code
                                    >
                                </div>
                                <p class="mt-2 text-xs text-muted-foreground">
                                    Plain-text values are safely escaped. Only
                                    listed placeholders are accepted; executable
                                    template code is not supported.
                                </p>
                            </details>
                            <div class="flex flex-wrap justify-between gap-2">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    @click="restoreTemplate"
                                    >Restore default text</Button
                                ><Button
                                    type="button"
                                    variant="outline"
                                    @click="renderPreview"
                                    ><Loader2
                                        v-if="busy === 'preview'"
                                        class="size-4 animate-spin"
                                    />Preview with fictional order</Button
                                >
                            </div>
                            <div v-if="preview" class="space-y-3">
                                <p
                                    v-if="previewStale"
                                    class="text-xs text-amber-700"
                                >
                                    The draft changed. Generate a new preview to
                                    see the latest version.
                                </p>
                                <p class="text-sm">
                                    <span class="text-muted-foreground"
                                        >Subject: </span
                                    ><strong class="font-semibold">{{
                                        preview.subject
                                    }}</strong>
                                </p>
                                <DocumentEmailPreview :html="preview.html" />
                                <details class="text-sm">
                                    <summary
                                        class="cursor-pointer text-muted-foreground"
                                    >
                                        Plain-text alternative
                                    </summary>
                                    <pre
                                        class="mt-2 rounded-lg bg-muted/20 p-3 font-sans text-sm whitespace-pre-wrap"
                                        >{{ preview.text }}</pre
                                    >
                                </details>
                                <p class="text-xs text-muted-foreground">
                                    Fictional order DEMO-1042. This preview does
                                    not send emails or update orders. Actual
                                    email-client rendering still needs testing.
                                </p>
                            </div>
                        </template>
                        <template v-if="tab === 'activation'">
                            <div class="space-y-3 rounded-xl border p-4">
                                <h3 class="font-semibold">
                                    Readiness for {{ selected.name }}
                                </h3>
                                <ul
                                    v-if="selected.readiness.missing.length"
                                    class="list-disc space-y-1 pl-5 text-sm text-muted-foreground"
                                >
                                    <li
                                        v-for="issue in selected.readiness
                                            .missing"
                                        :key="issue"
                                    >
                                        {{ issue }}
                                    </li>
                                </ul>
                                <p
                                    v-else
                                    class="flex items-center gap-2 text-sm"
                                >
                                    <CheckCircle2
                                        class="size-4 text-emerald-600"
                                    />Brand and sender configuration is ready.
                                </p>
                                <p class="text-sm">
                                    <strong class="font-medium"
                                        >Completion suppression:</strong
                                    >
                                    {{
                                        selected.readiness.completion_supported
                                            ? 'Verified store integration'
                                            : 'Completion suppression not configured. Send-only fallback remains available.'
                                    }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    Platform: {{ selected.readiness.platform }}.
                                    A capability check reads the integration
                                    state; it does not install a plugin or
                                    change store notifications.
                                </p>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    :disabled="dirty"
                                    @click="checkStore"
                                    >Check store capability</Button
                                >
                                <p
                                    v-if="dirty"
                                    class="text-xs text-muted-foreground"
                                >
                                    Save the draft before checking or testing.
                                </p>
                                <div
                                    v-if="
                                        selected.readiness.platform ===
                                        'woocommerce'
                                    "
                                    class="space-y-3 border-t pt-3"
                                >
                                    <label
                                        v-if="draft.store_pairing_key"
                                        class="setting-label"
                                        >Companion plugin website
                                        identifier<input
                                            :value="draft.store_pairing_key"
                                            readonly
                                            class="setting-input font-mono text-xs"
                                            @focus="
                                                (
                                                    $event.target as HTMLInputElement
                                                ).select()
                                            "
                                        /><span class="setting-help"
                                            >Use this website identifier when
                                            pairing the reusable WPHub
                                            WooCommerce companion plugin.</span
                                        ></label
                                    >
                                    <p class="text-sm font-medium">
                                        Apply the saved acknowledgement branding
                                        to the store
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        This writes the saved configuration to
                                        the installed companion plugin. When
                                        this workflow is active, the store will
                                        use it for its processing and
                                        pending-payment emails. Saving or
                                        activating in WP Hub alone does not
                                        apply it.
                                    </p>
                                    <label
                                        class="flex items-start gap-2 text-sm"
                                        ><input
                                            v-model="storeBrandingConfirmed"
                                            type="checkbox"
                                            class="mt-0.5 size-4 shrink-0 accent-primary"
                                        /><span
                                            >I approve applying this website’s
                                            saved notification configuration to
                                            its store.</span
                                        ></label
                                    >
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        :disabled="
                                            dirty || !storeBrandingConfirmed
                                        "
                                        @click="applyStoreBranding"
                                        >Apply saved configuration to
                                        store</Button
                                    >
                                </div>
                            </div>
                            <div class="space-y-4 rounded-xl border p-4">
                                <div>
                                    <h3 class="font-semibold">
                                        Send a harmless test
                                    </h3>
                                    <p
                                        class="mt-1 text-sm text-muted-foreground"
                                    >
                                        Uses the saved profile, a fictional
                                        customer and sample PDFs. No real order
                                        is completed and no store notification
                                        is triggered.
                                    </p>
                                </div>
                                <div class="grid gap-3 sm:grid-cols-3">
                                    <label class="setting-label"
                                        >Template<select
                                            v-model="template"
                                            class="setting-input"
                                        >
                                            <option
                                                v-for="(
                                                    title, key
                                                ) in templateNames"
                                                :key="key"
                                                :value="key"
                                            >
                                                {{ title }}
                                            </option>
                                        </select></label
                                    ><label class="setting-label"
                                        >Language<select
                                            v-model="language"
                                            class="setting-input"
                                        >
                                            <option value="fr">Français</option>
                                            <option value="en">English</option>
                                        </select></label
                                    ><label class="setting-label"
                                        >Sample PDFs<select
                                            v-model="attachmentCount"
                                            class="setting-input"
                                        >
                                            <option :value="1">1 PDF</option>
                                            <option :value="2">2 PDFs</option>
                                        </select></label
                                    >
                                </div>
                                <label class="setting-label"
                                    >Explicit test recipient<input
                                        v-model="testRecipient"
                                        type="email"
                                        class="setting-input"
                                        placeholder="Your test inbox"
                                        autocomplete="off" /></label
                                ><label class="flex items-start gap-2 text-sm"
                                    ><input
                                        v-model="testConfirmed"
                                        type="checkbox"
                                        class="mt-0.5 size-4 shrink-0 accent-primary"
                                    /><span
                                        >I approve sending this test only to
                                        {{
                                            testRecipient ||
                                            'the address entered above'
                                        }}.</span
                                    ></label
                                ><Button
                                    type="button"
                                    :disabled="
                                        dirty ||
                                        testUnconfirmed ||
                                        !testConfirmed ||
                                        !testRecipient.trim() ||
                                        attachmentCount < 1
                                    "
                                    @click="sendTest"
                                    ><Send class="size-4" />Send test
                                    email</Button
                                >
                                <p class="text-xs text-muted-foreground">
                                    Last result: {{ lastTest(selected) }}
                                </p>
                                <p
                                    v-if="testUnconfirmed"
                                    role="status"
                                    class="text-sm text-amber-700"
                                >
                                    The test result is not confirmed. Check the
                                    entered test inbox before sending another
                                    test. This request is not automatically
                                    retried.
                                </p>
                            </div>
                            <div
                                class="space-y-3 rounded-xl border border-primary/20 bg-primary/5 p-4"
                            >
                                <h3 class="font-semibold">
                                    {{
                                        selected.profile.workflow_enabled
                                            ? 'Workflow is active'
                                            : 'Activate this website explicitly'
                                    }}
                                </h3>
                                <p class="text-sm text-muted-foreground">
                                    Activation changes the document composer for
                                    this website. Existing deliveries keep their
                                    saved versions and history. Test the store
                                    acknowledgement and silent completion on
                                    staging before live use.
                                </p>
                                <template
                                    v-if="!selected.profile.workflow_enabled"
                                    ><label
                                        class="flex items-start gap-2 text-sm"
                                        ><input
                                            v-model="activationConfirmed"
                                            type="checkbox"
                                            class="mt-0.5 size-4 shrink-0 accent-primary"
                                        /><span
                                            >I reviewed the sender, branding,
                                            templates and test results for
                                            {{ selected.name }}.</span
                                        ></label
                                    ><Button
                                        type="button"
                                        :disabled="
                                            dirty ||
                                            !activationConfirmed ||
                                            !selected.readiness.ready
                                        "
                                        @click="save(true)"
                                        >Activate for
                                        {{ selected.name }}</Button
                                    ></template
                                ><Button
                                    v-else
                                    type="button"
                                    variant="outline"
                                    @click="disableWorkflow"
                                    >Disable this workflow</Button
                                >
                            </div>
                        </template>
                    </fieldset>
                    <footer
                        class="flex flex-wrap items-center justify-between gap-3 border-t pt-4"
                    >
                        <p class="text-xs text-muted-foreground">
                            {{
                                draft.workflow_enabled
                                    ? 'Saved changes apply to new previews. Existing sent snapshots are preserved.'
                                    : 'Saving a draft does not activate this workflow or change store notifications.'
                            }}
                        </p>
                        <Button :disabled="!!busy || !dirty" @click="save()"
                            ><Loader2
                                v-if="busy === 'save'"
                                class="size-4 animate-spin"
                            />{{
                                draft.workflow_enabled
                                    ? 'Save changes'
                                    : 'Save draft'
                            }}</Button
                        >
                    </footer>
                </div>
            </section>
        </SettingsLayout>
    </AppLayout>
</template>

<style scoped>
@reference "../../../css/app.css";
.setting-label {
    @apply block space-y-1.5 text-sm font-medium;
}
.setting-input {
    @apply block w-full min-w-0 rounded-md border bg-background px-3 py-2 text-sm font-normal outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-60;
}
.setting-help {
    @apply block text-xs leading-relaxed font-normal text-muted-foreground;
}
</style>
