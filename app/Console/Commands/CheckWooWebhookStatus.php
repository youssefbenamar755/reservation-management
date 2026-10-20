<?php

namespace App\Console\Commands;

use App\Models\Website;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class CheckWooWebhookStatus extends Command
{
    protected $signature = 'webhooks:check-woocommerce';

    protected $description = 'Check that each connected store has active WP Hub order webhooks';

    public function handle(): int
    {
        foreach (Website::where('status', 'active')->cursor() as $website) {
            if (! $website->wc_consumer_key || ! $website->wc_consumer_secret || ! $website->wc_webhook_secret) {
                continue;
            }
            $issues = 1;
            $state = 'check_failed';
            try {
                $response = Http::acceptJson()->connectTimeout(5)->timeout(15)
                    ->withoutRedirecting()
                    ->withBasicAuth($website->wc_consumer_key, $website->wc_consumer_secret)
                    ->get(rtrim($website->base_url, '/').'/wp-json/wc/v3/webhooks', ['per_page' => 100]);
                $hooks = $response->json();
                if ($response->successful() && is_array($hooks) && array_is_list($hooks)) {
                    $target = rtrim(config('app.url'), '/').'/api/v1/webhooks/woocommerce/'.$website->slug;
                    $ours = collect($hooks)->filter(fn ($hook) => ($hook['delivery_url'] ?? null) === $target);
                    $issues = collect(['order.created', 'order.updated'])->filter(fn ($topic) =>
                        ! $ours->contains(fn ($hook) => ($hook['topic'] ?? null) === $topic && ($hook['status'] ?? null) === 'active')
                    )->count();
                    // A truncated listing cannot prove a hook is missing.
                    $state = $issues && (int) $response->header('X-WP-TotalPages') > 1 ? 'check_failed' : ($issues ? 'inactive' : 'healthy');
                }
            } catch (Throwable) {
                // Persist a safe failure signal, never credentials or remote response bodies.
            }
            DB::table('woo_webhook_checks')->updateOrInsert(['website_id' => $website->id], [
                'issues' => $issues, 'state' => $state, 'checked_at' => now(),
            ]);
            $this->line($website->name.': '.$state);
        }

        return self::SUCCESS;
    }
}
