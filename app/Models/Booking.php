<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Booking extends Model
{
    public const OPEN_STATUSES = ['pending_payment', 'awaiting_approval', 'confirmed'];

    /** payment_note marker: client wants to pay later (after the game / cash at the pitch), not paid yet. */
    public const PAY_LATER_NOTE = 'PAY LATER';

    /** Shown after a goalkeeper's name on the list. */
    public const GK_MARK = '🧤';

    /** Name without the keeper mark, for comparing names ("Omar 🧤" = "Omar" = "Omar (GK)"). */
    public static function plainName(string $name): string
    {
        $n = str_replace(self::GK_MARK, '', $name);
        $n = preg_replace('/\((gk|goalkeeper|keeper)\)|\b(gk)\b/iu', '', $n) ?? $n;

        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $n) ?? $n));
    }

    /** Fresh booking waiting for an admin: confirm straight away, or send a payment link first. */
    public const NEW_BOOKING_NOTE = 'NEW BOOKING';

    public function isNewBookingRequest(): bool
    {
        return $this->status === 'awaiting_approval' && $this->payment_note === self::NEW_BOOKING_NOTE
            && ! $this->payment_proof_media_id && ! $this->payment_verified;
    }

    public function wantsCash(): bool
    {
        return str_starts_with((string) $this->payment_note, self::PAY_LATER_NOTE)
            || str_starts_with((string) $this->payment_note, 'CASH on the spot');
    }

    protected $fillable = [
        'reference', 'contact_id', 'source', 'game_id', 'player_name', 'players', 'amount', 'status',
        'payment_intent_id', 'payment_url', 'link_requested_at', 'link_due_at', 'link_sent_by', 'payment_verified', 'paid_at', 'payment_proof_media_id', 'payment_proof_mime', 'payment_note', 'payment_reported_at', 'decided_by', 'decided_at', 'admin_reminded_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'players' => 'integer',
            'payment_reported_at' => 'datetime',
            'payment_verified' => 'boolean',
            'paid_at' => 'datetime',
            'link_requested_at' => 'datetime',
            'link_due_at' => 'datetime',
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
