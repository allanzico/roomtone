<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taste_clusters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->string('color', 7);
            $table->json('position');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taste_clusters');
    }
};
