<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ending a run reached. `ending_key` is the config endings key (null while
 * the run is active); `ending_type` is win|lose. `status` flips to 'ended' when
 * an ending fires, which stops the daily loop and the card flow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->string('ending_key')->nullable();
            $table->string('ending_type')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->dropColumn(['ending_key', 'ending_type']);
        });
    }
};
