<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The persistent player profile — what survives ACROSS runs.
 *
 *   handle           a stable identifier (email or 'anonymous'); one profile
 *                    per handle. No auth scaffolding (out of scope) — the API
 *                    just resolves-or-creates by handle.
 *   research_points  meta currency, earned even on a loss, spent on unlocks.
 *   unlocks          [unlock_key,...] the player has purchased (content, not
 *                    stat boosts — see config game.unlocks).
 *   flags            PROFILE-scoped flags: the cross-run memory. A flag set in
 *                    one run (scope: profile) is readable by a later run's
 *                    condition. This is the signature replayability feature.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profiles', function (Blueprint $table) {
            $table->id();
            $table->string('handle')->unique();
            $table->unsignedInteger('research_points')->default(0);
            $table->json('unlocks')->nullable();
            $table->json('flags')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profiles');
    }
};
