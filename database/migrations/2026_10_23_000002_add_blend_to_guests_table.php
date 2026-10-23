<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Qloo's middle ground between all of a guest's loves (one insights call with every signal): {loves, text, pool}.
        Schema::table('guests', function (Blueprint $table) {
            $table->json('blend')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->dropColumn('blend');
        });
    }
};
