<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submission_itineraries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ff_submission_id')->unique()->constrained('ff_submissions')->cascadeOnDelete();
            $table->string('reference', 32)->unique();
            $table->string('reference_type', 16);
            $table->string('external_pnr', 20)->nullable();
            $table->string('booking_source', 120)->nullable();
            $table->string('template', 32)->default('classic_v1');
            $table->json('snapshot');
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_itineraries');
    }
};
