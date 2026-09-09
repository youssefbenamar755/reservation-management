<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('traffic_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->char('cache_key', 64)->unique();
            $table->uuid('connection_key');
            $table->uuid('mapping_key');
            $table->char('app_fingerprint', 64);
            $table->string('ga4_property_id')->nullable();
            $table->text('gsc_site_url')->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 20)->default('missing');
            $table->uuid('run_key')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('refreshed_at')->nullable();
            $table->json('payload')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'start_date', 'end_date']);
            $table->index('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('traffic_reports');
    }
};
