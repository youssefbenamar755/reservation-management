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
        Schema::table('websites', function (Blueprint $table) {
            // Add user_id column after id
            $table->foreignId('user_id')
                ->after('id')
                ->nullable() // Temporarily nullable for existing records
                ->constrained()
                ->cascadeOnDelete();
            
            $table->index('user_id');
        });

        // Assign existing websites to the first user if any exist
        $firstUserId = DB::table('users')->orderBy('id')->value('id');
        
        if ($firstUserId) {
            DB::table('websites')
                ->whereNull('user_id')
                ->update(['user_id' => $firstUserId]);
        }

        // Make user_id not nullable after backfilling
        Schema::table('websites', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};
