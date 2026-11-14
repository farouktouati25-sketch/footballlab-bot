<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Contacts saved before 14 Nov were "silent"; saved now means "old client".
    public function up(): void
    {
        DB::table('contacts')->whereNotNull('saved_at')->update(['client_type' => 'old']);
    }

    public function down(): void {}
};
