<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Stage 1 (DJ agent) results. The pool is Qloo's ranked artists for this one love, kept server-side so the
        // set can be rebuilt, and every reason re-checked, without calling Qloo again (also after the key expires).
        Schema::table('guest_loves', function (Blueprint $table) {
            $table->string('status')->default('pending');
            $table->json('pool')->nullable();
        });

        // Which love produced a track, and Qloo's rank for it there, so a published reason can be verified.
        Schema::table('set_items', function (Blueprint $table) {
            $table->foreignId('reason_love_id')->nullable()->constrained('guest_loves')->nullOnDelete();
            $table->unsignedSmallInteger('reason_rank')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('set_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reason_love_id');
            $table->dropColumn('reason_rank');
        });

        Schema::table('guest_loves', function (Blueprint $table) {
            $table->dropColumn(['status', 'pool']);
        });
    }
};
