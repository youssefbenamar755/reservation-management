<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            // Leave null so the first corrected sync also repairs historical gaps.
            $table->timestamp('wc_orders_synced_at')->nullable();
            $table->json('wc_orders_sync_state')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropColumn(['wc_orders_synced_at', 'wc_orders_sync_state']);
        });
    }
};
