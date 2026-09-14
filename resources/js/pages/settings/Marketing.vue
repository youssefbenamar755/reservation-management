<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { marketingDate } from '@/lib/marketing';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import {
    CheckCircle2,
    ExternalLink,
    LoaderCircle,
    Mail,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
defineProps<{
    connection: {
        connected: boolean;
        has_key: boolean;
        account_email: string | null;
        senders: { email: string; name: string }[];
        verified_at: string | null;
        last_event_at: string | null;
    } | null;
}>();
const form = useForm({ api_key: '' });
const disconnectForm = useForm({});
const confirmDisconnect = ref(false);
const page = usePage();
const errors = computed(() => Object.values(page.props.errors || {}).flat());
const flash = computed(() => page.props.flash as { success?: string });
const save = () =>
    form.post('/settings/marketing/connect', {
        preserveScroll: true,
        onFinish: () => form.reset('api_key'),
    });
</script>
<template>
    <Head title="Marketing settings" /><AppLayout
        :breadcrumbs="[
            { title: 'Settings', href: '/settings/profile' },
            { title: 'Marketing', href: '/settings/marketing' },
        ]"
        ><SettingsLayout>
            <div>
                <h2 class="text-xl font-semibold">Marketing email</h2>
                <p class="mt-2 text-sm text-muted-foreground">
                    Connect Brevo to send branded campaigns from WP Hub.
                </p>
            </div>
            <p
                v-if="flash?.success"
                role="status"
                class="rounded-lg bg-emerald-50 p-4 text-sm text-emerald-900 dark:bg-emerald-950 dark:text-emerald-200"
            >
                {{ flash.success }}
            </p>
            <div
                v-if="errors.length"
                role="alert"
                class="rounded-lg bg-red-50 p-4 text-sm text-red-900 dark:bg-red-950 dark:text-red-200"
            >
                <p v-for="(error, i) in errors" :key="i">{{ error }}</p>
            </div>
            <div class="space-y-5 rounded-xl border p-5">
                <div class="flex items-center gap-3">
                    <div
                        class="rounded-xl bg-teal-50 p-3 text-teal-700 dark:bg-teal-950 dark:text-teal-300"
                    >
                        <Mail class="size-6" />
                    </div>
                    <div>
                        <h3 class="font-semibold">Brevo</h3>
                        <p class="text-sm text-muted-foreground">
                            {{
                                connection?.connected
                                    ? connection.account_email
                                    : 'Ready when you are'
                            }}
                        </p>
                    </div>
                    <CheckCircle2
                        v-if="connection?.connected"
                        class="ml-auto size-5 text-emerald-600"
                    />
                </div>
                <ol
                    class="list-decimal space-y-2 pl-5 text-sm text-muted-foreground"
                >
                    <li>Create or open your Brevo account.</li>
                    <li>
                        Add and authenticate the sender domains for your
                        websites in Brevo.
                    </li>
                    <li>
                        Create an API key and paste it below. Connecting also
                        sets up campaign result notifications.
                    </li>
                </ol>
                <a
                    href="https://app.brevo.com/settings/keys/api"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex items-center gap-2 text-sm font-medium underline underline-offset-4"
                    >Open Brevo API keys<ExternalLink class="size-3.5"
                /></a>
                <form class="space-y-3" @submit.prevent="save">
                    <label for="brevo-key" class="text-sm font-medium"
                        >Brevo API key</label
                    ><Input
                        id="brevo-key"
                        v-model="form.api_key"
                        type="password"
                        autocomplete="new-password"
                        :placeholder="
                            connection?.has_key
                                ? 'Leave empty to refresh the current connection'
                                : 'Paste your Brevo API key'
                        "
                    />
                    <p class="text-xs text-muted-foreground">
                        Stored encrypted. Campaign recipients’ email addresses
                        are shared with this Brevo account when you launch a
                        campaign. Document emails use your existing Gmail
                        connection.
                    </p>
                    <Button :disabled="form.processing"
                        ><LoaderCircle
                            v-if="form.processing"
                            class="size-4 animate-spin"
                        />{{
                            form.processing
                                ? 'Connecting…'
                                : connection?.has_key
                                  ? 'Save & refresh connection'
                                  : 'Connect Brevo'
                        }}</Button
                    >
                </form>
            </div>
            <div v-if="connection?.connected" class="space-y-4">
                <h3 class="font-semibold">Verified senders</h3>
                <p
                    v-if="!connection.senders.length"
                    class="text-sm text-muted-foreground"
                >
                    Add a sender in Brevo, then refresh this connection.
                </p>
                <div
                    v-for="sender in connection.senders"
                    :key="sender.email"
                    class="rounded-lg border p-3 text-sm"
                >
                    <p class="font-medium">{{ sender.name || sender.email }}</p>
                    <p class="break-all text-muted-foreground">
                        {{ sender.email }}
                    </p>
                </div>
                <p class="text-xs text-muted-foreground">
                    Connection checked
                    {{ marketingDate(connection.verified_at) }}. Last campaign
                    event: {{ marketingDate(connection.last_event_at) }}.
                </p>
                <Link
                    href="/marketing"
                    class="inline-flex rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground"
                    >Open Marketing</Link
                >
                <div class="border-t pt-5">
                    <Button
                        variant="outline"
                        @click="confirmDisconnect = !confirmDisconnect"
                        >Disconnect Brevo</Button
                    >
                    <div
                        v-if="confirmDisconnect"
                        class="mt-3 space-y-3 rounded-lg border p-4"
                    >
                        <p class="text-sm">
                            Disconnecting cancels pending WP Hub campaigns.
                            Emails already accepted by Brevo cannot be recalled.
                        </p>
                        <Button
                            variant="destructive"
                            :disabled="disconnectForm.processing"
                            @click="
                                disconnectForm.delete(
                                    '/settings/marketing/connection',
                                    {
                                        onSuccess: () =>
                                            (confirmDisconnect = false),
                                    },
                                )
                            "
                            >Disconnect and cancel pending campaigns</Button
                        >
                    </div>
                </div>
            </div>
        </SettingsLayout></AppLayout
    >
</template>
