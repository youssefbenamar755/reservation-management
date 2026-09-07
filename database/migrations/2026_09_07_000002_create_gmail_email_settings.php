<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gmail_app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('client_id');
            $table->text('client_secret');
            $table->timestamps();
        });
        Schema::create('gmail_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('email')->nullable();
            $table->uuid('connection_key');
            $table->string('app_fingerprint', 64);
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->json('aliases')->nullable();
            $table->timestamps();
        });
        Schema::create('website_email_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->string('sender_email');
            $table->string('subject_template', 255);
            $table->text('body_template');
            $table->text('signature')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'website_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_email_settings');
        Schema::dropIfExists('gmail_connections');
        Schema::dropIfExists('gmail_app_settings');
    }
};
