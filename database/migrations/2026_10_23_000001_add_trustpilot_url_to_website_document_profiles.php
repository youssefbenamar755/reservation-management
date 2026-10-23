<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('website_document_profiles', function (Blueprint $table) {
            $table->text('trustpilot_url')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('website_document_profiles', function (Blueprint $table) {
            $table->dropColumn('trustpilot_url');
        });
    }
};
