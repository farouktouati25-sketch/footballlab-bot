<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Game extends Model
{
    protected $fillable = [
        'starts_at', 'venue', 'format', 'price', 'capacity', 'payment_link', 'notes', 'status', 'batch_id', 'source_text',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'price' => 'decimal:2',
            'capacity' => 'integer',
        ];
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function waitlist(): HasMany
    {
        return $this->hasMany(WaitlistEntry::class);
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('status', 'published')->where('starts_at', '>', now())->orderBy('starts_at');
    }

    /** Spots held by confirmed, awaiting-approval and still-valid unpaid bookings. */
    public function spotsTaken(?int $exceptBookingId = null): int
    {
        $cutoff = now()->subMinutes((int) config('fbl.payment_timeout_minutes', 120));

        return (int) $this->bookings()
            ->when($exceptBookingId, fn ($q) => $q->where('id', '!=', $exceptBookingId))
            ->where(function ($q) use ($cutoff) {
                $q->whereIn('status', ['confirmed', 'awaiting_approval'])
                    ->orWhere(fn ($q) => $q->where('status', 'pending_payment')->where('created_at', '>', $cutoff));
            })
            ->sum('players');
    }

    public function confirmedPlayers(): int
    {
        return (int) $this->bookings()->where('status', 'confirmed')->sum('players');
    }

    public function spotsLeft(?int $exceptBookingId = null): int
    {
        return max(0, $this->capacity - $this->spotsTaken($exceptBookingId));
    }

    public function paymentLink(): ?string
    {
        return $this->payment_link ?: config('fbl.defaults.payment_link');
    }

    public function localStart(): \Carbon\CarbonInterface
    {
        return $this->starts_at->copy()->setTimezone(config('fbl.timezone'));
    }

    /** e.g. "Thu 6 Nov, 9:00 PM · Al Safa Sports Ground (7-a-side)" */
    public function label(): string
    {
        $label = $this->localStart()->format('D j M, g:i A').' · '.$this->venue;

        return $this->format ? $label.' ('.$this->format.')' : $label;
    }
}
