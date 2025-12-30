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
        Schema::table('ff_submissions', function (Blueprint $table) {
            $table->string('amadeus_code')->nullable()->after('payload');
            $table->timestamp('amadeus_generated_at')->nullable()->after('amadeus_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ff_submissions', function (Blueprint $table) {
            $table->dropColumn(['amadeus_code', 'amadeus_generated_at']);
        });
    }
};
