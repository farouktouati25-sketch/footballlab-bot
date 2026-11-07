<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WaitlistEntry extends Model
{
    protected $fillable = ['contact_id', 'game_id', 'players', 'notified_at', 'admin_asked_at', 'offer_sent_at', 'skipped_at'];

    protected function casts(): array
    {
        return ['notified_at' => 'datetime', 'players' => 'integer', 'admin_asked_at' => 'datetime',
            'offer_sent_at' => 'datetime', 'skipped_at' => 'datetime'];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
