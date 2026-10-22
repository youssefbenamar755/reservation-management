<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_document_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('gmail_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('configured_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('brand_name')->nullable();
            $table->text('logo_url')->nullable();
            foreach (['primary_color', 'accent_color', 'background_color', 'text_color'] as $color) {
                $table->string($color, 7)->nullable();
            }
            $table->string('support_email')->nullable();
            $table->text('website_url')->nullable();
            $table->string('support_phone')->nullable();
            $table->text('footer')->nullable();
            $table->string('sender_email')->nullable();
            $table->string('sender_name')->nullable();
            $table->string('reply_to')->nullable();
            $table->string('default_language', 2)->default('fr');
            $table->string('service_label')->nullable();
            $table->json('required_services')->nullable();
            $table->json('templates')->nullable();
            $table->boolean('workflow_enabled')->default(false);
            $table->unsignedInteger('config_version')->default(1);
            $table->uuid('store_pairing_key')->unique();
            $table->json('store_capability')->nullable();
            $table->timestamp('store_checked_at')->nullable();
            $table->unsignedInteger('brand_sync_version')->nullable();
            $table->timestamp('last_test_at')->nullable();
            $table->json('last_test_result')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_document_profiles');
    }
};
