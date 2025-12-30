<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Check if amadeus_code column exists
        if (Schema::hasColumn('ff_submissions', 'amadeus_code')) {
            Schema::table('ff_submissions', function (Blueprint $table) {
                $table->renameColumn('amadeus_code', 'amadeus_command_block');
            });
            
            // To be safe with types in SQLite, we often accept the existing type if we can't easily change it.
            // But if we must change type to TEXT, we can try change() but it requires dbal.
            // For now, rename is likely sufficient to fix the syntax error.
             Schema::table('ff_submissions', function (Blueprint $table) {
                $table->text('amadeus_command_block')->nullable()->change();
            });
        } else {
            // Create new column if it doesn't exist AND the new name doesn't exist
            if (!Schema::hasColumn('ff_submissions', 'amadeus_command_block')) {
                Schema::table('ff_submissions', function (Blueprint $table) {
                    $table->text('amadeus_command_block')->nullable()->after('payload');
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('ff_submissions', 'amadeus_command_block')) {
             Schema::table('ff_submissions', function (Blueprint $table) {
                $table->renameColumn('amadeus_command_block', 'amadeus_code');
            });
        }
    }
};
