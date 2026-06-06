<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link a run to the profile it belongs to. Nullable + no hard FK constraint
 * (keeps migration ordering simple and stays MySQL/SQLite-portable); the app
 * always sets it via the profile resolver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->unsignedBigInteger('profile_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->dropColumn('profile_id');
        });
    }
};
