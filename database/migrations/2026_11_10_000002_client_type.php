<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            // Unknown number (no ad, no history): an admin says old client / new player. 'guess' = nobody answered in time.
            $table->string('client_type', 10)->nullable();
            $table->timestamp('client_type_asked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn(['client_type', 'client_type_asked_at']);
        });
    }
};
