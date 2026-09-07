<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { Check, Copy, ExternalLink, LoaderCircle, Mail } from 'lucide-vue-next';
import { computed, onUnmounted, reactive, ref, watch } from 'vue';

interface WebsiteEmailSettings {
    id: number;
    name: string;
    base_url: string;
    sender_email: string;
    subject_template: string;
    body_template: string;
    signature: string;
}

interface EmailSettings {
    app: {
        configured: boolean;
        client_id: string | null;
        has_secret: boolean;
        redirect_uri: string;
        can_manage: boolean;
    };
    connection: {
        connected: boolean;
        email: string | null;
        aliases: Array<{ email: string; name: string }>;
    };
    websites: WebsiteEmailSettings[];
    placeholders: string[];
}

type WebsiteDraft = Pick<
    WebsiteEmailSettings,
    'sender_email' | 'subject_template' | 'body_template' | 'signature'
>;
type Action = 'application' | 'connect' | 'disconnect' | 'aliases' | 'website';
type FlashPage = { props: { flash?: { error?: string; success?: string } } };

const props = defineProps<{ emailSettings: EmailSettings }>();
const page = usePage();
const breadcrumbItems: BreadcrumbItem[] = [
    { title: 'Email settings', href: '/settings/email' },
];
const appForm = reactive({
    client_id: props.emailSettings.app.client_id ?? '',
    client_secret: '',
});
const appErrors = ref<Record<string, string>>({});
const drafts = reactive<Record<number, WebsiteDraft>>({});
const websiteErrors = reactive<Record<number, Record<string, string>>>({});
const selectedWebsiteId = ref<number | null>(
    props.emailSettings.websites[0]?.id ?? null,
);
const busy = ref<Action | null>(null);
const initialFlash = page.props.flash as
    | { error?: string; success?: string }
    | undefined;
const errorMessage = ref(initialFlash?.error ?? '');
const successMessage = ref(
    initialFlash?.error ? '' : (initialFlash?.success ?? ''),
);
const copyMessage = ref('');
const connectController = new AbortController();
let disposed = false;

watch(
    () => props.emailSettings.websites,
    (websites) => {
        for (const website of websites) {
            if (!drafts[website.id]) {
                drafts[website.id] = {
                    sender_email: website.sender_email ?? '',
                    subject_template: website.subject_template ?? '',
                    body_template: website.body_template ?? '',
                    signature: website.signature ?? '',
                };
                websiteErrors[website.id] = {};
            }
        }
        if (
            !websites.some((website) => website.id === selectedWebsiteId.value)
        ) {
            selectedWebsiteId.value = websites[0]?.id ?? null;
        }
    },
    { immediate: true },
);

const selectedWebsite = computed(() =>
    props.emailSettings.websites.find(
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
const aliases = computed(() =>
    props.emailSettings.connection.connected
        ? props.emailSettings.connection.aliases
        : [],
);
const senderIsVerified = computed(() =>
    aliases.value.some(
        (alias) => alias.email === websiteForm.value?.sender_email,
    ),
);
const secretRequired = computed(
    () =>
        !props.emailSettings.app.has_secret ||
        appForm.client_id.trim() !== props.emailSettings.app.client_id,
);

function startAction(action: Action): boolean {
    if (busy.value || disposed) return false;
    busy.value = action;
    errorMessage.value = '';
    successMessage.value = '';
    return true;
}

function finishRedirect(response: FlashPage, fallback: string): boolean {
    const flash = response.props.flash;
    if (flash?.error) {
        errorMessage.value = flash.error;
        successMessage.value = '';
        return false;
    }
    successMessage.value = flash?.success ?? fallback;
    return true;
}

function saveApplication(): void {
    if (!props.emailSettings.app.can_manage || !startAction('application'))
        return;
    appErrors.value = {};
    router.put(
        '/settings/email/application',
        { ...appForm },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (response) => {
                if (
                    finishRedirect(
                        response,
                        'Google application settings saved.',
                    )
                )
                    appForm.client_secret = '';
            },
            onError: (errors) => {
                appErrors.value = errors;
                errorMessage.value =
                    'Application settings were not saved. Review the fields below.';
            },
            onFinish: () => {
                busy.value = null;
            },
        },
    );
}

async function connectMailbox(): Promise<void> {
    if (!props.emailSettings.app.configured || !startAction('connect')) return;
    try {
        const response = await axios.post(
            '/settings/email/connect',
            {},
            {
                headers: { Accept: 'application/json' },
                signal: connectController.signal,
                timeout: 20000,
            },
        );
        if (disposed) return;
        const destination = new URL(response.data.url);
        if (destination.origin !== 'https://accounts.google.com')
            throw new Error('Invalid authorization URL');
        window.location.assign(destination.href);
    } catch {
        if (!disposed)
            errorMessage.value =
                'Google connection could not be started. Please try again.';
        busy.value = null;
    }
}

function changeConnection(action: 'disconnect' | 'aliases'): void {
    if (!props.emailSettings.connection.connected || !startAction(action))
        return;
    const options = {
        preserveScroll: true,
        preserveState: true,
        onSuccess: (response: FlashPage) =>
            finishRedirect(
                response,
                action === 'disconnect'
                    ? 'Gmail disconnected.'
                    : 'Sender addresses refreshed.',
            ),
        onError: (errors: Record<string, string>) => {
            errorMessage.value =
                Object.values(errors)[0] ??
                'Mailbox settings could not be updated. Please try again.';
        },
        onFinish: () => {
            busy.value = null;
        },
    };
    if (action === 'disconnect')
        router.delete('/settings/email/connection', options);
    else router.post('/settings/email/aliases', {}, options);
}

function saveWebsite(): void {
    const website = selectedWebsite.value;
    const draft = websiteForm.value;
    if (
        !website ||
        !draft ||
        !senderIsVerified.value ||
        !startAction('website')
    )
        return;
    websiteErrors[website.id] = {};
    router.put(
        `/settings/email/websites/${website.id}`,
        { ...draft },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (response) => {
                finishRedirect(
                    response,
                    `Email settings saved for ${website.name}.`,
                );
            },
            onError: (errors) => {
                websiteErrors[website.id] = errors;
                errorMessage.value = `Email settings for ${website.name} were not saved. Review the fields below.`;
            },
            onFinish: () => {
                busy.value = null;
            },
        },
    );
}

async function copyCallback(): Promise<void> {
    try {
        await navigator.clipboard.writeText(
            props.emailSettings.app.redirect_uri,
        );
        copyMessage.value = 'Callback URL copied.';
    } catch {
        copyMessage.value = 'Select the callback URL and copy it manually.';
    }
}

onUnmounted(() => {
    disposed = true;
    connectController.abort();
});
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Email settings" />
        <SettingsLayout>
            <div class="space-y-8">
                <HeadingSmall
                    title="Email"
                    description="Connect Gmail and prepare the messages you send with order documents."
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
                    class="flex items-start gap-2 rounded-lg border border-emerald-500/30 bg-emerald-500/5 p-3 text-sm text-emerald-700 dark:text-emerald-400"
                >
                    <Check class="mt-0.5 size-4 shrink-0" />{{ successMessage }}
                </p>

                <section
                    class="space-y-5 rounded-xl border p-5"
                    aria-labelledby="mailbox-heading"
                >
                    <div
                        class="flex flex-wrap items-start justify-between gap-3"
                    >
                        <div class="flex items-start gap-3">
                            <div class="rounded-lg bg-muted p-2">
                                <Mail class="size-5" />
                            </div>
                            <div>
                                <h2 id="mailbox-heading" class="font-medium">
                                    Your Gmail connection
                                </h2>
                                <p class="mt-1 text-sm text-muted-foreground">
                                    {{
                                        emailSettings.connection.connected
                                            ? emailSettings.connection.email
                                            : 'Connect the mailbox you use to send order documents.'
                                    }}
                                </p>
                            </div>
                        </div>
                        <span
                            class="rounded-full bg-muted px-2.5 py-1 text-xs font-medium"
                            >{{
                                emailSettings.connection.connected
                                    ? 'Connected'
                                    : 'Not connected'
                            }}</span
                        >
                    </div>
                    <template v-if="emailSettings.connection.connected">
                        <div class="space-y-2">
                            <h3 class="text-sm font-medium">
                                Verified sender addresses
                            </h3>
                            <ul
                                v-if="aliases.length"
                                class="space-y-1 text-sm text-muted-foreground"
                            >
                                <li
                                    v-for="alias in aliases"
                                    :key="alias.email"
                                    class="break-all"
                                >
                                    {{ alias.name ? `${alias.name} · ` : ''
                                    }}{{ alias.email }}
                                </li>
                            </ul>
                            <p v-else class="text-sm text-muted-foreground">
                                No verified sender addresses are available.
                                Refresh to check your mailbox.
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <Button
                                variant="outline"
                                :disabled="!!busy"
                                @click="changeConnection('aliases')"
                                ><LoaderCircle
                                    v-if="busy === 'aliases'"
                                    class="size-4 animate-spin"
                                />Refresh senders</Button
                            >
                            <Button
                                variant="ghost"
                                :disabled="!!busy"
                                @click="changeConnection('disconnect')"
                                ><LoaderCircle
                                    v-if="busy === 'disconnect'"
                                    class="size-4 animate-spin"
                                />Disconnect</Button
                            >
                        </div>
                    </template>
                    <template v-else>
                        <Button
                            :disabled="!!busy || !emailSettings.app.configured"
                            @click="connectMailbox"
                            ><LoaderCircle
                                v-if="busy === 'connect'"
                                class="size-4 animate-spin"
                            />{{
                                busy === 'connect'
                                    ? 'Opening Google…'
                                    : 'Connect Gmail'
                            }}</Button
                        >
                        <p
                            v-if="!emailSettings.app.configured"
                            class="text-sm text-muted-foreground"
                        >
                            {{
                                emailSettings.app.can_manage
                                    ? 'Set up the Google application below before connecting.'
                                    : 'An administrator needs to set up the Google application before you can connect.'
                            }}
                        </p>
                    </template>
                </section>

                <section
                    class="space-y-5"
                    aria-labelledby="website-email-heading"
                >
                    <div>
                        <h2 id="website-email-heading" class="font-medium">
                            Website email defaults
                        </h2>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Choose a sender and reusable message for each
                            website. You can review the email before sending.
                        </p>
                    </div>
                    <p
                        v-if="!emailSettings.websites.length"
                        class="rounded-lg border border-dashed p-4 text-sm text-muted-foreground"
                    >
                        No websites are available to configure.
                    </p>
                    <template v-else>
                        <div class="grid gap-2">
                            <Label for="email-website">Website</Label>
                            <select
                                id="email-website"
                                v-model="selectedWebsiteId"
                                :disabled="!!busy"
                                class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50"
                            >
                                <option
                                    v-for="website in emailSettings.websites"
                                    :key="website.id"
                                    :value="website.id"
                                >
                                    {{ website.name }}
                                </option>
                            </select>
                            <p class="text-xs break-all text-muted-foreground">
                                {{ selectedWebsite?.base_url }}
                            </p>
                        </div>
                        <form
                            v-if="websiteForm"
                            class="space-y-5"
                            @submit.prevent="saveWebsite"
                        >
                            <fieldset
                                :disabled="!!busy"
                                class="space-y-5 disabled:opacity-70"
                            >
                                <div class="grid gap-2">
                                    <Label for="email-sender"
                                        >Sender address</Label
                                    >
                                    <select
                                        id="email-sender"
                                        v-model="websiteForm.sender_email"
                                        :disabled="!aliases.length"
                                        required
                                        class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50"
                                    >
                                        <option value="" disabled>
                                            Choose a verified sender
                                        </option>
                                        <option
                                            v-for="alias in aliases"
                                            :key="alias.email"
                                            :value="alias.email"
                                        >
                                            {{
                                                alias.name
                                                    ? `${alias.name} — ${alias.email}`
                                                    : alias.email
                                            }}
                                        </option>
                                    </select>
                                    <p
                                        v-if="
                                            !emailSettings.connection.connected
                                        "
                                        class="text-xs text-muted-foreground"
                                    >
                                        Connect Gmail to choose a sender and
                                        save these defaults.
                                    </p>
                                    <p
                                        v-else-if="
                                            websiteForm.sender_email &&
                                            !senderIsVerified
                                        "
                                        class="text-xs text-destructive"
                                    >
                                        The saved sender
                                        {{ websiteForm.sender_email }} is
                                        unavailable. Choose a verified address.
                                    </p>
                                    <p
                                        v-else
                                        class="text-xs text-muted-foreground"
                                    >
                                        Only verified addresses from your
                                        connected Gmail account are available.
                                    </p>
                                    <InputError
                                        :message="selectedErrors.sender_email"
                                    />
                                </div>
                                <div class="grid gap-2">
                                    <Label for="email-subject"
                                        >Subject template</Label
                                    >
                                    <Input
                                        id="email-subject"
                                        v-model="websiteForm.subject_template"
                                        required
                                        maxlength="255"
                                        autocomplete="off"
                                    />
                                    <InputError
                                        :message="
                                            selectedErrors.subject_template
                                        "
                                    />
                                </div>
                                <div class="grid gap-2">
                                    <Label for="email-body"
                                        >Message template</Label
                                    >
                                    <textarea
                                        id="email-body"
                                        v-model="websiteForm.body_template"
                                        rows="7"
                                        required
                                        class="w-full resize-y rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                    />
                                    <InputError
                                        :message="selectedErrors.body_template"
                                    />
                                </div>
                                <div
                                    class="rounded-lg bg-muted/50 p-3 text-xs text-muted-foreground"
                                >
                                    <p>
                                        Use these placeholders in the subject or
                                        message:
                                    </p>
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        <code
                                            v-for="placeholder in emailSettings.placeholders"
                                            :key="placeholder"
                                            class="rounded border bg-background px-1.5 py-1 text-foreground"
                                            >{{ placeholder }}</code
                                        >
                                    </div>
                                </div>
                                <div class="grid gap-2">
                                    <Label for="email-signature"
                                        >Signature
                                        <span
                                            class="font-normal text-muted-foreground"
                                            >(optional)</span
                                        ></Label
                                    >
                                    <textarea
                                        id="email-signature"
                                        v-model="websiteForm.signature"
                                        rows="4"
                                        class="w-full resize-y rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                    />
                                    <p class="text-xs text-muted-foreground">
                                        Added below your message. Templates and
                                        signatures use plain text.
                                    </p>
                                    <InputError
                                        :message="selectedErrors.signature"
                                    />
                                </div>
                            </fieldset>
                            <Button
                                type="submit"
                                :disabled="!!busy || !senderIsVerified"
                                ><LoaderCircle
                                    v-if="busy === 'website'"
                                    class="size-4 animate-spin"
                                />Save website defaults</Button
                            >
                        </form>
                    </template>
                </section>

                <section
                    v-if="emailSettings.app.can_manage"
                    class="space-y-5 border-t pt-8"
                    aria-labelledby="google-app-heading"
                >
                    <div
                        class="flex flex-wrap items-start justify-between gap-3"
                    >
                        <div>
                            <h2 id="google-app-heading" class="font-medium">
                                Google application
                            </h2>
                            <p class="mt-1 text-sm text-muted-foreground">
                                Administrator setup for Gmail connections.
                            </p>
                        </div>
                        <span
                            class="rounded-full bg-muted px-2.5 py-1 text-xs font-medium"
                            >{{
                                emailSettings.app.configured
                                    ? 'Configured'
                                    : 'Setup required'
                            }}</span
                        >
                    </div>
                    <details
                        :open="!emailSettings.app.configured"
                        class="rounded-lg border p-4 text-sm"
                    >
                        <summary class="cursor-pointer font-medium">
                            Set up Google Cloud
                        </summary>
                        <ol
                            class="mt-3 list-decimal space-y-3 pl-5 text-muted-foreground"
                        >
                            <li>
                                Create or select a project in
                                <a
                                    href="https://console.cloud.google.com/"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="text-foreground underline underline-offset-4"
                                    >Google Cloud Console</a
                                >
                                and enable the Gmail API.
                            </li>
                            <li>
                                Configure the OAuth consent screen in Google
                                Auth Platform. Choose the audience for your app;
                                while testing, add the Gmail account you will
                                connect as a test user.
                            </li>
                            <li>
                                Create an OAuth client with application type
                                <strong class="font-medium text-foreground"
                                    >Web application</strong
                                >. Add the callback URL below as an authorized
                                redirect URI, exactly as shown.
                            </li>
                            <li>
                                Save the client ID and client secret below, then
                                select Connect Gmail and approve the requested
                                access.
                            </li>
                        </ol>
                        <div class="mt-4 flex flex-wrap gap-4">
                            <a
                                href="https://developers.google.com/workspace/gmail/api/quickstart/js"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex items-center gap-1 underline underline-offset-4"
                                >Gmail API setup<ExternalLink class="size-3"
                            /></a>
                            <a
                                href="https://developers.google.com/identity/protocols/oauth2/web-server"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex items-center gap-1 underline underline-offset-4"
                                >Google OAuth guide<ExternalLink class="size-3"
                            /></a>
                        </div>
                    </details>
                    <div class="grid gap-2">
                        <Label for="google-callback">Callback URL</Label>
                        <div class="flex items-center gap-2">
                            <Input
                                id="google-callback"
                                :model-value="emailSettings.app.redirect_uri"
                                readonly
                                class="min-w-0 font-mono text-xs"
                                @focus="
                                    ($event.target as HTMLInputElement).select()
                                "
                            />
                            <Button
                                variant="outline"
                                size="icon"
                                aria-label="Copy callback URL"
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
                            :disabled="!!busy"
                            class="space-y-5 disabled:opacity-70"
                        >
                            <div class="grid gap-2">
                                <Label for="google-client-id">Client ID</Label>
                                <Input
                                    id="google-client-id"
                                    v-model="appForm.client_id"
                                    required
                                    autocomplete="off"
                                    placeholder="Your Google OAuth client ID"
                                />
                                <InputError :message="appErrors.client_id" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="google-client-secret"
                                    >Client secret</Label
                                >
                                <Input
                                    id="google-client-secret"
                                    v-model="appForm.client_secret"
                                    type="password"
                                    :required="secretRequired"
                                    autocomplete="new-password"
                                    :placeholder="
                                        secretRequired
                                            ? 'Enter the client secret'
                                            : 'Saved secret is kept when left blank'
                                    "
                                />
                                <p class="text-xs text-muted-foreground">
                                    {{
                                        secretRequired
                                            ? 'Enter the secret for this client ID.'
                                            : 'A secret is saved. Leave this blank to keep it for the same client ID.'
                                    }}
                                </p>
                                <InputError
                                    :message="appErrors.client_secret"
                                />
                            </div>
                        </fieldset>
                        <Button type="submit" :disabled="!!busy"
                            ><LoaderCircle
                                v-if="busy === 'application'"
                                class="size-4 animate-spin"
                            />Save application</Button
                        >
                    </form>
                </section>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
