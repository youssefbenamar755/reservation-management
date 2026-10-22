<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wc_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->string('customer_email');
            $table->string('name');
            $table->string('disk');
            $table->string('path');
            $table->char('sha256', 64);
            $table->unsignedInteger('size');
            $table->unsignedInteger('version');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['wc_order_id', 'version']);
            $table->index(['website_id', 'wc_order_id']);
        });
        Schema::table('order_email_deliveries', function (Blueprint $table) {
            $table->boolean('workflow')->default(false);
            $table->char('document_set_version', 64)->nullable()->index();
            $table->string('document_mode', 20)->nullable();
            $table->string('sync_status', 24)->default('not_requested');
            $table->string('sync_error')->nullable();
            $table->unsignedInteger('sync_attempts')->default(0);
            $table->timestamp('sync_claimed_at')->nullable();
            $table->timestamp('next_sync_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->text('manual_review')->nullable();
            $table->index(['workflow', 'status', 'sync_status', 'next_sync_at'], 'document_sync_pending');
        });
        Schema::create('document_test_sends', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->longText('snapshot');
            $table->string('status', 24);
            $table->string('message')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_test_sends');
        Schema::dropIfExists('order_documents');
        Schema::table('order_email_deliveries', function (Blueprint $table) {
            $table->dropIndex('document_sync_pending');
            $table->dropColumn(['workflow', 'document_set_version', 'document_mode', 'sync_status', 'sync_error', 'sync_attempts', 'sync_claimed_at', 'next_sync_at', 'synced_at', 'manual_review']);
        });
    }
};
