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
        Schema::create('ff_forms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('form_id');
            $table->string('title')->nullable();
            $table->json('fields'); // stores field schema: { "names": { "label": "Name", "type": "name" }, ... }
            $table->timestamps();

            $table->unique(['website_id', 'form_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ff_forms');
    }
};
