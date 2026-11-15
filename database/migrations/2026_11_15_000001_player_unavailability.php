<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            // Said in a chat they can't play (injured, travelling…): left out of suggestions until then.
            $table->date('unavailable_until')->nullable();
            $table->string('unavailable_reason', 120)->nullable();
            $table->unsignedBigInteger('availability_checked_message_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn(['unavailable_until', 'unavailable_reason', 'availability_checked_message_id']);
        });
    }
};
