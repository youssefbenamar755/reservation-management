<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_contact_submissions', function (Blueprint $table) {
            $table->unsignedBigInteger('ff_submission_id')->primary();
            $table->unsignedBigInteger('marketing_contact_id')->index('mcs_contact_index');
            $table->foreign('ff_submission_id', 'mcs_submission_fk')->references('id')->on('ff_submissions')->cascadeOnDelete();
            $table->foreign('marketing_contact_id', 'mcs_contact_fk')->references('id')->on('marketing_contacts')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_contact_submissions');
    }
};
