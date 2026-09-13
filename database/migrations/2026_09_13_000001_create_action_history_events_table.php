<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('action_history_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('wc_orders')->nullOnDelete();
            $table->string('kind', 40);
            $table->string('entity_key', 64)->nullable();
            $table->string('reference', 30)->nullable();
            $table->string('deduplication_key', 100)->nullable()->unique();
            $table->string('outcome', 20);
            $table->json('details');
            $table->timestamp('occurred_at');
            $table->timestamp('finished_at')->nullable();
            $table->index(['website_id', 'occurred_at', 'id'], 'action_history_website_time');
            $table->index(['order_id', 'occurred_at']);
            $table->index(['actor_id', 'kind', 'occurred_at'], 'action_history_actor_kind_time');
        });

        // Preserve one truthful retained record per older email. Do not fabricate
        // separate attempts: only the latest attempt's timestamp/result was saved.
        DB::table('order_email_deliveries as delivery')->join('wc_orders as orders', 'orders.id', '=', 'delivery.wc_order_id')
            ->whereColumn('orders.website_id', 'delivery.website_id')
            ->where('delivery.send_attempts', '>', 0)
            ->where(fn ($query) => $query->whereNotNull('delivery.sending_at')->orWhereNotNull('delivery.sent_at'))
            ->select('delivery.id', 'delivery.user_id', 'delivery.website_id', 'delivery.wc_order_id', 'delivery.status',
                'delivery.send_attempts', 'delivery.sending_at', 'delivery.sent_at', 'orders.wp_order_id')
            ->chunkById(100, function ($rows) {
                DB::table('action_history_events')->insert($rows->map(fn ($row) => [
                    'actor_id' => $row->user_id, 'website_id' => $row->website_id, 'order_id' => $row->wc_order_id,
                    'kind' => 'email_record', 'entity_key' => $row->id, 'reference' => (string) $row->wp_order_id,
                    'deduplication_key' => 'legacy-email:'.$row->id,
                    'outcome' => match ($row->status) {
                        'sent' => 'succeeded', 'failed' => 'failed', default => 'uncertain'
                    },
                    'details' => json_encode(['attempts' => (int) $row->send_attempts]),
                    'occurred_at' => $row->sending_at ?? $row->sent_at, 'finished_at' => $row->sent_at,
                ])->all());
            }, 'delivery.id', 'id');
    }

    public function down(): void
    {
        Schema::dropIfExists('action_history_events');
    }
};
