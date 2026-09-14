<script setup lang="ts">
import MarketingLayout from '@/components/MarketingLayout.vue';
import MarketingPagination from '@/components/MarketingPagination.vue';
import { marketingDate, marketingStatus, marketingTone } from '@/lib/marketing';
import type {
    MarketingCampaign,
    MarketingCommon,
    MarketingPage,
} from '@/types/marketing';
import { Link, router } from '@inertiajs/vue3';
import { ArrowRight, LayoutTemplate, MailPlus, Users } from 'lucide-vue-next';
defineProps<
    MarketingCommon & {
        campaigns: MarketingPage<MarketingCampaign>;
        summary: Record<string, number>;
        filters: { website_id?: number };
    }
>();
</script>
<template>
    <MarketingLayout
        title="Email campaigns"
        description="Build useful messages for the customers who want to hear from you."
        active="Campaigns"
        :connected="connected"
    >
        <div class="grid grid-cols-2 gap-4 xl:grid-cols-4">
            <div
                v-for="item in [
                    { key: 'total', label: 'All campaigns' },
                    { key: 'drafts', label: 'Drafts' },
                    { key: 'scheduled', label: 'Scheduled & preparing' },
                    { key: 'submitted', label: 'Accepted by Brevo' },
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
                    for="campaign-site"
                    class="mb-2 block text-sm font-medium"
                    >Website</label
                ><select
                    id="campaign-site"
                    :value="filters.website_id || ''"
                    class="h-10 max-w-full rounded-lg border bg-background px-3 text-sm"
                    @change="
                        router.get(
                            '/marketing',
                            {
                                website_id: ($event.target as HTMLSelectElement)
                                    .value,
                            },
                            { preserveScroll: true },
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
            <Link
                href="/marketing/templates"
                class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2.5 text-sm font-medium text-primary-foreground"
                ><MailPlus class="size-4" />Create campaign</Link
            >
        </div>
        <div
            v-if="!campaigns.data.length"
            class="rounded-2xl border bg-card px-6 py-14 text-center"
        >
            <MailPlus class="mx-auto mb-4 size-10 text-teal-600" />
            <h2 class="text-xl font-semibold">
                Your next customer conversation starts here
            </h2>
            <p class="mx-auto mt-2 max-w-lg text-sm text-muted-foreground">
                Prepare a website audience, save a branded template, then review
                your first campaign before scheduling.
            </p>
            <div class="mt-7 flex flex-wrap justify-center gap-3">
                <Link
                    href="/marketing/audience"
                    class="inline-flex items-center gap-2 rounded-lg border px-4 py-2 text-sm"
                    ><Users class="size-4" />Build audience</Link
                ><Link
                    href="/marketing/templates"
                    class="inline-flex items-center gap-2 rounded-lg border px-4 py-2 text-sm"
                    ><LayoutTemplate class="size-4" />Create template</Link
                >
            </div>
        </div>
        <div v-else class="overflow-hidden rounded-xl border bg-card">
            <div class="relative overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-muted/50 text-muted-foreground">
                        <tr>
                            <th class="px-5 py-3 font-medium">Campaign</th>
                            <th class="px-5 py-3 font-medium">Website</th>
                            <th class="px-5 py-3 font-medium">Status</th>
                            <th class="px-5 py-3 font-medium">Recipients</th>
                            <th class="px-5 py-3 font-medium">Scheduled</th>
                            <th class="px-5 py-3">
                                <span class="sr-only">View</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="campaign in campaigns.data"
                            :key="campaign.id"
                            class="border-t hover:bg-muted/30"
                        >
                            <td class="min-w-48 px-5 py-5 font-medium">
                                <Link
                                    :href="
                                        '/marketing/campaigns/' + campaign.id
                                    "
                                    class="hover:underline"
                                    >{{ campaign.name }}</Link
                                >
                                <p
                                    class="mt-1 text-xs font-normal text-muted-foreground"
                                >
                                    Created
                                    {{ marketingDate(campaign.created_at) }}
                                </p>
                            </td>
                            <td class="px-5 py-5">
                                {{
                                    websites.find(
                                        (s) => s.id === campaign.website_id,
                                    )?.name
                                }}
                            </td>
                            <td class="px-5 py-5">
                                <span
                                    class="inline-block rounded-full px-2.5 py-1 text-xs font-medium whitespace-nowrap"
                                    :class="marketingTone(campaign.status)"
                                    >{{
                                        marketingStatus(campaign.status)
                                    }}</span
                                >
                            </td>
                            <td class="px-5 py-5 tabular-nums">
                                {{
                                    campaign.status === 'draft'
                                        ? 'To review'
                                        : campaign.recipient_count
                                }}
                            </td>
                            <td
                                class="px-5 py-5 whitespace-nowrap text-muted-foreground"
                            >
                                {{ marketingDate(campaign.scheduled_at) }}
                            </td>
                            <td class="px-5 py-5">
                                <Link
                                    :href="
                                        '/marketing/campaigns/' + campaign.id
                                    "
                                    :aria-label="'Open ' + campaign.name"
                                    ><ArrowRight class="size-4"
                                /></Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <MarketingPagination :page="campaigns" />
        </div>
    </MarketingLayout>
</template>
