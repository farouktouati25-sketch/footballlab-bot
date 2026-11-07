<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('player_name', 120)->nullable();   // the name they gave the bot ("Farouk")
            $table->json('preferred_days')->nullable();        // [0..6], 0 = Sunday ("I'm free Sundays")
        });
        Schema::table('game_reminders', function (Blueprint $table) {
            // pending = waiting for an admin OK · sent · failed · skipped
            $table->string('status', 12)->default('sent');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', fn (Blueprint $t) => $t->dropColumn(['player_name', 'preferred_days']));
        Schema::table('game_reminders', fn (Blueprint $t) => $t->dropColumn('status'));
    }
};
