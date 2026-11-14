<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            // Tue/Fri/Sat/Sun: an admin confirms the pitch (and format) each night before players are told.
            $table->timestamp('venue_confirmed_at')->nullable();
        });

        // Sat/Sun were auto-published as 11v11: back to "waiting for the admins" if nobody booked and no list was posted.
        $games = DB::table('games')->whereIn('weekly_key', ['saturday@20:00', 'sunday@20:00'])
            ->where('status', 'published')->whereNull('source_text')
            ->where('starts_at', '>', now()->addHours(6))->pluck('id');
        foreach ($games as $id) {
            if (! DB::table('bookings')->where('game_id', $id)->exists()) {
                DB::table('games')->where('id', $id)->update(['status' => 'draft', 'format' => null, 'format_asked_at' => null]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn('venue_confirmed_at');
        });
    }
};
