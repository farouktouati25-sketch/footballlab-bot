<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Booking extends Model
{
    public const OPEN_STATUSES = ['pending_payment', 'awaiting_approval', 'confirmed'];

    protected $fillable = [
        'reference', 'contact_id', 'source', 'game_id', 'player_name', 'players', 'amount', 'status',
        'payment_proof_media_id', 'payment_proof_mime', 'payment_note', 'payment_reported_at', 'decided_by', 'decided_at', 'admin_reminded_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'players' => 'integer',
            'payment_reported_at' => 'datetime',
            'decided_at' => 'datetime',
            'admin_reminded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Booking $booking) {
            if (! $booking->reference) {
                do {
                    // No 0/O/1/I so references are easy to read out loud.
                    $ref = 'FBL-'.substr(str_shuffle(str_repeat('23456789ABCDEFGHJKLMNPQRSTUVWXYZ', 3)), 0, 5);
                } while (static::where('reference', $ref)->exists());
                $booking->reference = $ref;
            }
        });
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function amountLabel(): string
    {
        $amount = (float) $this->amount;

        return (fmod($amount, 1.0) === 0.0 ? number_format($amount, 0) : number_format($amount, 2)).' '.config('fbl.currency');
    }

    public static function findByReference(string $reference): ?self
    {
        return static::where('reference', Str::upper(trim($reference)))->first();
    }
}
