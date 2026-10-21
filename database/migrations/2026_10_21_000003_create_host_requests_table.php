<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What the host asked the DJ mid-party, how it was read, and the DJ's one-line answer.
        Schema::create('host_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('text', 200);
            $table->string('status')->default('pending');
            $table->json('interpreted')->nullable();
            $table->string('reply')->nullable();
            $table->timestamps();
        });

        // Tracks a request placed: they stay at the front of the queue through set rebuilds until played.
        Schema::table('set_items', function (Blueprint $table) {
            $table->foreignId('host_request_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('set_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('host_request_id');
        });

        Schema::dropIfExists('host_requests');
    }
};
