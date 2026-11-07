<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AvailabilityRequest extends Model
{
    protected $fillable = ['contact_id', 'play_date', 'players', 'notified_at'];

    protected function casts(): array
    {
        return ['notified_at' => 'datetime', 'players' => 'integer'];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
