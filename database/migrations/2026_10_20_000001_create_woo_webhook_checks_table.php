<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('woo_webhook_checks', function (Blueprint $table) {
            $table->foreignId('website_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('issues')->default(0);
            $table->string('state', 32);
            $table->timestamp('checked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('woo_webhook_checks');
    }
};
