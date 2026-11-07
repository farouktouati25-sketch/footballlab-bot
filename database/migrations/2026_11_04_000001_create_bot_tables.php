<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->string('wa_id', 32)->unique();
            $table->string('name')->nullable();
            $table->timestamp('last_inbound_at')->nullable();
            $table->timestamp('bot_paused_until')->nullable();
            $table->boolean('act_as_client')->default(false); // admin test mode
            $table->json('first_referral')->nullable();        // Click-to-WhatsApp ad that brought them
            $table->string('source')->nullable();              // e.g. "meta_ad", "organic"
            $table->timestamps();
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 8);   // in | out
            $table->string('author', 16);     // client | bot | human | system
            $table->string('type', 32);       // text | image | button | template | ...
            $table->text('body')->nullable();
            $table->string('wa_message_id')->nullable()->unique();
            $table->json('payload')->nullable();
            $table->timestamp('processed_at')->nullable(); // inbound only: handler finished
            $table->timestamps();
            $table->index(['contact_id', 'id']);
        });

        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->dateTime('starts_at');                 // stored in UTC
            $table->string('venue');
            $table->string('format')->nullable();          // e.g. "7-a-side"
            $table->decimal('price', 8, 2);
            $table->unsignedSmallInteger('capacity');
            $table->string('payment_link')->nullable();
            $table->string('notes')->nullable();
            $table->string('status', 16)->default('draft'); // draft | published | cancelled
            $table->uuid('batch_id')->nullable()->index();
            $table->text('source_text')->nullable();
            $table->timestamps();
            $table->index(['status', 'starts_at']);
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 16)->unique();
            // null for players an admin added by hand ("add 12 Omar")
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 16)->default('bot'); // bot | manual
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->string('player_name');
            $table->unsignedTinyInteger('players')->default(1);
            $table->decimal('amount', 8, 2);
            // pending_payment | awaiting_approval | confirmed | rejected | cancelled | expired
            $table->string('status', 24)->default('pending_payment');
            $table->string('payment_proof_media_id')->nullable(); // re-uploaded media id
            $table->string('payment_proof_mime', 64)->nullable();
            $table->string('payment_note')->nullable();
            $table->timestamp('payment_reported_at')->nullable();
            $table->string('decided_by', 32)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('admin_reminded_at')->nullable();
            $table->timestamps();
            $table->index(['game_id', 'status']);
            $table->index(['contact_id', 'status']);
        });

        Schema::create('waitlist_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('players')->default(1);
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
            $table->unique(['contact_id', 'game_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waitlist_entries');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('games');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('contacts');
    }
};
