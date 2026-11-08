<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->string('weekly_key', 32)->nullable()->index(); // e.g. "tuesday@21:00" for auto-created fixed games
            $table->timestamp('format_asked_at')->nullable();
        });
        Schema::table('bookings', function (Blueprint $table) {
            $table->timestamp('link_due_at')->nullable(); // payment link is sent the day before the game
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropIndex(['weekly_key']);
            $table->dropColumn(['weekly_key', 'format_asked_at']);
        });
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('link_due_at');
        });
    }
};
