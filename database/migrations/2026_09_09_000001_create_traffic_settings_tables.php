<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('traffic_app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('client_id');
            $table->text('client_secret');
            $table->timestamps();
        });
        Schema::create('traffic_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('email')->nullable();
            $table->uuid('connection_key');
            $table->char('app_fingerprint', 64);
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->boolean('reconnect_required')->default(false);
            $table->json('catalog')->nullable();
            $table->timestamp('catalog_at')->nullable();
            $table->timestamps();
        });
        Schema::create('traffic_websites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->uuid('connection_key');
            $table->uuid('mapping_key');
            $table->string('ga4_property_id', 32)->nullable();
            $table->text('gsc_site_url')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'website_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('traffic_websites');
        Schema::dropIfExists('traffic_connections');
        Schema::dropIfExists('traffic_app_settings');
    }
};
