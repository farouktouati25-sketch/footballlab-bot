<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('payment_intent_id')->nullable()->unique()->after('amount');
            $table->string('payment_url', 512)->nullable()->after('payment_intent_id');
            $table->boolean('payment_verified')->default(false)->after('payment_url');
            $table->timestamp('paid_at')->nullable()->after('payment_verified');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropUnique(['payment_intent_id']);
            $table->dropColumn(['payment_intent_id', 'payment_url', 'payment_verified', 'paid_at']);
        });
    }
};
