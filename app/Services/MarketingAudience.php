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

    public const SOURCES = ['all', 'forms', 'orders', 'forms_only', 'orders_only', 'both'];

    public function websites(User $user)
    {
        return Website::when(! $user->is_admin, fn ($q) => $q->where('user_id', $user->id))->orderBy('name')->get(['id', 'name', 'base_url']);
    }

    public function query(int|array $websiteId, array $filters = [], bool $includeOrderTotals = true): Builder
    {
        $websiteIds = (array) $websiteId;
        $source = $filters['source'] ?? 'all';
        $needsTotals = $includeOrderTotals || in_array($filters['segment'] ?? 'all', ['first', 'repeat', 'inactive'], true);
        $query = MarketingContact::whereIn('marketing_contacts.website_id', $websiteIds)->select('marketing_contacts.*');
        if ($needsTotals) {
            $orders = WcOrder::whereIn('website_id', $websiteIds)->whereNotNull('customer_email')
                ->selectRaw("website_id, LOWER(TRIM(customer_email)) as email_key, COUNT(*) as orders_count, SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count, MAX(COALESCE(created_at_wp, created_at)) as last_order_at")
                ->groupBy('website_id')->groupByRaw('LOWER(TRIM(customer_email))');
            $submissions = DB::table('marketing_contact_submissions as mcs')->join('ff_submissions as ff', 'ff.id', '=', 'mcs.ff_submission_id')
                ->whereIn('ff.website_id', $websiteIds)->selectRaw('mcs.marketing_contact_id, COUNT(*) as submissions_count, MAX(COALESCE(ff.created_at_wp, ff.created_at)) as last_submission_at')
                ->groupBy('mcs.marketing_contact_id');
            $query->leftJoinSub($orders, 'order_totals', fn ($join) => $join->on('marketing_contacts.website_id', '=', 'order_totals.website_id')->on('marketing_contacts.email', '=', 'order_totals.email_key'))
                ->leftJoinSub($submissions, 'form_totals', 'marketing_contacts.id', '=', 'form_totals.marketing_contact_id')
                ->selectRaw('COALESCE(order_totals.orders_count, 0) as orders_count, COALESCE(order_totals.completed_count, 0) as completed_count, order_totals.last_order_at, COALESCE(form_totals.submissions_count, 0) as submissions_count, form_totals.last_submission_at');
        }
        if ($source !== 'all') {
            // Eligibility checks run again during delivery; existence checks avoid
            // recalculating website-wide totals for each individual recipient.
            $hasForms = fn ($q) => $q->selectRaw('1')->from('marketing_contact_submissions as mcs')->whereColumn('mcs.marketing_contact_id', 'marketing_contacts.id');
            $hasOrders = fn ($q) => $q->selectRaw('1')->from('wc_orders')->whereColumn('wc_orders.website_id', 'marketing_contacts.website_id')
                ->whereRaw('LOWER(TRIM(wc_orders.customer_email)) = marketing_contacts.email');
            if (in_array($source, ['forms', 'forms_only', 'both'], true)) {
                $query->whereExists($hasForms);
            }
            if (in_array($source, ['orders', 'orders_only', 'both'], true)) {
                $query->whereExists($hasOrders);
            }
            if ($source === 'forms_only') {
                $query->whereNotExists($hasOrders);
            } elseif ($source === 'orders_only') {
                $query->whereNotExists($hasForms);
            }
        }
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
        return app(MarketingContactDiscovery::class)->discover($websiteId);
    }
}
