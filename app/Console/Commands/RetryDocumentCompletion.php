<?php

namespace App\Console\Commands;

use App\Models\OrderEmailDelivery;
use App\Services\DocumentCompletionSync;
use Illuminate\Console\Command;

class RetryDocumentCompletion extends Command
{
    protected $signature = 'documents:retry-completion';

    protected $description = 'Retry pending store completion without resending document emails';

    public function handle(DocumentCompletionSync $sync): int
    {
        $ids = OrderEmailDelivery::where('workflow', true)->where('status', 'sent')->where('document_mode', 'complete')
            ->where('sync_attempts', '<', 6)->where(fn ($query) => $query
            ->where(fn ($pending) => $pending->where('sync_status', 'pending')->where('next_sync_at', '<=', now()))
            ->orWhere(fn ($stale) => $stale->where('sync_status', 'syncing')->where('sync_claimed_at', '<', now()->subMinutes(5))))
            ->orderBy('next_sync_at')->limit(10)->pluck('id');
        foreach ($ids as $id) {
            $sync->sync($id);
        }
        $this->info('Checked '.$ids->count().' pending order updates. No emails sent.');

        return self::SUCCESS;
    }
}
