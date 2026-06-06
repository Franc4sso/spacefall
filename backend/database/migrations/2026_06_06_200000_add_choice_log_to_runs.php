<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->json('choice_log')->default('[]');
        });
    }

    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->dropColumn('choice_log');
        });
    }
};
