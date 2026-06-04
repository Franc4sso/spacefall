<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A single playthrough. Holds the five resources, the day counter, and the
 * seeded-RNG state (seed + cursor) that makes the run reproducible.
 *
 * Resource values live in a JSON column rather than five fixed columns so
 * that adding/removing a resource is a config/game.php edit, not a migration
 * (Prime Directive #1). Schema stays MySQL-compatible (json type).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('runs', function (Blueprint $table) {
            $table->id();

            // Reproducibility contract: seed + monotonic cursor.
            $table->bigInteger('seed');
            $table->bigInteger('rng_cursor')->default(0);

            $table->unsignedInteger('day')->default(1);

            // { oxygen: int, food: int, ... } — keys come from config('game.resources').
            $table->json('resources');

            // Lifecycle: 'active' while playable, 'ended' once an ending fires (Phase 6).
            $table->string('status')->default('active');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('runs');
    }
};
