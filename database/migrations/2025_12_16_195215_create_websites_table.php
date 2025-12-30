<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('websites', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique(); // e.g. site-1, site-2
            $table->string('base_url'); // https://example.com

            // Woo credentials (encrypted via model casts)
            $table->text('wc_consumer_key')->nullable();
            $table->text('wc_consumer_secret')->nullable();

            // Fluent Forms API token/key if you need it later
            $table->text('ff_api_key')->nullable();

            // Our own secret used for validating webhook calls (query token)
            $table->text('webhook_secret')->nullable();

            $table->string('timezone')->default('UTC');
            $table->enum('status', ['active', 'paused'])->default('active');

            $table->timestamp('last_sync_at')->nullable();
            $table->timestamp('last_webhook_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('websites');
    }
};
