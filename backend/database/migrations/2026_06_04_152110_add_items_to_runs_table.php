<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The run's chosen equipment: a flat list of item keys (the start-of-run
 * pick-5). Items gate *choices* via the existing `has_item` condition, so they
 * change which routes a run can take — not just its numbers (design §2.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->json('items')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->dropColumn('items');
        });
    }
};
