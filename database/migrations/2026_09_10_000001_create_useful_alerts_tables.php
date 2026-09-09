<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('useful_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 40);
            $table->uuid('episode_key');
            $table->uuid('notification_id')->nullable();
            $table->unsignedInteger('count');
            $table->timestamp('first_detected_at');
            $table->timestamp('last_detected_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('snoozed_until')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'website_id', 'kind']);
            $table->index(['user_id', 'resolved_at', 'snoozed_until']);
        });
        Schema::create('useful_alert_scan_states', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->timestamp('checked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('useful_alert_scan_states');
        Schema::dropIfExists('useful_alerts');
    }
};
