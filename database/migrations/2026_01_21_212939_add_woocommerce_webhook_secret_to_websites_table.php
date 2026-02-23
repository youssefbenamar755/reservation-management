<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            // Add separate webhook secret for WooCommerce
            $table->text('wc_webhook_secret')->nullable()->after('webhook_secret');
        });

        // Generate WooCommerce webhook secrets for existing websites
        $websites = DB::table('websites')->whereNull('wc_webhook_secret')->get();
        foreach ($websites as $website) {
            DB::table('websites')
                ->where('id', $website->id)
                ->update([
                    'wc_webhook_secret' => 'wc_' . Str::random(40),
                ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropColumn('wc_webhook_secret');
        });
    }
};
