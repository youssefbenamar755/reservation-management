<?php

namespace App\Services;

use App\Models\UsefulAlert;
use App\Models\User;
use App\Notifications\UsefulAlertNotification;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class UsefulAlerts
{
    public const KINDS = ['webhook_failed', 'webhook_stalled', 'email_attention', 'processing_aged'];

    public function assertWebsite(User $actor, ?int $websiteId): void
    {
        abort_if($websiteId !== null && ! $this->websites($actor)->where('id', $websiteId)->exists(), 403);
    }

    private function websites(User $actor): Builder
    {
        return DB::table('websites')->when(! $actor->is_admin, fn ($query) => $query->where('user_id', $actor->id));
    }

    /** A complete scan and episode changes commit together; failed scans retain prior evidence. */
    public function scan(User $actor): void
    {
        DB::transaction(function () use ($actor) {
            // Serialize scheduled/manual scans and snooze actions for this recipient.
            $user = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $now = CarbonImmutable::now()->startOfSecond();
            $sites = $this->websites($user)->orderBy('id')->get(['id', 'name'])->keyBy('id');
            $ids = $sites->keys()->all();
            $counts = $this->detect($user, $ids, $now);
            $existing = UsefulAlert::where('user_id', $user->id)->get()->keyBy(fn ($row) => $row->website_id.':'.$row->kind);

            foreach ($existing as $row) {
                if (! $sites->has($row->website_id)) {
                    $this->readNotification($user, $row, $now);
                    $row->delete();
                }
            }
            foreach ($sites as $site) {
                foreach (self::KINDS as $kind) {
                    $key = $site->id.':'.$kind;
                    $count = $counts[$key] ?? 0;
                    $row = $existing->get($key);
                    if ($count === 0) {
                        if ($row && $row->resolved_at === null) {
                            $this->readNotification($user, $row, $now);
                            $row->update(['resolved_at' => $now]);
                        }

                        continue;
                    }
                    if (! $row) {
                        $row = new UsefulAlert(['user_id' => $user->id, 'website_id' => $site->id, 'kind' => $kind]);
                    }
                    if (! $row->exists || $row->resolved_at !== null) {
                        $row->fill(['episode_key' => (string) Str::uuid(), 'notification_id' => null,
                            'first_detected_at' => $now, 'resolved_at' => null]);
                    }
                    $row->fill(['count' => $count, 'last_detected_at' => $now])->save();
                    if ($row->notification_id === null && ($row->snoozed_until === null || $row->snoozed_until <= $now)) {
                        $copy = $this->copy($kind, $count, (int) $site->id);
                        $notification = new UsefulAlertNotification(['type' => 'useful_alert', 'alert_id' => $row->id,
                            'website_id' => $site->id, 'website_name' => $site->name, 'kind' => $kind,
                            'title' => $copy['title'], 'message' => $site->name.': '.$copy['title'], 'count' => $count]);
                        $notification->id = (string) Str::uuid();
                        $user->notifyNow($notification, ['database']);
                        $row->update(['notification_id' => $notification->id]);
                        DB::afterCommit(function () use ($user, $notification) {
                            try {
                                $user->notifyNow($notification, ['broadcast']);
                            } catch (Throwable $exception) {
                                // The saved inbox item remains usable if the push service is unavailable.
                                Log::warning('Useful alert broadcast unavailable', ['user_id' => $user->id, 'exception_type' => $exception::class]);
                            }
                        });
                    }
                }
            }
            DB::table('useful_alert_scan_states')->updateOrInsert(['user_id' => $user->id], ['checked_at' => $now]);
        }, 3);
    }

    private function detect(User $actor, array $websiteIds, CarbonImmutable $now): array
    {
        $counts = [];
        $webhooks = DB::table('webhook_events')->whereIn('website_id', $websiteIds)->where('signature_valid', true)
            ->whereIn('status', ['failed', 'queued'])
            ->selectRaw("website_id, SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,
                SUM(CASE WHEN status = 'queued' AND received_at <= ? THEN 1 ELSE 0 END) AS stalled", [$now->subMinutes(5)])
            ->groupBy('website_id')->get();
        foreach ($webhooks as $row) {
            $counts[$row->website_id.':webhook_failed'] = (int) $row->failed;
            $counts[$row->website_id.':webhook_stalled'] = (int) $row->stalled;
        }
        foreach ($this->processing($websiteIds, $now)->selectRaw('website_id, COUNT(*) AS count')->groupBy('website_id')->get() as $row) {
            $counts[$row->website_id.':processing_aged'] = (int) $row->count;
        }
        foreach ($this->emailAttention($actor, $websiteIds, $now)->selectRaw('wc_orders.website_id, COUNT(*) AS count')->groupBy('wc_orders.website_id')->get() as $row) {
            $counts[$row->website_id.':email_attention'] = (int) $row->count;
        }

        return $counts;
    }

    private function processing(array $websiteIds, CarbonImmutable $now): Builder
    {
        return DB::table('wc_orders')->whereIn('website_id', $websiteIds)->where('status', 'processing')
            ->where('created_at_wp', '<=', $now->subHours(24));
    }

    private function emailAttention(User $actor, array $websiteIds, CarbonImmutable $now): Builder
    {
        $latest = DB::table('order_email_deliveries as latest')->select('latest.id')->where('latest.user_id', $actor->id)
            ->whereColumn('latest.wc_order_id', 'wc_orders.id')->whereColumn('latest.website_id', 'wc_orders.website_id')
            ->orderByDesc('latest.created_at')->orderByDesc('latest.id')->limit(1);

        return DB::table('wc_orders')->whereIn('wc_orders.website_id', $websiteIds)
            ->join('order_email_deliveries as delivery', fn ($join) => $join->where('delivery.id', '=', $latest))
            ->where(fn ($query) => $query->whereIn('delivery.status', ['failed', 'uncertain'])
                ->orWhere(fn ($sending) => $sending->where('delivery.status', 'sending')
                    ->where(fn ($stale) => $stale->whereNull('delivery.sending_at')->orWhere('delivery.sending_at', '<', $now->subMinutes(5)))));
    }

    public function snooze(User $actor, int $id, bool $snooze): void
    {
        DB::transaction(function () use ($actor, $id, $snooze) {
            $user = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $row = UsefulAlert::where('user_id', $user->id)->whereIn('website_id', $this->websites($user)->select('id'))->findOrFail($id);
            abort_if($row->resolved_at !== null, 409, 'This alert has already resolved. Refresh the view.');
            $now = CarbonImmutable::now()->startOfSecond();
            $row->update(['snoozed_until' => $snooze ? $now->addDay() : null]);
            if ($snooze) {
                $this->readNotification($user, $row, $now);
            }
        }, 3);
    }

    private function readNotification(User $actor, UsefulAlert $alert, CarbonImmutable $now): void
    {
        if ($alert->notification_id) {
            $actor->unreadNotifications()->whereKey($alert->notification_id)->update(['read_at' => $now]);
        }
    }

    public function snapshot(User $actor, array $filters, int $page = 1): array
    {
        return DB::transaction(function () use ($actor, $filters, $page) {
            $now = CarbonImmutable::now()->startOfSecond();
            $sites = $this->websites($actor)->orderBy('name')->orderBy('id')->get(['id', 'name'])->keyBy('id');
            abort_if($filters['website_id'] !== null && ! $sites->has($filters['website_id']), 403);
            $ids = $filters['website_id'] === null ? $sites->keys()->all() : [$filters['website_id']];
            $base = DB::table('useful_alerts')->where('user_id', $actor->id)->whereIn('website_id', $ids)
                ->when($filters['kind'] !== 'all', fn ($query) => $query->where('kind', $filters['kind']))
                ->select('id', 'website_id', 'kind', 'count', 'first_detected_at', 'last_detected_at', 'resolved_at', 'snoozed_until')
                ->selectRaw("CASE WHEN resolved_at IS NOT NULL THEN 'resolved' WHEN snoozed_until > ? THEN 'snoozed' ELSE 'active' END AS state", [$now]);
            $summary = ['active' => 0, 'snoozed' => 0, 'resolved' => 0];
            foreach (DB::query()->fromSub(clone $base, 'alerts')->selectRaw('state, COUNT(*) AS count')->groupBy('state')->get() as $row) {
                $summary[$row->state] = (int) $row->count;
            }
            $total = $filters['status'] === 'all' ? array_sum($summary) : $summary[$filters['status']];
            $lastPage = max(1, (int) ceil($total / 25));
            $page = max(1, min($page, $lastPage));
            $rows = DB::query()->fromSub($base, 'alerts')->when($filters['status'] !== 'all', fn ($query) => $query->where('state', $filters['status']))
                ->orderByRaw("CASE WHEN state = 'active' THEN 0 WHEN state = 'snoozed' THEN 1 ELSE 2 END")
                ->orderByRaw("CASE WHEN kind IN ('webhook_failed', 'email_attention') THEN 0 ELSE 1 END")
                ->orderBy('first_detected_at')->orderBy('id')->offset(($page - 1) * 25)->limit(25)->get();
            $examples = [];
            foreach (['email_attention', 'processing_aged'] as $kind) {
                $visibleIds = $rows->where('kind', $kind)->whereNull('resolved_at')->pluck('website_id')->all();
                if ($visibleIds === []) {
                    continue;
                }
                $query = $kind === 'email_attention' ? $this->emailAttention($actor, $visibleIds, $now) : $this->processing($visibleIds, $now);
                $ranked = $query->select('wc_orders.id', 'wc_orders.website_id', 'wc_orders.wp_order_id')
                    ->selectRaw('ROW_NUMBER() OVER (PARTITION BY wc_orders.website_id ORDER BY wc_orders.created_at_wp, wc_orders.id) AS position');
                foreach (DB::query()->fromSub($ranked, 'examples')->where('position', '<=', 3)->orderBy('website_id')->orderBy('position')->get() as $row) {
                    $examples[$row->website_id.':'.$kind][] = ['id' => (int) $row->id, 'label' => 'Order #'.$row->wp_order_id, 'url' => '/orders/'.$row->id];
                }
            }
            $data = $rows->map(fn ($row) => ['id' => (int) $row->id, 'kind' => $row->kind, 'count' => (int) $row->count,
                'website' => ['id' => (int) $row->website_id, 'name' => $sites->get($row->website_id)->name],
                'status' => $row->state, 'first_detected_at' => $this->iso($row->first_detected_at), 'last_detected_at' => $this->iso($row->last_detected_at),
                'resolved_at' => $this->iso($row->resolved_at), 'snoozed_until' => $this->iso($row->snoozed_until),
                'examples' => $examples[$row->website_id.':'.$row->kind] ?? [],
            ] + $this->copy($row->kind, (int) $row->count, (int) $row->website_id))->all();
            $checked = DB::table('useful_alert_scan_states')->where('user_id', $actor->id)->value('checked_at');

            return ['alertCenter' => ['data' => $data, 'summary' => $summary, 'checked_at' => $this->iso($checked),
                'thresholds' => ['webhook_minutes' => 5, 'processing_hours' => 24], 'current_page' => $page, 'last_page' => $lastPage,
                'per_page' => 25, 'total' => $total, 'from' => $total === 0 ? null : ($page - 1) * 25 + 1,
                'to' => $total === 0 ? null : ($page - 1) * 25 + count($data)], 'websites' => $sites->values()->all(), 'filters' => $filters];
        });
    }

    private function iso(?string $date): ?string
    {
        return $date === null ? null : CarbonImmutable::parse($date, config('app.timezone'))->toIso8601String();
    }

    private function copy(string $kind, int $count, int $websiteId): array
    {
        $scope = '?website_id='.$websiteId;

        return match ($kind) {
            'webhook_failed' => ['severity' => 'critical', 'title' => 'Webhooks need review',
                'message' => $count.' verified webhook records failed to process. Review the failure before requesting a retry.',
                'action_url' => '/website-health'.$scope.'&status=failed&range=all', 'action_label' => 'Review webhooks'],
            'webhook_stalled' => ['severity' => 'warning', 'title' => 'Webhooks are waiting',
                'message' => $count.' verified webhook records have been queued for at least 5 minutes. Check processing and the queue worker.',
                'action_url' => '/website-health'.$scope.'&status=queued&range=all', 'action_label' => 'Review queue'],
            'email_attention' => ['severity' => 'critical', 'title' => 'Document emails need review',
                'message' => $count.' orders have a failed or uncertain latest WP Hub email send for your account. Check Gmail Sent before retrying an uncertain message.',
                'action_url' => '/orders'.$scope, 'action_label' => 'View website orders'],
            'processing_aged' => ['severity' => 'warning', 'title' => 'Processing orders are over 24 hours old',
                'message' => $count.' orders are still processing at least 24 hours after their order date. Review their progress; this is an age reminder.',
                'action_url' => '/orders'.$scope.'&status=processing&sort=oldest', 'action_label' => 'Review processing orders'],
        };
    }
}
