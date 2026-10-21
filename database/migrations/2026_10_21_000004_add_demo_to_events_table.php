<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A visitor's copy of the demo party ("Try a demo party"). Copies are pruned after a day.
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('demo')->default(false)->index();
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('demo');
        });
    }
};
