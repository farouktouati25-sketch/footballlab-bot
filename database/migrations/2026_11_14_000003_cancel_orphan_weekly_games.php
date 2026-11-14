<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // An automatic weekly game nobody booked, with a game the admins posted the same evening (±2h): the post replaced it.
    public function up(): void
    {
        foreach (DB::table('games')->whereNotNull('weekly_key')->whereIn('status', ['published', 'draft'])->where('starts_at', '>', now())->get() as $w) {
            $posted = DB::table('games')->whereNull('weekly_key')->where('status', 'published')
                ->whereBetween('starts_at', [\Carbon\Carbon::parse($w->starts_at)->subHours(2), \Carbon\Carbon::parse($w->starts_at)->addHours(2)])
                ->exists();
            $booked = DB::table('bookings')->where('game_id', $w->id)->whereIn('status', ['pending_payment', 'awaiting_approval', 'confirmed'])->exists();
            if ($posted && ! $booked) {
                DB::table('games')->where('id', $w->id)->update(['status' => 'cancelled', 'updated_at' => now()]);
            }
        }
    }

    public function down(): void {}
};
