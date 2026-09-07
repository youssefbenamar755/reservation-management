<?php

namespace App\Jobs;

use App\Services\WebhookRecovery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class RetryWebhookEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public function __construct(public int $attemptId) {}

    public function handle(WebhookRecovery $recovery): void
    {
        $recovery->process($this->attemptId);
    }

    public function failed(Throwable $exception): void
    {
        app(WebhookRecovery::class)->fail($this->attemptId, $exception);
    }
}
