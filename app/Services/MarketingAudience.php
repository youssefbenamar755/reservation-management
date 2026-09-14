<?php

namespace App\Services;

use App\Models\MarketingCampaign;
use App\Models\MarketingContact;
use App\Models\User;
use App\Models\WcOrder;
use App\Models\Website;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class MarketingAudience
{
    public const MAX_RECIPIENTS = 5000;

    public function websites(User $user)
    {
        return Website::when(! $user->is_admin, fn ($q) => $q->where('user_id', $user->id))->orderBy('name')->get(['id', 'name', 'base_url']);
    }

    public function query(int $websiteId, array $filters = [], bool $includeOrderTotals = true): Builder
    {
        if (! $includeOrderTotals && ! in_array($filters['segment'] ?? 'all', ['first', 'repeat', 'inactive'], true)) {
            return MarketingContact::where('marketing_contacts.website_id', $websiteId)
                ->when(! empty($filters['locale']), fn ($q) => $q->where('marketing_contacts.locale', $filters['locale']));
        }
        $orders = WcOrder::where('website_id', $websiteId)->whereNotNull('customer_email')
            ->selectRaw("LOWER(TRIM(customer_email)) as email_key, COUNT(*) as orders_count, SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count, MAX(created_at_wp) as last_order_at")
            ->groupByRaw('LOWER(TRIM(customer_email))');
        $query = MarketingContact::where('marketing_contacts.website_id', $websiteId)
            ->leftJoinSub($orders, 'order_totals', 'marketing_contacts.email', '=', 'order_totals.email_key')
            ->select('marketing_contacts.*')->selectRaw('COALESCE(order_totals.orders_count, 0) as orders_count, COALESCE(order_totals.completed_count, 0) as completed_count, order_totals.last_order_at');
        if (! empty($filters['locale'])) {
            $query->where('marketing_contacts.locale', $filters['locale']);
        }
        if (! empty($filters['status'])) {
            $query->where('marketing_contacts.status', $filters['status']);
        }
        if (($filters['segment'] ?? '') === 'repeat') {
            $query->where('order_totals.completed_count', '>=', 2);
        } elseif (($filters['segment'] ?? '') === 'first') {
            $query->where('order_totals.completed_count', 1);
        } elseif (($filters['segment'] ?? '') === 'inactive') {
            $query->where('order_totals.last_order_at', '<', now()->subDays(90));
        }
        if (! empty($filters['search'])) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['search']).'%';
            $query->where(fn ($q) => $q->whereRaw("marketing_contacts.email LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("marketing_contacts.name LIKE ? ESCAPE '!'", [$pattern]));
        }

        return $query;
    }

    public function eligible(MarketingCampaign $campaign): Builder
    {
        return $this->query($campaign->website_id, $campaign->audience, false)
            ->where('marketing_contacts.status', 'subscribed')->whereNotNull('marketing_contacts.consented_at')
            ->whereNotNull('marketing_contacts.consent_source')
            ->whereNotExists(function ($q) use ($campaign) {
                $q->selectRaw('1')->from('marketing_suppressions')->whereColumn('marketing_suppressions.email', 'marketing_contacts.email')
                    ->where('marketing_connection_id', $campaign->marketing_connection_id ?? 0);
            });
    }

    public function review(MarketingCampaign $campaign): array
    {
        $rows = $this->eligible($campaign)->orderBy('marketing_contacts.id')->limit(self::MAX_RECIPIENTS + 1)->get();
        $fingerprint = hash_hmac('sha256', json_encode([
            $campaign->id, $campaign->content, $campaign->audience, $campaign->connection_key,
            $rows->map(fn ($c) => [$c->id, $c->email, $c->consented_at?->toIso8601String(), $c->consent_source])->all(),
        ], JSON_THROW_ON_ERROR), (string) config('app.key'));

        return ['count' => $rows->count(), 'limit' => self::MAX_RECIPIENTS, 'token' => $fingerprint,
            'sample' => $rows->take(10)->map(fn ($c) => ['name' => $c->name, 'email' => $c->email])->values()->all(), 'rows' => $rows];
    }

    public function stillEligible(MarketingCampaign $campaign, string $email): bool
    {
        return $this->eligible($campaign)->where('marketing_contacts.email', $email)->exists();
    }

    /** Import only identity; order history is never evidence of marketing consent. */
    public function discover(int $websiteId): int
    {
        $added = 0;
        WcOrder::where('website_id', $websiteId)->whereNotNull('customer_email')->select('id', 'customer_email', 'customer_name')
            ->chunkById(250, function ($orders) use ($websiteId, &$added) {
                $rows = [];
                foreach ($orders as $order) {
                    $email = strtolower(trim($order->customer_email));
                    if (strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $rows[$email] = ['website_id' => $websiteId, 'email' => $email, 'name' => mb_substr($order->customer_name ?? '', 0, 255), 'status' => 'unknown', 'created_at' => now(), 'updated_at' => now()];
                    }
                }
                $added += DB::table('marketing_contacts')->insertOrIgnore(array_values($rows));
            });

        return $added;
    }
}
