<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->timestamp('handoff_at')->nullable()->index(); // bot asked the team to step in, no human reply yet
            $table->string('handoff_reason', 255)->nullable();
            $table->unsignedTinyInteger('handoff_reminders')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex(['handoff_at']);
            $table->dropColumn(['handoff_at', 'handoff_reason', 'handoff_reminders']);
        });
    }
};
