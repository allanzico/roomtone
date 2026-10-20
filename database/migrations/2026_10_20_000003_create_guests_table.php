<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('color', 7);
            $table->timestamps();
        });

        Schema::create('guest_loves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guest_id')->constrained()->cascadeOnDelete();
            $table->string('raw_text');
            $table->string('qloo_entity_id')->nullable();
            $table->string('qloo_type')->nullable();
            $table->string('resolved_name')->nullable();
        });

        // How strongly each guest belongs to each taste cluster (0–1). Written by the agent later.
        Schema::create('guest_taste_cluster', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guest_id')->constrained()->cascadeOnDelete();
            $table->foreignId('taste_cluster_id')->constrained()->cascadeOnDelete();
            $table->float('weight')->default(1);
            $table->unique(['guest_id', 'taste_cluster_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_taste_cluster');
        Schema::dropIfExists('guest_loves');
        Schema::dropIfExists('guests');
    }
};
