<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Declarative event content. One row = one whole event (title, body, speaker,
 * weight, cooldown, requires-tree, and choices with embedded outcomes/effects).
 *
 * `requires` and `choices` are JSON: the DSL is a nested tree, so shredding it
 * into child tables would buy nothing and cost joins on every card draw.
 * One row per event keeps the Prime-Directive-#1 promise: adding an event is a
 * single seeder row, never an engine code change.
 *
 * `is_filler` marks the guaranteed always-eligible low-stakes pool the Selector
 * falls back to so the hand is never empty (build prompt §1.5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('title');
            $table->text('body');
            $table->string('speaker')->nullable();
            $table->unsignedInteger('base_weight')->default(10);
            $table->unsignedInteger('cooldown_days')->default(0);
            $table->boolean('is_filler')->default(false);

            // Condition tree; null/empty means "always eligible".
            $table->json('requires')->nullable();

            // [{ label, requires?, hint?, outcomes: [{ weight, effects[], log }] }]
            $table->json('choices');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
