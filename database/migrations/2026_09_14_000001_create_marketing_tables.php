<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('api_key')->nullable();
            $table->uuid('connection_key');
            $table->string('account_email')->nullable();
            $table->json('senders')->nullable();
            $table->unsignedBigInteger('folder_id')->nullable();
            $table->unsignedBigInteger('webhook_id')->nullable();
            $table->text('webhook_secret');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_event_at')->nullable();
            $table->timestamps();
        });
        Schema::create('marketing_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->string('email', 254);
            $table->string('name')->nullable();
            $table->string('locale', 8)->nullable();
            $table->string('status', 24)->default('unknown');
            $table->string('consent_source', 500)->nullable();
            $table->timestamp('consented_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();
            $table->unique(['website_id', 'email']);
            $table->index(['website_id', 'status', 'locale']);
        });
        Schema::create('marketing_consent_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketing_contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24);
            $table->string('source', 500);
            $table->timestamp('consented_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
        Schema::create('marketing_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('content');
            $table->timestamps();
            $table->index(['user_id', 'website_id']);
        });
        Schema::create('marketing_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->foreignId('marketing_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('connection_key')->nullable();
            $table->string('name');
            $table->json('content');
            $table->json('audience');
            $table->string('status', 24)->default('draft');
            $table->string('phase', 24)->default('list');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sending_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('test_sent_at')->nullable();
            $table->unsignedBigInteger('provider_campaign_id')->nullable();
            $table->unsignedBigInteger('provider_list_id')->nullable();
            $table->unsignedInteger('recipient_count')->default(0);
            $table->string('result_message', 500)->nullable();
            $table->timestamps();
            $table->index(['status', 'scheduled_at']);
            $table->index(['user_id', 'website_id', 'id']);
            $table->index(['marketing_connection_id', 'provider_campaign_id']);
        });
        Schema::create('marketing_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketing_campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('marketing_contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email', 254);
            $table->string('status', 24)->default('pending');
            foreach (['delivered', 'opened', 'clicked', 'bounced', 'unsubscribed', 'complained'] as $event) {
                $table->timestamp($event.'_at')->nullable();
            }
            $table->timestamps();
            $table->unique(['marketing_campaign_id', 'email']);
            $table->index(['marketing_campaign_id', 'status', 'id']);
        });
        Schema::create('marketing_suppressions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketing_connection_id')->constrained()->cascadeOnDelete();
            $table->string('email', 254);
            $table->string('reason', 32);
            $table->timestamps();
            $table->unique(['marketing_connection_id', 'email']);
        });
    }

    public function down(): void
    {
        foreach (['marketing_suppressions', 'marketing_recipients', 'marketing_campaigns', 'marketing_templates', 'marketing_consent_events', 'marketing_contacts', 'marketing_connections'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
