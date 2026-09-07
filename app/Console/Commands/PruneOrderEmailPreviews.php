<?php

namespace App\Console\Commands;

use App\Models\OrderEmailDelivery;
use App\Services\OrderEmailDeliveryService;
use Illuminate\Console\Command;

class PruneOrderEmailPreviews extends Command
{
    protected $signature = 'emails:prune-previews';

    protected $description = 'Remove expired preview and sent email attachments while preserving delivery history';

    public function handle(): int
    {
        $expired = OrderEmailDelivery::whereIn('status', ['prepared', 'failed'])->where('expires_at', '<', now())->update([
            'status' => 'expired', 'mime' => null, 'deduplication_key' => null, 'result_message' => 'This preview expired. Prepare a new email before sending.',
        ]);
        $stopped = OrderEmailDelivery::where('status', 'sending')->where('sending_at', '<', now()->subMinutes(5))
            ->update(['status' => 'uncertain', 'result_message' => OrderEmailDeliveryService::UNKNOWN]);
        $sent = OrderEmailDelivery::where('status', 'sent')->whereNotNull('mime')->update(['mime' => null]);
        $uncertain = OrderEmailDelivery::where('status', 'uncertain')->where('expires_at', '<', now())->whereNotNull('mime')->update(['mime' => null]);
        $this->info("Expired {$expired} email preview(s); marked {$stopped} interrupted send(s) uncertain; cleared {$sent} sent and {$uncertain} uncertain snapshot(s).");

        return self::SUCCESS;
    }
}
