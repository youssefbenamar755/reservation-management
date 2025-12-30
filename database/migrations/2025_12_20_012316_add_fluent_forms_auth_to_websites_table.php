<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            // Fluent Forms authentication using WordPress Application Passwords
            $table->text('ff_username')->nullable()->after('ff_api_key');
            $table->text('ff_app_password')->nullable()->after('ff_username');
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropColumn(['ff_username', 'ff_app_password']);
        });
    }
};
