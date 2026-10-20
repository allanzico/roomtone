<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Global cache of tracks, shared by every event.
        Schema::create('tracks', function (Blueprint $table) {
            $table->id();
            $table->string('artist');
            $table->string('title');
            $table->string('youtube_video_id')->nullable();
            $table->unsignedSmallInteger('bpm')->nullable();
            $table->unsignedTinyInteger('energy')->default(3);
            $table->string('qloo_artist_id')->nullable();
            $table->unique(['artist', 'title']);
        });

        Schema::create('set_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('track_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('status')->default('queued');
            $table->foreignId('reason_guest_id')->nullable()->constrained('guests')->nullOnDelete();
            $table->string('reason_text');
            $table->index(['event_id', 'status', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('set_items');
        Schema::dropIfExists('tracks');
    }
};
