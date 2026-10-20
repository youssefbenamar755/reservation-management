<?php

namespace App\Console\Commands;

use App\Jobs\ProcessWooWebhookEvent;
use App\Models\WebhookEvent;
use Illuminate\Console\Command;
use Throwable;

class RecoverWooWebhookEvents extends Command
{
    protected $signature = 'webhooks:recover-woocommerce';

    protected $description = 'Recover verified WooCommerce deliveries interrupted after acknowledgement';

    public function handle(): int
    {
        // Bound each scan. Failed events remain available for explicit review/retry.
        $ids = WebhookEvent::query()
            ->where('source', 'woocommerce')
            ->where('signature_valid', true)
            ->where('status', 'queued')
            ->whereIn('topic', ['order.created', 'order.updated'])
            ->where('received_at', '<', now()->subMinutes(2))
            ->whereHas('website', fn ($query) => $query->where('status', 'active'))
            ->orderBy('id')->limit(50)->pluck('id');

        $failed = 0;
        foreach ($ids as $id) {
            $job = new ProcessWooWebhookEvent($id);
            try {
                // Event locking and completed-event checks make late HTTP processing safe.
                app()->call([$job, 'handle']);
            } catch (Throwable $exception) {
                $job->failed($exception);
                report($exception);
                $failed++;
            }
        }

        $this->info('Checked '.$ids->count().' interrupted deliveries; '.$failed.' processing exceptions.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
