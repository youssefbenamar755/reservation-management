<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('wc_orders', function (Blueprint $table) {
            $table->index(['website_id', 'created_at_wp'], 'wc_orders_website_created_idx');
            $table->index(['website_id', 'status', 'created_at_wp'], 'wc_orders_website_status_created_idx');
        });

        Schema::table('ff_submissions', function (Blueprint $table) {
            $table->index(['website_id', 'created_at_wp'], 'ff_submissions_website_created_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wc_orders', function (Blueprint $table) {
            $table->dropIndex('wc_orders_website_created_idx');
            $table->dropIndex('wc_orders_website_status_created_idx');
        });

        Schema::table('ff_submissions', function (Blueprint $table) {
            $table->dropIndex('ff_submissions_website_created_idx');
        });
    }
};
