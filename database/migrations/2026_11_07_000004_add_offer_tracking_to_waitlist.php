<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('waitlist_entries', function (Blueprint $table) {
            $table->timestamp('admin_asked_at')->nullable();  // bot asked admins "spot free, offer it?"
            $table->timestamp('offer_sent_at')->nullable();   // admin said yes, client was told
            $table->timestamp('skipped_at')->nullable();      // admin said skip
        });
    }

    public function down(): void
    {
        Schema::table('waitlist_entries', fn (Blueprint $t) => $t->dropColumn(['admin_asked_at', 'offer_sent_at', 'skipped_at']));
    }
};
