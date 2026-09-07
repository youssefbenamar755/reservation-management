<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_email_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wc_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->foreignId('gmail_connection_id')->constrained()->cascadeOnDelete();
            $table->uuid('connection_key');
            $table->char('fingerprint', 64);
            $table->char('deduplication_key', 64)->nullable()->unique();
            $table->longText('snapshot');
            $table->longText('mime')->nullable();
            $table->string('status', 20)->default('prepared');
            $table->string('result_message')->nullable();
            $table->string('gmail_message_id')->nullable();
            $table->unsignedInteger('send_attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('sending_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['wc_order_id', 'user_id', 'created_at']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_email_deliveries');
    }
};
