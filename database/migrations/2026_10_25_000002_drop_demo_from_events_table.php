<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The demo party is gone: delete the template (code DEMO) and every visitor's copy (guests, loves, sets cascade).
        if (Schema::hasColumn('events', 'demo')) {
            DB::table('events')->where('demo', true)->delete();
        }

        DB::table('events')->where('code', 'DEMO')->delete();

        if (Schema::hasColumn('events', 'demo')) {
            Schema::table('events', function (Blueprint $table) {
                $table->dropIndex(['demo']);
                $table->dropColumn('demo');
            });
        }
    }

    public function down(): void
    {
        // Nothing to bring back.
    }
};
