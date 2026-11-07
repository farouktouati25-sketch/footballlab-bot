<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "I want to play next Friday": kept until that day's game is published.
        Schema::create('availability_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->date('play_date');
            $table->unsignedTinyInteger('players')->default(1);
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
            $table->unique(['contact_id', 'play_date']);
        });

        // One reminder per person per game day, never more.
        Schema::create('game_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->date('play_date');
            $table->string('reason', 16); // asked | last_week
            $table->boolean('sent')->default(false);
            $table->timestamps();
            $table->unique(['contact_id', 'play_date']);
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->timestamp('reminders_opted_out_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_reminders');
        Schema::dropIfExists('availability_requests');
        Schema::table('contacts', fn (Blueprint $t) => $t->dropColumn('reminders_opted_out_at'));
    }
};
