<script setup lang="ts">
import MarketingLayout from '@/components/MarketingLayout.vue';
import { Button } from '@/components/ui/button';
import {
    marketingDate,
    marketingSegments,
    marketingSources,
    marketingStatus,
    marketingTone,
} from '@/lib/marketing';
import type { MarketingCampaign, MarketingCommon } from '@/types/marketing';
import { Link, router, useForm } from '@inertiajs/vue3';
import { CalendarClock, CheckCircle2, RefreshCw, Send } from 'lucide-vue-next';
import { computed, ref } from 'vue';
const props = defineProps<
    MarketingCommon & {
        campaign: MarketingCampaign;
        review: {
            count: number;
            limit: number;
            token: string;
            sample: { email: string; name: string | null }[];
        };
        previewHtml: string;
        stats: Record<string, number>;
    }
>();
const delivery = ref('now'),
    scheduleTime = ref(''),
    reviewOpen = ref(false),
    localError = ref('');
const form = useForm({
    review_token: props.review.token,
    confirmed: false,
    scheduled_at: null as string | null,
});
const testForm = useForm({ confirmed: true });
const cancelForm = useForm({});
const senderReady = computed(() =>
    props.senders.some(
        (s) =>
            s.email.toLowerCase() ===
            props.campaign.content.sender_email.toLowerCase(),
    ),
);
const canSchedule = computed(
    () =>
        props.connected &&
        senderReady.value &&
        props.review.count > 0 &&
        props.review.count <= props.review.limit &&
        props.campaign.status === 'draft',
);
const launch = () => {
    localError.value = '';
    if (delivery.value === 'later') {
        const date = new Date(scheduleTime.value);
        if (!Number.isFinite(date.getTime()) || date.getTime() <= Date.now()) {
            localError.value = 'Choose a future date and time.';
            return;
        }
        form.scheduled_at = date.toISOString();
    } else form.scheduled_at = null;
    form.review_token = props.review.token;
    form.post('/marketing/campaigns/' + props.campaign.id + '/schedule', {
        preserveScroll: true,
        onSuccess: () => {
            reviewOpen.value = false;
            form.confirmed = false;
        },
    });
};
</script>
<template>
    <MarketingLayout
        :title="campaign.name"
        description="Review your message, audience and delivery time in one place."
        active="Campaigns"
        :connected="connected"
    >
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-3">
                <span
                    class="rounded-full px-3 py-1.5 text-sm font-medium"
                    :class="marketingTone(campaign.status)"
                    >{{ marketingStatus(campaign.status) }}</span
                ><span class="text-sm text-muted-foreground"
                    >{{
                        websites.find((s) => s.id === campaign.website_id)?.name
                    }}
                    ·
                    {{
                        campaign.content.locale === 'fr' ? 'French' : 'English'
                    }}</span
                >
            </div>
            <Button
                variant="outline"
                @click="
                    router.reload({
                        only: [
                            'campaign',
                            'review',
                            'stats',
                            'connected',
                            'senders',
                        ],
                    })
                "
                ><RefreshCw class="size-4" />Refresh results</Button
            >
        </div>
        <p
            v-if="campaign.result_message"
            role="status"
            class="rounded-xl border bg-muted/40 p-4 text-sm"
        >
            {{ campaign.result_message }}
        </p>
        <div
            v-if="campaign.status !== 'draft'"
            class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4"
        >
            <div
                v-for="item in [
                    { key: 'selected', label: 'Selected recipients' },
                    { key: 'delivered', label: 'Delivery detected' },
                    { key: 'clicked', label: 'Recipients who clicked' },
                    { key: 'unsubscribed', label: 'Unsubscribed' },
                    { key: 'opened', label: 'Open detected (estimate)' },
                    { key: 'bounced', label: 'Bounced' },
                    { key: 'complained', label: 'Spam complaints' },
                    { key: 'skipped', label: 'Excluded before sending' },
                ]"
                :key="item.key"
                class="rounded-xl border bg-card p-4"
            >
                <p class="text-xs text-muted-foreground">{{ item.label }}</p>
                <p class="mt-2 text-2xl font-semibold tabular-nums">
                    {{ stats[item.key] || 0 }}
                </p>
            </div>
        </div>
        <div
            class="grid items-start gap-6 xl:grid-cols-[minmax(0,1.5fr)_minmax(300px,1fr)]"
        >
            <section
                class="overflow-hidden rounded-xl border bg-card"
                aria-label="Campaign preview"
            >
                <div class="space-y-2 border-b p-5">
                    <h2 class="font-semibold">Message preview</h2>
                    <p class="text-sm break-words">
                        <span class="text-muted-foreground">From:</span>
                        {{ campaign.content.sender_name }} &lt;{{
                            campaign.content.sender_email
                        }}&gt;
                    </p>
                    <p class="text-sm break-words">
                        <span class="text-muted-foreground">Subject:</span>
                        {{ campaign.content.subject }}
                    </p>
                    <p class="text-sm break-words text-muted-foreground">
                        {{ campaign.content.preheader }}
                    </p>
                </div>
                <div
                    class="marketing-preview overflow-x-auto bg-slate-100 p-2 sm:p-5"
                    v-html="previewHtml"
                />
            </section>
            <div class="space-y-5">
                <section
                    class="space-y-4 rounded-xl border bg-card p-5"
                    aria-label="Campaign audience"
                >
                    <div>
                        <p class="text-sm text-muted-foreground">
                            {{
                                campaign.status === 'draft'
                                    ? 'Eligible subscribers now'
                                    : 'Reviewed audience'
                            }}
                        </p>
                        <p class="mt-2 text-4xl font-semibold tabular-nums">
                            {{
                                campaign.status === 'draft'
                                    ? review.count.toLocaleString()
                                    : campaign.recipient_count.toLocaleString()
                            }}
                        </p>
                    </div>
                    <p class="text-sm">
                        {{
                            marketingSources.find(
                                (s) =>
                                    s.value ===
                                    (campaign.audience.source || 'all'),
                            )?.label
                        }}
                        ·
                        {{
                            marketingSegments.find(
                                (s) => s.value === campaign.audience.segment,
                            )?.label
                        }}
                        ·
                        {{
                            campaign.audience.locale === 'fr'
                                ? 'French'
                                : campaign.audience.locale === 'en'
                                  ? 'English'
                                  : 'All languages'
                        }}
                    </p>
                    <p class="text-xs leading-relaxed text-muted-foreground">
                        Only recorded subscribers to this website qualify. Brevo
                        suppression takes priority. The recipient selection is
                        fixed when scheduled, and withdrawals are checked before
                        sending.
                    </p>
                    <div
                        v-if="
                            campaign.status === 'draft' && review.sample.length
                        "
                        class="space-y-2 border-t pt-4"
                    >
                        <h3 class="text-sm font-medium">
                            Sample recipients (up to 10)
                        </h3>
                        <p
                            v-for="recipient in review.sample"
                            :key="recipient.email"
                            class="text-xs break-all text-muted-foreground"
                        >
                            {{ recipient.email }}
                        </p>
                    </div>
                    <p
                        v-if="campaign.status === 'draft' && !review.count"
                        class="text-sm text-amber-700 dark:text-amber-300"
                    >
                        No eligible subscribers match. Record permission or
                        adjust the audience in a new draft.
                    </p>
                    <p
                        v-if="
                            review.count > review.limit &&
                            campaign.status === 'draft'
                        "
                        class="text-sm text-amber-700 dark:text-amber-300"
                    >
                        Choose a smaller audience. The first version supports up
                        to {{ review.limit.toLocaleString() }} recipients per
                        campaign.
                    </p>
                    <Link
                        :href="
                            '/marketing/audience?website_id=' +
                            campaign.website_id
                        "
                        class="inline-block text-sm font-medium underline underline-offset-4"
                        >Manage website audience</Link
                    >
                </section>
                <section
                    v-if="campaign.status === 'draft'"
                    class="space-y-4 rounded-xl border bg-card p-5"
                >
                    <h2 class="font-semibold">Test your email</h2>
                    <p class="text-sm break-words text-muted-foreground">
                        Send a test only to your Brevo account address:
                        <strong>{{ testEmail || 'Connect Brevo first' }}</strong
                        >.
                    </p>
                    <p
                        v-if="connected && !senderReady"
                        class="text-sm text-amber-700 dark:text-amber-300"
                    >
                        Verify this sender in Brevo and refresh Marketing
                        settings before sending.
                    </p>
                    <Button
                        variant="outline"
                        :disabled="
                            !connected || !senderReady || testForm.processing
                        "
                        @click="
                            testForm.post(
                                '/marketing/campaigns/' + campaign.id + '/test',
                                { preserveScroll: true },
                            )
                        "
                        ><Send class="size-4" />{{
                            testForm.processing
                                ? 'Requesting test…'
                                : 'Send test to my address'
                        }}</Button
                    >
                    <p
                        v-if="campaign.test_sent_at"
                        class="flex items-center gap-2 text-xs text-muted-foreground"
                    >
                        <CheckCircle2 class="size-4 text-emerald-600" />Test
                        requested {{ marketingDate(campaign.test_sent_at) }}
                    </p>
                </section>
                <section
                    v-if="campaign.status === 'draft'"
                    class="space-y-4 rounded-xl border bg-card p-5"
                >
                    <h2 class="flex items-center gap-2 font-semibold">
                        <CalendarClock class="size-4" />Delivery
                    </h2>
                    <label class="flex items-center gap-2 text-sm"
                        ><input
                            v-model="delivery"
                            type="radio"
                            value="now"
                            name="delivery"
                        />Send as soon as preparation finishes</label
                    ><label class="flex items-center gap-2 text-sm"
                        ><input
                            v-model="delivery"
                            type="radio"
                            value="later"
                            name="delivery"
                        />Schedule for later</label
                    >
                    <div v-if="delivery === 'later'">
                        <label
                            for="campaign-time"
                            class="mb-2 block text-sm font-medium"
                            >Start preparing at (your local time)</label
                        ><input
                            id="campaign-time"
                            v-model="scheduleTime"
                            type="datetime-local"
                            class="h-10 w-full rounded-lg border bg-background px-3 text-sm"
                        />
                    </div>
                    <p class="text-xs text-muted-foreground">
                        WP Hub checks due campaigns every minute. Larger
                        audiences take longer to prepare before Brevo starts
                        delivery.
                    </p>
                    <Button
                        class="w-full"
                        :disabled="!canSchedule"
                        @click="reviewOpen = true"
                        >Review &
                        {{ delivery === 'later' ? 'schedule' : 'send' }}</Button
                    >
                    <form
                        v-if="reviewOpen"
                        class="space-y-4 border-t pt-4"
                        @submit.prevent="launch"
                    >
                        <p class="text-sm">
                            This will share the selected recipient emails with
                            Brevo and send
                            <strong>{{ campaign.content.subject }}</strong> to
                            up to
                            <strong>{{ review.count }}</strong> subscribers.
                        </p>
                        <label class="flex items-start gap-2 text-sm"
                            ><input
                                v-model="form.confirmed"
                                type="checkbox"
                                required
                                class="mt-1"
                            />I approve this message, website, audience and
                            delivery time.</label
                        >
                        <p
                            v-if="localError"
                            role="alert"
                            class="text-sm text-red-600"
                        >
                            {{ localError }}
                        </p>
                        <Button
                            :disabled="
                                !form.confirmed ||
                                form.processing ||
                                !canSchedule
                            "
                            class="w-full"
                            >{{
                                form.processing
                                    ? 'Scheduling…'
                                    : delivery === 'later'
                                      ? 'Confirm schedule'
                                      : 'Confirm campaign send'
                            }}</Button
                        >
                    </form>
                </section>
                <div v-else class="rounded-xl border p-5 text-sm">
                    <p>Scheduled: {{ marketingDate(campaign.scheduled_at) }}</p>
                    <p class="mt-2">
                        Accepted by Brevo:
                        {{ marketingDate(campaign.submitted_at) }}
                    </p>
                    <p
                        v-if="campaign.provider_campaign_id"
                        class="mt-2 text-muted-foreground"
                    >
                        Brevo campaign #{{ campaign.provider_campaign_id }}
                    </p>
                    <p
                        class="mt-3 text-xs leading-relaxed text-muted-foreground"
                    >
                        Results count recipients with detected events. Privacy
                        protection affects open tracking. Brevo acceptance does
                        not confirm delivery. Results refresh when you choose
                        Refresh results.
                    </p>
                </div>
                <Button
                    v-if="
                        ['draft', 'scheduled', 'preparing'].includes(
                            campaign.status,
                        )
                    "
                    variant="outline"
                    :disabled="cancelForm.processing"
                    @click="
                        cancelForm.post(
                            '/marketing/campaigns/' + campaign.id + '/cancel',
                            { preserveScroll: true },
                        )
                    "
                    >Cancel campaign</Button
                >
                <p class="text-xs text-muted-foreground">
                    This draft keeps a copy of its saved template. To revise the
                    message, edit the template and create a new draft.
                </p>
            </div>
        </div>
    </MarketingLayout>
</template>
