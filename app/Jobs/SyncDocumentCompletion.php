<?php

namespace App\Jobs;

use App\Services\DocumentCompletionSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncDocumentCompletion implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(public string $deliveryId)
    {
        $this->onConnection('documents')->onQueue('documents');
    }

    public function handle(DocumentCompletionSync $sync): void
    {
        $sync->sync($this->deliveryId);
    }
}
