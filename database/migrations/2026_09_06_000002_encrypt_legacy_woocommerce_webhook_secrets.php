<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('websites')->select('id')->whereNotNull('wc_webhook_secret')
            ->chunkById(100, function ($websites) {
                foreach ($websites as $website) {
                    DB::transaction(function () use ($website) {
                        $current = DB::table('websites')->where('id', $website->id)->lockForUpdate()->first();
                        $secret = $current?->wc_webhook_secret;

                        // The January 2026 migration wrote this exact plaintext
                        // format through DB, bypassing Website's encrypted cast.
                        // Ciphertext and any unknown format must stay untouched.
                        if (! is_string($secret) || preg_match('/\Awc_[A-Za-z0-9]{40}\z/', $secret) !== 1) {
                            return;
                        }

                        DB::table('websites')->where('id', $website->id)->update([
                            'wc_webhook_secret' => Crypt::encryptString($secret),
                        ]);
                    });
                }
            });
    }

    public function down(): void
    {
        // Preserve encrypted credentials when rolling back; restoring plaintext
        // would reintroduce the invalid encrypted-cast value and expose the secret.
    }
};
