<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->timestamp('link_requested_at')->nullable()->after('payment_url');
            $table->string('link_sent_by', 32)->nullable()->after('link_requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['link_requested_at', 'link_sent_by']);
        });
    }
};
