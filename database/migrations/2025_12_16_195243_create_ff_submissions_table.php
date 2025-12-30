<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ff_submissions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('website_id')->constrained()->cascadeOnDelete();

            $table->unsignedBigInteger('form_id');
            $table->unsignedBigInteger('entry_id'); // submission id / entry id
            $table->string('email')->nullable()->index();

            $table->timestamp('created_at_wp')->nullable()->index();

            $table->json('payload');

            $table->timestamps();

            $table->unique(['website_id', 'form_id', 'entry_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ff_submissions');
    }
};
