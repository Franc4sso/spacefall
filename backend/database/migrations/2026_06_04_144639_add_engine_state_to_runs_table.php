<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Engine state carried on the run:
 *   flags             { name: scalar } — run-scoped memory (Reigns callbacks).
 *                     Profile-scoped flags live elsewhere (Phase 7).
 *   recent_events     { event_key: last_seen_day } — drives cooldowns.
 *   scheduled_events  [ { key, fire_on_day } ] — delayed/spawned events.
 *   current_event_key the card currently presented (set by the Selector,
 *                     cleared when its choice resolves).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->json('flags')->nullable();
            $table->json('recent_events')->nullable();
            $table->json('scheduled_events')->nullable();
            $table->string('current_event_key')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->dropColumn(['flags', 'recent_events', 'scheduled_events', 'current_event_key']);
        });
    }
};
