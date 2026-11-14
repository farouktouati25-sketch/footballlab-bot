<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Automatic weekly games the admins never posted or confirmed are hidden from clients (back to draft).
    public function up(): void
    {
        DB::table('games')->whereNotNull('weekly_key')->where('status', 'published')
            ->whereNull('source_text')->whereNull('venue_confirmed_at')
            ->where('starts_at', '>', now())
            ->update(['status' => 'draft', 'updated_at' => now()]);
    }

    public function down(): void {}
};
