<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_documents', function (Blueprint $table) {
            $table->foreignId('submission_itinerary_id')->nullable()->constrained('submission_itineraries')->nullOnDelete();
            $table->string('source_version', 64)->nullable();
            $table->json('source_metadata')->nullable();
            $table->unique(['wc_order_id', 'submission_itinerary_id', 'source_version'], 'order_document_itinerary_version_unique');
        });
    }

    public function down(): void
    {
        Schema::table('order_documents', function (Blueprint $table) {
            $table->dropUnique('order_document_itinerary_version_unique');
            $table->dropConstrainedForeignId('submission_itinerary_id');
            $table->dropColumn(['source_version', 'source_metadata']);
        });
    }
};
