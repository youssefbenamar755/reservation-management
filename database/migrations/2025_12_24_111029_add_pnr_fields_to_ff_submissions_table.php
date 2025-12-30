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
            $table->string('pnr')->nullable()->after('amadeus_generated_at');
            $table->timestamp('pnr_generated_at')->nullable()->after('pnr');
            $table->string('pnr_pdf_path')->nullable()->after('pnr_generated_at');
            $table->string('pnr_source')->nullable()->after('pnr_pdf_path'); // 'amadeus_direct' or 'amadeus_search'
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ff_submissions', function (Blueprint $table) {
            $table->dropColumn(['pnr', 'pnr_generated_at', 'pnr_pdf_path', 'pnr_source']);
        });
    }
};
