<script setup lang="ts">
import MarketingLayout from '@/components/MarketingLayout.vue';
import MarketingPagination from '@/components/MarketingPagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { marketingSegments } from '@/lib/marketing';
import type {
    MarketingCommon,
    MarketingContent,
    MarketingPage,
    MarketingTemplate,
} from '@/types/marketing';
import { router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { Eye, Plus, Save } from 'lucide-vue-next';
import { ref, watch } from 'vue';
const props = defineProps<
    MarketingCommon & {
        websiteId: number;
        templates: MarketingPage<MarketingTemplate>;
    }
>();
const initial = (): MarketingContent & {
    website_id: number;
    name: string;
} => ({
    website_id: props.websiteId,
    name: '',
    locale: 'fr',
    sender_email: '',
    sender_name:
        props.websites.find((s) => s.id === props.websiteId)?.name || '',
    reply_to: '',
    subject: '',
    preheader: '',
    headline: '',
    body: 'Bonjour,\n\n',
    signature: props.websites.find((s) => s.id === props.websiteId)?.name || '',
    postal_address: '',
    logo_url: '',
    accent_color: '#0f766e',
    cta_text: '',
    cta_url: '',
});
const form = useForm(initial());
const editing = ref<number | null>(null);
const previewHtml = ref(''),
    previewError = ref(''),
    previewBusy = ref(false);
const campaignTemplate = ref<MarketingTemplate | null>(null);
const campaign = useForm({
    template_id: 0,
    name: '',
    locale: '',
    segment: 'all',
});
const fields: {
    key: keyof MarketingContent;
    label: string;
    type?: string;
    required?: boolean;
}[] = [
    { key: 'sender_name', label: 'Sender name', required: true },
    { key: 'reply_to', label: 'Reply-to email', type: 'email', required: true },
    { key: 'subject', label: 'Subject', required: true },
    { key: 'preheader', label: 'Inbox preview text' },
    { key: 'headline', label: 'Email heading', required: true },
    { key: 'logo_url', label: 'Logo URL (HTTPS)', type: 'url' },
    { key: 'cta_text', label: 'Button text' },
    { key: 'cta_url', label: 'Button destination (HTTPS)', type: 'url' },
];
const reset = () => {
    editing.value = null;
    Object.assign(form, initial());
    form.clearErrors();
    previewHtml.value = '';
};
watch(() => props.websiteId, reset);
const edit = (template: MarketingTemplate) => {
    reset();
    editing.value = template.id;
    Object.assign(
        form,
        Object.fromEntries(
            Object.entries(template.content).map(([k, v]) => [k, v ?? '']),
        ),
        { name: template.name, website_id: template.website_id },
    );
};
const save = () => {
    const opts = {
        preserveScroll: true,
        onSuccess: () => {
            previewHtml.value = '';
        },
    };
    if (editing.value) form.put('/marketing/templates/' + editing.value, opts);
    else form.post('/marketing/templates', opts);
};
const preview = async () => {
    previewBusy.value = true;
    previewError.value = '';
    try {
        const { data } = await axios.post('/marketing/preview', form.data());
        previewHtml.value = data.html;
    } catch (error) {
        previewError.value = axios.isAxiosError(error)
            ? Object.values(error.response?.data?.errors || {})
                  .flat()
                  .join(' ') ||
              'Preview could not load. Check the form and try again.'
            : 'Preview could not load.';
    } finally {
        previewBusy.value = false;
    }
};
const useTemplate = (template: MarketingTemplate) => {
    campaignTemplate.value = template;
    campaign.template_id = template.id;
    campaign.name = template.name;
    campaign.locale = template.content.locale;
    campaign.segment = 'all';
};
</script>
<template>
    <MarketingLayout
        title="Branded templates"
        description="Give every website its own voice, sender and signature."
        active="Templates"
        :connected="connected"
    >
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <label
                    for="template-site"
                    class="mb-2 block text-sm font-medium"
                    >Website</label
                ><select
                    id="template-site"
                    :value="websiteId"
                    class="h-10 rounded-lg border bg-background px-3 text-sm"
                    @change="
                        router.get('/marketing/templates', {
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
            <Button variant="outline" @click="reset"
                ><Plus class="size-4" />New template</Button
            >
        </div>
        <div
            v-if="!websiteId"
            class="rounded-xl border p-8 text-center text-muted-foreground"
        >
            Add a website to start creating templates.
        </div>
        <div
            v-else
            class="grid items-start gap-6 xl:grid-cols-[minmax(0,1.6fr)_minmax(300px,1fr)]"
        >
            <form
                class="space-y-6 rounded-xl border bg-card p-5 sm:p-6"
                @submit.prevent="save"
            >
                <div>
                    <h2 class="text-lg font-semibold">
                        {{ editing ? 'Edit template' : 'Create a template' }}
                    </h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Messages are formatted automatically. Write in plain
                        text and add an optional button.
                    </p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label
                            for="template-name"
                            class="mb-2 block text-sm font-medium"
                            >Template name</label
                        ><Input
                            id="template-name"
                            v-model="form.name"
                            required
                            maxlength="120"
                            placeholder="Returning customers · French"
                        />
                    </div>
                    <div>
                        <label
                            for="template-language"
                            class="mb-2 block text-sm font-medium"
                            >Language</label
                        ><select
                            id="template-language"
                            v-model="form.locale"
                            class="h-10 w-full rounded-md border bg-background px-3 text-sm"
                        >
                            <option value="fr">French</option>
                            <option value="en">English</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label
                        for="template-sender"
                        class="mb-2 block text-sm font-medium"
                        >From email</label
                    ><Input
                        id="template-sender"
                        v-model="form.sender_email"
                        type="email"
                        list="marketing-senders"
                        required
                        placeholder="news@your-website.com"
                    /><datalist id="marketing-senders">
                        <option
                            v-for="sender in senders"
                            :key="sender.email"
                            :value="sender.email"
                        >
                            {{ sender.name }}
                        </option>
                    </datalist>
                    <p class="mt-1.5 text-xs text-muted-foreground">
                        Use a sender verified in your Brevo account.
                    </p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div v-for="field in fields" :key="field.key">
                        <label
                            :for="'template-' + field.key"
                            class="mb-2 block text-sm font-medium"
                            >{{ field.label }}</label
                        ><Input
                            :id="'template-' + field.key"
                            v-model="form[field.key]"
                            :type="field.type || 'text'"
                            :required="field.required"
                        />
                    </div>
                </div>
                <div>
                    <label
                        for="template-body"
                        class="mb-2 block text-sm font-medium"
                        >Message</label
                    ><textarea
                        id="template-body"
                        v-model="form.body"
                        required
                        rows="9"
                        maxlength="15000"
                        class="w-full rounded-lg border bg-background p-3 text-sm leading-6"
                    />
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label
                            for="template-signature"
                            class="mb-2 block text-sm font-medium"
                            >Signature</label
                        ><textarea
                            id="template-signature"
                            v-model="form.signature"
                            required
                            rows="4"
                            class="w-full rounded-lg border bg-background p-3 text-sm"
                        />
                    </div>
                    <div>
                        <label
                            for="template-address"
                            class="mb-2 block text-sm font-medium"
                            >Business postal address</label
                        ><textarea
                            id="template-address"
                            v-model="form.postal_address"
                            required
                            rows="4"
                            class="w-full rounded-lg border bg-background p-3 text-sm"
                        />
                    </div>
                </div>
                <div>
                    <label
                        for="template-color"
                        class="mb-2 block text-sm font-medium"
                        >Brand color</label
                    >
                    <div class="flex items-center gap-3">
                        <input
                            id="template-color"
                            v-model="form.accent_color"
                            type="color"
                            class="size-10 rounded border"
                        /><span class="text-sm text-muted-foreground">{{
                            form.accent_color
                        }}</span>
                    </div>
                </div>
                <p class="text-xs text-muted-foreground">
                    Every campaign includes a Brevo unsubscribe link. Customer
                    names and documents are not included automatically.
                </p>
                <p
                    v-if="previewError"
                    role="alert"
                    class="text-sm text-red-600"
                >
                    {{ previewError }}
                </p>
                <div class="flex flex-wrap gap-3">
                    <Button :disabled="form.processing"
                        ><Save class="size-4" />{{
                            form.processing ? 'Saving…' : 'Save template'
                        }}</Button
                    ><Button
                        type="button"
                        variant="outline"
                        :disabled="previewBusy"
                        @click="preview"
                        ><Eye class="size-4" />{{
                            previewBusy ? 'Loading…' : 'Preview email'
                        }}</Button
                    >
                </div>
            </form>
            <div class="space-y-6">
                <div
                    v-if="campaignTemplate"
                    class="rounded-xl border border-teal-300 bg-card p-5"
                >
                    <div class="flex justify-between gap-3">
                        <h2 class="font-semibold">Create campaign</h2>
                        <button
                            type="button"
                            class="text-sm text-muted-foreground"
                            @click="campaignTemplate = null"
                        >
                            Close
                        </button>
                    </div>
                    <p class="mt-2 text-sm text-muted-foreground">
                        Using saved template: {{ campaignTemplate.name }}
                    </p>
                    <form
                        class="mt-5 space-y-4"
                        @submit.prevent="campaign.post('/marketing/campaigns')"
                    >
                        <div>
                            <label
                                for="campaign-name"
                                class="mb-2 block text-sm font-medium"
                                >Campaign name</label
                            ><Input
                                id="campaign-name"
                                v-model="campaign.name"
                                required
                                maxlength="120"
                            />
                        </div>
                        <div>
                            <label
                                for="campaign-language"
                                class="mb-2 block text-sm font-medium"
                                >Subscriber language</label
                            ><select
                                id="campaign-language"
                                v-model="campaign.locale"
                                class="h-10 w-full rounded-lg border bg-background px-3 text-sm"
                            >
                                <option value="">
                                    All languages, including unknown
                                </option>
                                <option value="fr">French</option>
                                <option value="en">English</option>
                            </select>
                        </div>
                        <div>
                            <label
                                for="campaign-segment"
                                class="mb-2 block text-sm font-medium"
                                >Audience</label
                            ><select
                                id="campaign-segment"
                                v-model="campaign.segment"
                                class="h-10 w-full rounded-lg border bg-background px-3 text-sm"
                            >
                                <option
                                    v-for="segment in marketingSegments"
                                    :key="segment.value"
                                    :value="segment.value"
                                >
                                    {{ segment.label }}
                                </option>
                            </select>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            The next screen previews your message and eligible
                            subscribers. Creating a draft does not send email.
                        </p>
                        <Button :disabled="campaign.processing"
                            >Create draft & review</Button
                        >
                    </form>
                </div>
                <div class="overflow-hidden rounded-xl border bg-card">
                    <div class="border-b px-5 py-4">
                        <h2 class="font-semibold">Saved templates</h2>
                    </div>
                    <div
                        v-if="!templates.data.length"
                        class="p-6 text-sm text-muted-foreground"
                    >
                        Your saved templates will appear here. Save your first
                        template, then choose Use template to create a campaign.
                    </div>
                    <div
                        v-for="template in templates.data"
                        :key="template.id"
                        class="space-y-3 border-b p-5 last:border-b-0"
                    >
                        <div>
                            <h3 class="font-medium">{{ template.name }}</h3>
                            <p class="mt-1 text-sm text-muted-foreground">
                                {{
                                    template.content.locale === 'fr'
                                        ? 'French'
                                        : 'English'
                                }}
                                · {{ template.content.subject }}
                            </p>
                        </div>
                        <div class="flex gap-3">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                @click="edit(template)"
                                >Edit</Button
                            ><Button
                                type="button"
                                size="sm"
                                @click="useTemplate(template)"
                                >Use template</Button
                            >
                        </div>
                    </div>
                    <MarketingPagination
                        v-if="templates.last_page > 1"
                        :page="templates"
                    />
                </div>
                <div
                    v-if="previewHtml"
                    class="overflow-hidden rounded-xl border bg-slate-100"
                >
                    <h2 class="border-b bg-card px-5 py-4 font-semibold">
                        Email preview
                    </h2>
                    <div
                        class="marketing-preview overflow-x-auto p-2"
                        v-html="previewHtml"
                    />
                </div>
            </div>
        </div>
    </MarketingLayout>
</template>
