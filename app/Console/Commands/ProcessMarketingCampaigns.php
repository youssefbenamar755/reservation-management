<?php

namespace App\Console\Commands;

use App\Models\MarketingCampaign;
use App\Services\MarketingDelivery;
use App\Services\MarketingLock;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class ProcessMarketingCampaigns extends Command
{
    protected $signature = 'marketing:process {--steps=40}';

    protected $description = 'Prepare and dispatch due marketing campaigns in bounded, resumable steps';

    public function handle(MarketingDelivery $delivery, MarketingLock $lock): int
    {
        MarketingCampaign::where('status', 'sending')->where('sending_at', '<', now()->subMinutes(10))
            ->update(['status' => 'uncertain', 'result_message' => 'The sending process ended without confirmation. Check Brevo; this campaign will not be sent again automatically.']);
        $deadline = microtime(true) + 40;
        $steps = min(100, max(1, (int) $this->option('steps')));
        for ($i = 0; $i < $steps && microtime(true) < $deadline; $i++) {
            $campaign = MarketingCampaign::whereIn('status', ['scheduled', 'preparing'])->where('scheduled_at', '<=', now())->orderBy('updated_at')->orderBy('id')->first();
            if (! $campaign) {
                break;
            }
            try {
                $lock->run($campaign->user_id, fn () => $delivery->step($campaign));
            } catch (ValidationException) {
                break;
            }
        }

        return self::SUCCESS;
    }
}
