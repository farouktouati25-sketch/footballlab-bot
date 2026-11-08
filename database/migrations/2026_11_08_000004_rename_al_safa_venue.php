<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // The pitch is "Al Safa Sports Ground" (it isn't in Nad Al Sheba).
    public function up(): void
    {
        DB::table('games')->where('venue', 'Al Safa Sports Ground, Nad Al Sheba')->update(['venue' => 'Al Safa Sports Ground']);
    }

    public function down(): void
    {
        //
    }
};
