<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional per-event weight modifiers:
 *   [ { when: <Condition>, factor: <float> }, ... ]
 *
 * The Selector multiplies an event's base_weight by every factor whose `when`
 * condition holds against the run state. Because `when` is the same Condition
 * DSL, a trait, a resource level, a flag, a relationship, or any composition
 * can bias selection — all from data, no per-event code. Example:
 * make a sabotage event more likely when a 'paranoid' survivor is aboard:
 *   { when: { trait_present: 'paranoid' }, factor: 2.0 }
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->json('weight_modifiers')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('weight_modifiers');
        });
    }
};
