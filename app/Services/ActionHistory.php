<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ActionHistory
{
    public function snapshot(User $actor, array $filters, int $page = 1): array
    {
        return DB::transaction(function () use ($actor, $filters, $page) {
            $now = CarbonImmutable::now()->startOfSecond();
            $sites = DB::table('websites')->when(! $actor->is_admin, fn ($query) => $query->where('user_id', $actor->id))
                ->orderBy('name')->orderBy('id')->get(['id', 'name'])->keyBy('id');
            abort_if($filters['website_id'] !== null && ! $sites->has($filters['website_id']), 403);
            $ids = $filters['website_id'] === null ? $sites->keys()->all() : [$filters['website_id']];
            $order = $filters['order_id'] === null ? null : DB::table('wc_orders')->whereIn('website_id', $sites->keys())
                ->where('id', $filters['order_id'])->first(['id', 'wp_order_id', 'website_id']);
            abort_if($filters['order_id'] !== null && $order === null, 404);

            $events = DB::table('action_history_events as event')->whereIn('event.website_id', $ids)
                ->where(fn ($query) => $query->where('event.kind', 'order_status')->orWhere('event.actor_id', $actor->id))
                ->when($order, fn ($query) => $query->where('event.order_id', $order->id)->where('event.website_id', $order->website_id))
                ->selectRaw("'event' AS source, event.id AS source_id, event.actor_id, event.website_id, event.order_id,
                    event.kind, event.reference, event.entity_key, event.occurred_at, event.finished_at, event.details,
                    CASE WHEN event.outcome = 'pending' AND event.occurred_at < ? THEN 'uncertain' ELSE event.outcome END AS outcome", [$now->subMinutes(5)]);
            $retries = DB::table('webhook_retry_attempts as retry')->join('webhook_events as webhook', 'webhook.id', '=', 'retry.webhook_event_id')
                ->whereIn('webhook.website_id', $ids)->when($order, fn ($query) => $query->whereRaw('1 = 0'))
                ->selectRaw("'webhook' AS source, retry.id AS source_id, retry.user_id AS actor_id, webhook.website_id, NULL AS order_id,
                    'webhook_retry' AS kind, webhook.external_id AS reference, webhook.id AS entity_key,
                    retry.requested_at AS occurred_at, retry.finished_at, NULL AS details,
                    CASE WHEN retry.status IN ('queued', 'running') THEN 'pending'
                    WHEN retry.status = 'succeeded' THEN 'succeeded' WHEN retry.status = 'failed' THEN 'failed' ELSE 'skipped' END AS outcome");
            $source = $events->unionAll($retries);
            $people = DB::table('users')->whereIn('id', DB::query()->fromSub(clone $source, 'visible')->select('actor_id'))
                ->orderBy('name')->orderBy('id')->get(['id', 'name']);
            $query = DB::query()->fromSub($source, 'activity');
            if ($filters['actor_id'] !== null) {
                $query->where('actor_id', $filters['actor_id']);
            }
            if ($filters['category'] !== 'all') {
                $query->whereIn('kind', match ($filters['category']) {
                    'orders' => ['order_status'], 'email' => ['email_send', 'email_record'],
                    'webhooks' => ['webhook_retry'], 'alerts' => ['alert_snoozed', 'alert_resumed'],
                });
            }
            if ($filters['outcome'] !== 'all') {
                $query->where('outcome', $filters['outcome']);
            }
            if ($filters['search'] !== '') {
                $query->where('reference', 'like', '%'.$filters['search'].'%');
            }
            if ($filters['start_date'] !== '') {
                $query->where('occurred_at', '>=', CarbonImmutable::parse($filters['start_date'], config('app.timezone'))->startOfDay());
            }
            if ($filters['end_date'] !== '') {
                $query->where('occurred_at', '<', CarbonImmutable::parse($filters['end_date'], config('app.timezone'))->addDay()->startOfDay());
            }
            $summary = ['total' => 0, 'succeeded' => 0, 'attention' => 0, 'pending' => 0];
            foreach ((clone $query)->selectRaw('outcome, COUNT(*) AS count')->groupBy('outcome')->get() as $row) {
                $summary['total'] += (int) $row->count;
                if (in_array($row->outcome, ['succeeded', 'pending'], true)) {
                    $summary[$row->outcome] += (int) $row->count;
                } elseif (in_array($row->outcome, ['failed', 'uncertain'], true)) {
                    $summary['attention'] += (int) $row->count;
                }
            }
            $total = $summary['total'];
            $last = max(1, (int) ceil($total / 25));
            $page = max(1, min($page, $last));
            $rows = $query->leftJoin('users', 'users.id', '=', 'activity.actor_id')
                ->leftJoin('wc_orders as orders', fn ($join) => $join->on('orders.id', '=', 'activity.order_id')->on('orders.website_id', '=', 'activity.website_id'))
                ->select('activity.*', 'users.name as actor_name', 'orders.id as linked_order_id')
                ->orderByDesc('activity.occurred_at')->orderByDesc('activity.source')->orderByDesc('activity.source_id')
                ->offset(($page - 1) * 25)->limit(25)->get();
            $data = $rows->map(fn ($row) => $this->present($row, $sites->get($row->website_id)->name))->all();

            return ['history' => ['data' => $data, 'summary' => $summary, 'total' => $total, 'current_page' => $page,
                'last_page' => $last, 'per_page' => 25, 'from' => $total ? ($page - 1) * 25 + 1 : null,
                'to' => $total ? ($page - 1) * 25 + count($data) : null, 'generated_at' => $now->toIso8601String(),
                'timezone' => config('app.timezone')], 'websites' => $sites->values()->all(), 'people' => $people,
                'orderContext' => $order ? ['id' => $order->id, 'number' => $order->wp_order_id, 'website_id' => $order->website_id] : null,
                'filters' => $filters];
        });
    }

    private function present(object $row, string $websiteName): array
    {
        $details = json_decode($row->details ?? '{}', true);
        $details = is_array($details) ? $details : [];
        $title = match ($row->kind) {
            'order_status' => 'Order status requested', 'email_send' => 'Document email send', 'email_record' => 'Earlier email delivery',
            'webhook_retry' => 'Webhook retry', 'alert_snoozed' => 'Alert snoozed', 'alert_resumed' => 'Alert resumed',
            default => 'Recorded action',
        };
        $message = match ($row->kind) {
            'order_status' => match ($details['reason'] ?? null) {
                'confirmed' => 'WooCommerce confirmed the requested status.',
                'newer_retained' => 'WooCommerce accepted the request; a newer order update was kept in WP Hub.',
                'different_status' => 'WooCommerce returned a different status. The requested change was not confirmed.',
                'rejected' => 'WooCommerce rejected the request.',
                'connection_required' => 'No request was sent. The website needs a WooCommerce connection.',
                default => $row->outcome === 'pending' ? 'Waiting for confirmation.' : 'The result was not confirmed. Check the order before retrying.',
            },
            'email_send', 'email_record' => match ($row->outcome) {
                'succeeded' => 'Gmail accepted the email. This does not confirm delivery or reading.',
                'failed' => 'The send failed. Review the email in the order before trying again.',
                'pending' => 'The send is in progress.',
                default => 'The result is uncertain. Check Gmail Sent before sending again.',
            },
            'webhook_retry' => match ($row->outcome) {
                'succeeded' => 'The webhook was processed successfully.', 'failed' => 'The retry failed. Review the delivery details.',
                'pending' => 'The retry is queued or processing.', default => 'The retry was skipped or existing data was kept.',
            },
            'alert_snoozed' => 'The alert was snoozed for 24 hours; checks continue.',
            'alert_resumed' => 'The alert was returned to the active view.',
            default => 'Recorded activity.',
        };
        if ($row->kind === 'email_record') {
            $message .= ' This is the retained delivery record, not a separate record of every earlier attempt.';
        }
        if (in_array($row->kind, ['alert_snoozed', 'alert_resumed'], true)) {
            $alertName = match ($details['alert_kind'] ?? null) {
                'webhook_failed' => 'Failed webhooks', 'webhook_stalled' => 'Delayed webhooks',
                'email_attention' => 'Email sends to review', 'processing_aged' => 'Orders waiting over 24 hours', default => null,
            };
            if ($alertName) {
                $message = $alertName.'. '.$message;
            }
        }
        $reference = preg_match('/\A[0-9]{1,20}\z/D', (string) $row->reference) ? (string) $row->reference : null;
        $url = $row->linked_order_id ? '/orders/'.$row->linked_order_id : null;
        if ($row->kind === 'webhook_retry') {
            $url = '/website-health?website_id='.$row->website_id.'&status=all&range=all';
        } elseif (in_array($row->kind, ['alert_snoozed', 'alert_resumed'], true)) {
            $url = '/alerts?website_id='.$row->website_id.'&status=all';
        }
        $changes = [];
        foreach (['from', 'requested', 'confirmed'] as $key) {
            if (in_array($details[$key] ?? null, ['pending', 'on-hold', 'processing', 'completed', 'failed', 'cancelled', 'refunded', 'checkout-draft'], true)) {
                $changes[$key] = $details[$key];
            }
        }

        return ['id' => $row->source.':'.$row->source_id, 'kind' => $row->kind, 'title' => $title, 'message' => $message,
            'outcome' => $row->outcome, 'actor' => ['id' => $row->actor_id === null ? null : (int) $row->actor_id, 'name' => $row->actor_name ?? 'Former user'],
            'website' => ['id' => (int) $row->website_id, 'name' => $websiteName], 'reference' => $reference,
            'occurred_at' => $this->date($row->occurred_at), 'finished_at' => $this->date($row->finished_at),
            'changes' => $changes, 'attempt' => isset($details['attempts']) ? max(0, (int) $details['attempts']) : null,
            'action_url' => $url, 'action_label' => $row->kind === 'webhook_retry' ? 'Review webhooks' : ($row->linked_order_id ? 'Open order' : 'View alerts')];
    }

    private function date(?string $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value, config('app.timezone'))->toIso8601String();
    }
}
