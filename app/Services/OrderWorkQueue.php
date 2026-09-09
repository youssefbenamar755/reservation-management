<?php

namespace App\Services;

use App\Http\Requests\OrderWorkQueueRequest;
use App\Models\User;
use App\Models\WcOrder;
use App\Models\Website;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OrderWorkQueue
{
    public function snapshot(User $actor, array $filters, int $page = 1): array
    {
        $now = CarbonImmutable::now();

        return DB::transaction(function () use ($actor, $filters, $page, $now) {
            $websites = Website::when(! $actor->is_admin, fn ($query) => $query->where('user_id', $actor->id))->orderBy('name')->get(['id', 'name', 'user_id']);
            abort_if($filters['website_id'] !== null && ! $websites->contains('id', $filters['website_id']), 403);
            $ids = $filters['website_id'] === null ? $websites->pluck('id')->all() : [$filters['website_id']];
            $base = $this->base($actor, $ids, $filters['search'], $now);
            $summary = array_fill_keys(OrderWorkQueueRequest::STAGES, 0);
            foreach (DB::query()->fromSub(clone $base, 'backlog')->selectRaw('stage, COUNT(*) AS count')->groupBy('stage')->get() as $count) {
                $summary[$count->stage] = (int) $count->count;
                $summary['all'] += (int) $count->count;
            }
            $total = $summary[$filters['stage']];
            $perPage = $filters['per_page'];
            $lastPage = max(1, (int) ceil($total / $perPage));
            $currentPage = max(1, min($page, $lastPage));
            $direction = $filters['sort'] === 'newest' ? 'desc' : 'asc';
            $rows = DB::query()->fromSub($base, 'backlog')
                ->when($filters['stage'] !== 'all', fn ($query) => $query->where('stage', $filters['stage']))
                ->orderBy('created_at_wp', $direction)->orderBy('id', $direction)
                ->offset(($currentPage - 1) * $perPage)->limit($perPage)->get();
            $submissions = $this->submissionLinks($rows);
            $byWebsite = $websites->keyBy('id');
            $data = $rows->map(function ($row) use ($actor, $byWebsite, $submissions) {
                $website = $byWebsite->get($row->website_id);
                $order = new WcOrder(['website_id' => $row->website_id, 'status' => $row->status]);
                $order->id = $row->id;
                $order->setRelation('website', $website);

                return [
                    'id' => (int) $row->id, 'wp_order_id' => (int) $row->wp_order_id, 'website_id' => (int) $row->website_id,
                    'website' => ['id' => $website->id, 'name' => $website->name], 'status' => $row->status,
                    'can_update_status' => $actor->can('update', $order), 'customer_name' => $row->customer_name, 'customer_email' => $row->customer_email,
                    'total' => (string) $row->total, 'currency' => $row->currency, 'created_at_wp' => $this->date($row->created_at_wp), 'stage' => $row->stage,
                    'email' => $row->email_status === null ? null : ['status' => $row->email_status, 'created_at' => $this->date($row->email_created_at),
                        'sent_at' => $this->date($row->email_sent_at), 'expires_at' => $this->date($row->email_expires_at)],
                    'submission_id' => $submissions[$row->id] ?? null,
                ];
            })->all();

            return ['queue' => ['data' => $data, 'current_page' => $currentPage, 'last_page' => $lastPage, 'per_page' => $perPage, 'total' => $total,
                'from' => $total === 0 ? null : ($currentPage - 1) * $perPage + 1, 'to' => $total === 0 ? null : ($currentPage - 1) * $perPage + count($data),
                'summary' => $summary, 'generated_at' => $now->toIso8601String(), 'timezone' => config('app.timezone')],
                'websites' => $websites->map(fn ($website) => ['id' => $website->id, 'name' => $website->name])->all(), 'filters' => $filters];
        });
    }

    private function base(User $actor, array $websiteIds, string $search, CarbonImmutable $now): Builder
    {
        $latest = DB::table('order_email_deliveries as latest')->select('latest.id')
            ->whereColumn('latest.wc_order_id', 'wc_orders.id')->whereColumn('latest.website_id', 'wc_orders.website_id')
            ->where('latest.user_id', $actor->id)->orderByDesc('latest.created_at')->orderByDesc('latest.id')->limit(1);
        $query = DB::table('wc_orders')->whereIn('wc_orders.website_id', $websiteIds)->whereIn('wc_orders.status', ['pending', 'on-hold', 'processing'])
            ->leftJoin('order_email_deliveries as delivery', fn ($join) => $join->where('delivery.id', '=', $latest))
            ->select('wc_orders.id', 'wc_orders.wp_order_id', 'wc_orders.website_id', 'wc_orders.status', 'wc_orders.customer_name',
                'wc_orders.customer_email', 'wc_orders.total', 'wc_orders.currency', 'wc_orders.created_at_wp',
                'delivery.created_at as email_created_at', 'delivery.sent_at as email_sent_at', 'delivery.expires_at as email_expires_at');
        $stale = "delivery.status = 'sending' AND (delivery.sending_at IS NULL OR delivery.sending_at < ?)";
        $expired = "delivery.status = 'prepared' AND (delivery.expires_at IS NULL OR delivery.expires_at <= ? OR delivery.mime IS NULL)";
        $query->selectRaw("CASE WHEN {$stale} THEN 'uncertain' WHEN {$expired} THEN 'expired' ELSE delivery.status END AS email_status",
            [$now->subMinutes(5), $now]);
        $query->selectRaw("CASE WHEN delivery.status IN ('failed', 'uncertain') OR ({$stale}) THEN 'attention'
            WHEN wc_orders.status IN ('pending', 'on-hold') THEN 'waiting'
            WHEN delivery.status = 'prepared' AND delivery.expires_at > ? AND delivery.mime IS NOT NULL THEN 'ready'
            WHEN delivery.status = 'sending' THEN 'sending' WHEN delivery.status = 'sent' THEN 'sent' ELSE 'prepare' END AS stage", [$now->subMinutes(5), $now]);
        if ($search !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $query->where(fn ($match) => $match->whereRaw("wc_orders.wp_order_id LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereRaw("wc_orders.customer_name LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("wc_orders.customer_email LIKE ? ESCAPE '!'", [$pattern]));
        }

        return $query;
    }

    /** Only the visible page is enriched; no payloads, PDFs or encrypted email contents leave SQL. */
    private function submissionLinks(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }
        $candidates = [];
        foreach (DB::table('wc_orders')->whereIn('id', $rows->pluck('id'))->get(['id', 'website_id', 'payload->meta_data as metadata']) as $order) {
            $metadata = json_decode($order->metadata ?? '[]', true);
            if (! is_array($metadata) || ! array_is_list($metadata)) {
                continue;
            }
            $values = [];
            foreach ($metadata as $meta) {
                if (! is_array($meta) || ($meta['key'] ?? null) !== '_fluent_id') {
                    continue;
                }
                $value = $meta['value'] ?? null;
                if ((! is_int($value) && ! is_string($value)) || ! preg_match('/\A[1-9][0-9]{0,17}\z/D', (string) $value)) {
                    $values[] = null;
                } else {
                    $values[] = (string) $value;
                }
            }
            $values = array_values(array_unique($values));
            if (count($values) === 1 && $values[0] !== null) {
                $candidates[$order->website_id.':'.$values[0]] = ['website_id' => $order->website_id, 'entry_id' => $values[0]];
            }
        }
        if ($candidates === []) {
            return [];
        }
        $entries = DB::table('ff_submissions')->where(function ($query) use ($candidates) {
            foreach ($candidates as $candidate) {
                $query->orWhere(fn ($match) => $match->where('website_id', $candidate['website_id'])->where('entry_id', $candidate['entry_id']));
            }
        })->selectRaw('website_id, entry_id, MIN(id) AS submission_id, COUNT(*) AS matches')->groupBy('website_id', 'entry_id')->get();
        $result = [];
        $checks = DB::query();
        $eligible = [];
        foreach ($entries as $entry) {
            if ((int) $entry->matches !== 1) {
                continue;
            }
            $match = DB::table('wc_orders')->where('website_id', $entry->website_id);
            $this->whereFluentId($match, (string) $entry->entry_id);
            $index = count($eligible);
            // Stop at two matches; a duplicate means no safe direct link.
            $unique = DB::query()->fromSub($match->select('id')->limit(2), 'matching_orders')->selectRaw('MIN(id)')->havingRaw('COUNT(*) = 1');
            $checks->selectSub($unique, 'order_'.$index);
            $eligible[] = $entry;
        }
        if ($eligible !== []) {
            $counts = $checks->first();
            foreach ($eligible as $index => $entry) {
                if ($counts->{'order_'.$index} !== null && $rows->contains('id', $counts->{'order_'.$index})) {
                    $result[(int) $counts->{'order_'.$index}] = (int) $entry->submission_id;
                }
            }
        }

        return $result;
    }

    private function whereFluentId(Builder $query, string $entryId): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $meta = "CASE WHEN metadata.type = 'object' THEN metadata.value ELSE '{}' END";
            $query->whereRaw("EXISTS (SELECT 1 FROM json_each(CASE WHEN json_type(payload, '$.meta_data') = 'array' THEN json_extract(payload, '$.meta_data') ELSE '[]' END) AS metadata WHERE json_extract({$meta}, '$.key') = ? AND json_type({$meta}, '$.value') IN ('integer', 'text') AND CAST(json_extract({$meta}, '$.value') AS TEXT) = ?)", ['_fluent_id', $entryId]);
        } else {
            $query->where(fn ($match) => $match->whereJsonContains('payload->meta_data', [['key' => '_fluent_id', 'value' => $entryId]])
                ->orWhereJsonContains('payload->meta_data', [['key' => '_fluent_id', 'value' => (int) $entryId]]));
        }
    }

    private function date(?string $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value, config('app.timezone'))->toIso8601String();
    }
}
