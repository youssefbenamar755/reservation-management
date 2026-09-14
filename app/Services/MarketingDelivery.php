<?php

namespace App\Services;

use App\Exceptions\MarketingProviderException;
use App\Models\MarketingCampaign;
use App\Models\MarketingConnection;
use App\Models\User;
use App\Models\Website;

class MarketingDelivery
{
    public function __construct(private BrevoClient $client, private MarketingAudience $audience, private MarketingContent $content) {}

    public function connection(MarketingCampaign $campaign): MarketingConnection
    {
        $connection = MarketingConnection::find($campaign->marketing_connection_id);
        $user = User::find($campaign->user_id);
        $website = Website::find($campaign->website_id);
        if (! $connection?->ready() || $connection->user_id !== $campaign->user_id || $connection->connection_key !== $campaign->connection_key
            || ! $user || ! $website || (! $user->is_admin && $website->user_id !== $user->id)) {
            throw new MarketingProviderException;
        }

        return $connection;
    }

    public function attachConnection(MarketingCampaign $campaign): void
    {
        if ($campaign->status === 'draft' && ! $campaign->marketing_connection_id) {
            $connection = MarketingConnection::where('user_id', $campaign->user_id)->first();
            if ($connection) {
                $campaign->fill(['marketing_connection_id' => $connection->id, 'connection_key' => $connection->connection_key]);
            }
        }
    }

    public function providerDraft(MarketingCampaign $campaign, MarketingConnection $connection): int
    {
        if ($campaign->provider_campaign_id) {
            return (int) $campaign->provider_campaign_id;
        }
        $result = $this->client->request($connection, 'POST', '/emailCampaigns', [
            'name' => 'WP Hub '.$campaign->id.' — '.$campaign->name,
            'sender' => ['email' => $campaign->content['sender_email'], 'name' => $campaign->content['sender_name']],
            'subject' => $campaign->content['subject'], 'previewText' => $campaign->content['preheader'] ?? '',
            'replyTo' => $campaign->content['reply_to'], 'htmlContent' => $this->content->html($campaign->content, false, $campaign->id),
            'tag' => 'wphub-'.$campaign->id,
        ]);
        if (! is_numeric($result['id'] ?? null)) {
            throw new MarketingProviderException;
        }
        $campaign->update(['provider_campaign_id' => $result['id']]);

        return (int) $result['id'];
    }

    /** One bounded step. Scheduler calls again; only the send step can send a campaign. */
    public function step(MarketingCampaign $campaign): void
    {
        $campaign->refresh();
        if (! in_array($campaign->status, ['scheduled', 'preparing'], true) || $campaign->scheduled_at?->isFuture()) {
            return;
        }
        try {
            $connection = $this->connection($campaign);
            $campaign->update(['status' => 'preparing']);
            if (! $campaign->provider_list_id) {
                $list = $this->client->request($connection, 'POST', '/contacts/lists', ['folderId' => (int) $connection->folder_id, 'name' => 'WP Hub campaign '.$campaign->id]);
                if (! is_numeric($list['id'] ?? null)) {
                    throw new MarketingProviderException;
                }
                $campaign->update(['provider_list_id' => $list['id'], 'phase' => 'contacts']);

                return;
            }
            $recipient = $campaign->recipients()->where('status', 'pending')->orderBy('id')->first();
            if ($recipient) {
                if (! $this->audience->stillEligible($campaign, $recipient->email)) {
                    $recipient->update(['status' => 'skipped']);

                    return;
                }
                // Omitting emailBlacklisted is deliberate: never resubscribe a suppressed Brevo contact.
                $this->client->request($connection, 'POST', '/contacts', ['email' => $recipient->email, 'listIds' => [(int) $campaign->provider_list_id], 'updateEnabled' => true]);
                $recipient->update(['status' => 'synced']);

                return;
            }
            // Consent may have changed during preparation. Remove withdrawals before provider handoff.
            $eligible = $this->audience->eligible($campaign)->pluck('marketing_contacts.email');
            $withdrawn = $campaign->recipients()->where('status', 'synced')->whereNotIn('email', $eligible)->limit(100)->get();
            if ($withdrawn->isNotEmpty()) {
                $this->client->request($connection, 'POST', '/contacts/lists/'.$campaign->provider_list_id.'/contacts/remove', ['emails' => $withdrawn->pluck('email')->all()]);
                $campaign->recipients()->whereIn('id', $withdrawn->pluck('id'))->update(['status' => 'skipped']);

                return;
            }
            if (! $campaign->recipients()->where('status', 'synced')->exists()) {
                $campaign->update(['status' => 'cancelled', 'result_message' => 'No eligible subscribers remain. Nothing was sent.']);

                return;
            }
            if (! $campaign->provider_campaign_id) {
                $this->providerDraft($campaign, $connection);

                return;
            }
            if ($campaign->phase !== 'send') {
                $this->client->request($connection, 'PUT', '/emailCampaigns/'.$campaign->provider_campaign_id, ['recipients' => ['listIds' => [(int) $campaign->provider_list_id]]]);
                $campaign->update(['phase' => 'send']);

                return;
            }
            $this->client->assertSender($connection, $campaign->content['sender_email']);
            $remote = $this->client->request($connection, 'GET', '/emailCampaigns/'.$campaign->provider_campaign_id);
            if (($remote['status'] ?? '') !== 'draft') {
                $campaign->update(['status' => 'uncertain', 'result_message' => 'This campaign is no longer a draft in Brevo. Check its status there; WP Hub will not send it again.']);

                return;
            }
            $eligibleNow = $this->audience->eligible($campaign)->pluck('marketing_contacts.email');
            if ($campaign->recipients()->where('status', 'synced')->whereNotIn('email', $eligibleNow)->exists()) {
                return; // A withdrawal arrived during provider checks; the next step removes it from the list.
            }
            // Persist the claim before the network call; process death or timeout must never cause a resend.
            $claimed = MarketingCampaign::whereKey($campaign->id)->where('status', 'preparing')->update(['status' => 'sending', 'sending_at' => now()]);
            if (! $claimed) {
                return;
            }
            $campaign->refresh();
            $this->client->request($connection, 'POST', '/emailCampaigns/'.$campaign->provider_campaign_id.'/sendNow');
            $campaign->update(['status' => 'submitted', 'submitted_at' => now(), 'result_message' => 'Brevo accepted the campaign. Delivery results appear as events arrive.']);
        } catch (MarketingProviderException $exception) {
            $campaign->update(['status' => $campaign->status === 'sending' && $exception->uncertain ? 'uncertain' : 'failed', 'result_message' => $exception->getMessage()]);
        }
    }

    public function stats(MarketingCampaign $campaign): array
    {
        $stats = $campaign->recipients()->selectRaw("COUNT(*) as selected, SUM(CASE WHEN status = 'skipped' THEN 1 ELSE 0 END) as skipped,
            SUM(CASE WHEN delivered_at IS NOT NULL THEN 1 ELSE 0 END) as delivered,
            SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) as opened,
            SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) as clicked,
            SUM(CASE WHEN bounced_at IS NOT NULL THEN 1 ELSE 0 END) as bounced,
            SUM(CASE WHEN unsubscribed_at IS NOT NULL THEN 1 ELSE 0 END) as unsubscribed,
            SUM(CASE WHEN complained_at IS NOT NULL THEN 1 ELSE 0 END) as complained")->first()->getAttributes();

        return array_map('intval', $stats);
    }
}
