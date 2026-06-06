<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Station systems state: { system_key: { efficiency: int } }. Degrades daily
 * and can be damaged by the damage_system effect; below a threshold it bleeds
 * a resource each day (see config game.systems). Keyed map so the `system`
 * condition can read efficiency without a join.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->json('systems')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->dropColumn('systems');
        });
    }
};
