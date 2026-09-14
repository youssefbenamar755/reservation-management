<?php

use App\Models\MarketingCampaign;
use App\Models\MarketingConnection;
use App\Models\MarketingContact;
use App\Models\User;
use App\Models\Website;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

test('marketing migration produces MySQL identifiers within the 64 character limit', function () {
    $schema = Schema::getFacadeRoot();
    $mysql = new MySqlConnection(fn () => throw new RuntimeException('No database access is allowed in this compilation test.'), 'schema_preview', '', ['driver' => 'mysql', 'version' => '8.4.0', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci']);
    try {
        Schema::swap($mysql->getSchemaBuilder());
        $migration = require database_path('migrations/2026_09_14_000001_create_marketing_tables.php');
        $queries = $mysql->pretend(fn () => $migration->up());
        $sql = implode("\n", array_column($queries, 'query'));
        expect($sql)->toContain('create table `marketing_connections`')->toContain('create table `marketing_suppressions`')->toContain('marketing_campaign_provider_index');
        preg_match_all('/`([^`]+)`/', $sql, $matches);
        foreach (array_unique($matches[1]) as $identifier) {
            expect(strlen($identifier), $identifier)->toBeLessThanOrEqual(64);
        }
    } finally {
        Schema::swap($schema);
    }
});

test('marketing migration resumes after the campaign index failure without losing records', function () {
    $owner = User::factory()->create();
    $website = Website::create(['user_id' => $owner->id, 'name' => 'Schema test', 'slug' => 'schema-test', 'base_url' => 'https://schema.example']);
    $connection = MarketingConnection::create(['user_id' => $owner->id, 'connection_key' => (string) Str::uuid(), 'webhook_secret' => str_repeat('a', 64)]);
    $contact = MarketingContact::create(['website_id' => $website->id, 'email' => 'record@example.test', 'status' => 'unknown']);
    $campaign = MarketingCampaign::create(['user_id' => $owner->id, 'website_id' => $website->id, 'marketing_connection_id' => $connection->id, 'name' => 'Preserve draft', 'content' => [], 'audience' => []]);
    Schema::drop('marketing_suppressions');
    Schema::drop('marketing_recipients');
    Schema::table('marketing_campaigns', fn (Blueprint $table) => $table->dropIndex('marketing_campaign_provider_index'));
    $migration = require database_path('migrations/2026_09_14_000001_create_marketing_tables.php');
    $migration->up();
    $migration->up();
    expect(Schema::hasIndex('marketing_campaigns', 'marketing_campaign_provider_index'))->toBeTrue()
        ->and(Schema::hasTable('marketing_recipients'))->toBeTrue()
        ->and(Schema::hasTable('marketing_suppressions'))->toBeTrue()
        ->and($contact->fresh()->email)->toBe('record@example.test')
        ->and($campaign->fresh()->name)->toBe('Preserve draft')
        ->and($connection->fresh()->connection_key)->toBe($connection->connection_key)
        ->and($owner->fresh()->id)->toBe($owner->id);
});
