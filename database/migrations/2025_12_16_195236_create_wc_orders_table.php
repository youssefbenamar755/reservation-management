<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('wc_orders', function (Blueprint $table) {
            $table->id();

            $table->foreignId('website_id')->constrained()->cascadeOnDelete();

            $table->unsignedBigInteger('wp_order_id'); // Woo order ID
            $table->string('status')->index();
            $table->string('currency', 10)->nullable();

            $table->decimal('total', 12, 2)->default(0);

            $table->string('customer_email')->nullable()->index();
            $table->string('customer_name')->nullable();

            $table->timestamp('created_at_wp')->nullable()->index();
            $table->timestamp('updated_at_wp')->nullable()->index();

            $table->json('payload');

            $table->timestamps();

            $table->unique(['website_id', 'wp_order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wc_orders');
    }
};
