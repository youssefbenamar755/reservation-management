<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_email_deliveries', function (Blueprint $table) {
            $table->boolean('tracking_enabled')->default(false);
            $table->char('tracking_token_hash', 64)->nullable()->unique();
            $table->timestamp('first_open_detected_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('order_email_deliveries', function (Blueprint $table) {
            $table->dropUnique(['tracking_token_hash']);
            $table->dropColumn(['tracking_enabled', 'tracking_token_hash', 'first_open_detected_at']);
        });
    }
};
