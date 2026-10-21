<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Set once a track has been looked up on YouTube, found or not, so a title with no video is never searched
        // again (search quota is 100 calls a day).
        Schema::table('tracks', function (Blueprint $table) {
            $table->timestamp('youtube_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tracks', function (Blueprint $table) {
            $table->dropColumn('youtube_checked_at');
        });
    }
};
