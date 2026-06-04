<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Survivors and their pairwise relationships, both JSON on the run.
 *
 *   characters: [ { name, role, traits[], stress, alive } ]
 *   relationships: [ { a, b, value } ]   value in [-100, 100]
 *
 * Kept on the run (not separate tables) for the same reason resources are:
 * the engine reads them as one snapshot per card, and a survivor is pure run
 * state, not a shared entity. Roster composition is data (config/seeder),
 * never engine code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->json('characters')->nullable();
            $table->json('relationships')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->dropColumn(['characters', 'relationships']);
        });
    }
};
