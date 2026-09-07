<?php

namespace App\Http\Controllers;

use App\Http\Requests\HealthFilterRequest;
use App\Models\WebhookEvent;
use App\Services\WebhookRecovery;
use App\Support\WebhookFailureSummary;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class WebhookHealthController extends Controller
{
    public function index(HealthFilterRequest $request)
    {
        $filters = $request->filters();
        $now = CarbonImmutable::now(config('app.timezone'))->startOfSecond();
        $websites = DB::table('websites')->when(! $request->user()->is_admin, fn ($query) => $query->where('user_id', $request->user()->id))
            ->select('id', 'name', 'status', 'last_webhook_at', 'last_sync_at', 'wc_orders_synced_at')
            ->orderBy('name')->orderBy('id')->get()->keyBy('id');
        abort_if($filters['website_id'] !== null && ! $websites->has($filters['website_id']), 403);
        $selected = $filters['website_id'] === null ? $websites : $websites->only([$filters['website_id']]);
        $ids = $selected->keys()->all();

        // Health totals intentionally ignore the event table's status/source/date filters.
        $aggregates = DB::table('webhook_events')->whereIn('website_id', $ids)
            ->selectRaw("website_id,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_total,
                SUM(CASE WHEN status = 'failed' AND received_at >= ? AND received_at <= ? THEN 1 ELSE 0 END) AS failed_recent,
                SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) AS queued,
                SUM(CASE WHEN status = 'processed' AND processed_at >= ? AND processed_at <= ? THEN 1 ELSE 0 END) AS processed_recent,
                MIN(CASE WHEN status = 'queued' THEN received_at END) AS oldest_queued_at,
                MAX(CASE WHEN status = 'processed' THEN processed_at END) AS last_processed_at", [
                $now->subDay()->toDateTimeString(), $now->toDateTimeString(), $now->subDay()->toDateTimeString(), $now->toDateTimeString(),
            ])->groupBy('website_id')->get()->keyBy('website_id');
        $summary = ['failed_recent' => 0, 'failed_total' => 0, 'queued' => 0, 'processed_recent' => 0, 'oldest_queued_at' => null];
        $sites = $selected->map(function ($website) use ($aggregates, &$summary) {
            $totals = $aggregates->get($website->id);
            foreach (['failed_recent', 'failed_total', 'queued', 'processed_recent'] as $key) {
                $summary[$key] += (int) ($totals?->{$key} ?? 0);
            }
            $oldest = $this->iso($totals?->oldest_queued_at);
            if ($oldest !== null) {
                $summary['oldest_queued_at'] = $summary['oldest_queued_at'] === null ? $oldest : min($summary['oldest_queued_at'], $oldest);
            }

            return [
                'id' => $website->id, 'name' => $website->name, 'status' => $website->status,
                'last_webhook_at' => $this->iso($website->last_webhook_at), 'last_sync_at' => $this->iso($website->last_sync_at),
                'wc_orders_synced_at' => $this->iso($website->wc_orders_synced_at),
                'failed_recent' => (int) ($totals?->failed_recent ?? 0), 'failed_total' => (int) ($totals?->failed_total ?? 0),
                'queued' => (int) ($totals?->queued ?? 0), 'last_processed_at' => $this->iso($totals?->last_processed_at),
            ];
        })->values()->all();

        $query = DB::table('webhook_events')->whereIn('website_id', $ids);
        foreach (['status', 'source'] as $key) {
            if ($filters[$key] !== 'all') {
                $query->where($key, $filters[$key]);
            }
        }
        if ($filters['range'] !== 'all') {
            $start = match ($filters['range']) {
                '7d' => $now->subDays(7), '30d' => $now->subDays(30), default => $now->subDay(),
            };
            $query->whereBetween('received_at', [$start, $now]);
        }
        $events = $query->select('id', 'website_id', 'source', 'topic', 'external_id', 'status', 'received_at', 'processed_at', 'error_message')
            ->selectSub(DB::table('webhook_retry_attempts')->selectRaw('COUNT(*)')->whereColumn('webhook_event_id', 'webhook_events.id'), 'attempts_count')
            ->orderByDesc('received_at')->orderByDesc('id')->paginate(15)->appends($filters);
        $events->through(fn ($event) => $this->publicEvent($event, $selected->get($event->website_id)));
        $health = ['summary' => $summary, 'sites' => $sites, 'events' => $events, 'timezone' => config('app.timezone'), 'checked_at' => $now->toIso8601String()];

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['health' => $health])->header('Cache-Control', 'private, no-store');
        }

        return Inertia::render('WebsiteHealth/Index', [
            'health' => $health, 'filters' => $filters,
            'websites' => $websites->map(fn ($website) => ['id' => $website->id, 'name' => $website->name])->values()->all(),
        ])->toResponse($request)->header('Cache-Control', 'private, no-store');
    }

    public function show(WebhookEvent $event, WebhookRecovery $recovery)
    {
        $website = $event->website;
        $this->authorize('view', $website);
        $event->setAttribute('attempts_count', DB::table('webhook_retry_attempts')->where('webhook_event_id', $event->id)->count());
        $attempts = DB::table('webhook_retry_attempts as attempts')->leftJoin('users', 'users.id', '=', 'attempts.user_id')
            ->where('attempts.webhook_event_id', $event->id)
            ->select('attempts.id', 'attempts.status', 'attempts.requested_at', 'attempts.started_at', 'attempts.finished_at', 'attempts.result_message', 'users.name as requested_by')
            ->orderByDesc('attempts.id')->limit(20)->get()->map(fn ($attempt) => $this->publicAttempt($attempt))->all();

        return response()->json([
            'event' => $this->publicEvent($event, $website) + ['signature_valid' => (bool) $event->signature_valid] + $recovery->eligibility($event),
            'attempts' => $attempts,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function retry(Request $request, WebhookEvent $event, WebhookRecovery $recovery)
    {
        $this->authorize('update', $event->website);
        $attempt = $recovery->retry($event, $request->user());
        $attempt->setAttribute('requested_by', $request->user()->name);

        return response()->json(['message' => 'The webhook retry was requested.', 'attempt' => $this->publicAttempt($attempt)], 202)
            ->header('Cache-Control', 'private, no-store');
    }

    private function publicEvent(object $event, object $website): array
    {
        // Topics and external IDs originate outside WP Hub. Do not echo arbitrary header/body strings.
        $topic = in_array($event->topic, ['order.created', 'order.updated', 'order.deleted', 'order.restored', 'form.submitted'], true) ? $event->topic : 'unknown';
        $externalId = is_scalar($event->external_id) && preg_match('/^[0-9]{1,20}$/D', (string) $event->external_id) === 1 ? (string) $event->external_id : null;

        return [
            'id' => $event->id, 'website_id' => $event->website_id, 'website' => ['id' => $website->id, 'name' => $website->name],
            'source' => $event->source, 'topic' => $topic, 'external_id' => $externalId, 'status' => $event->status,
            'received_at' => $this->iso($event->received_at), 'processed_at' => $this->iso($event->processed_at),
            'failure_summary' => $event->status === 'failed' ? WebhookFailureSummary::summarize($event->error_message) : null,
            'attempts_count' => (int) $event->attempts_count,
        ];
    }

    private function publicAttempt(object $attempt): array
    {
        return [
            'id' => $attempt->id, 'status' => $attempt->status, 'requested_at' => $this->iso($attempt->requested_at),
            'started_at' => $this->iso($attempt->started_at), 'finished_at' => $this->iso($attempt->finished_at),
            'result_message' => WebhookFailureSummary::attempt($attempt->status, $attempt->result_message),
            'requested_by' => $attempt->requested_by,
        ];
    }

    private function iso(DateTimeInterface|string|null $date): ?string
    {
        return $date === null ? null : CarbonImmutable::parse($date, config('app.timezone'))->toIso8601String();
    }
}
