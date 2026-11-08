<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->string('list_group', 36)->nullable()->index(); // games posted together in one post (Game 1 / Game 2)
            $table->unsignedTinyInteger('list_part')->nullable();  // which numbered list in that post is this game's
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropIndex(['list_group']);
            $table->dropColumn(['list_group', 'list_part']);
        });
    }
};
