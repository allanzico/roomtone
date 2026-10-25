<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The artist's Qloo genres when the track was last picked: what island the track's dot belongs to.
        Schema::table('tracks', function (Blueprint $table) {
            $table->json('genres')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tracks', function (Blueprint $table) {
            $table->dropColumn('genres');
        });
    }
};
