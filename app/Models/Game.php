<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Game extends Model
{
    protected $fillable = [
        'starts_at', 'venue', 'format', 'price', 'capacity', 'payment_link', 'notes', 'status', 'batch_id', 'source_text', 'weekly_key', 'format_asked_at', 'list_group', 'list_part',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'price' => 'decimal:2',
            'capacity' => 'integer',
            'format_asked_at' => 'datetime',
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

    /** Loose venue match ("AL SAFA SPORTS HALL" = "Al Safa Sports Ground"). */
    public static function sameVenue(string $a, string $b): bool
    {
        $words = fn (string $s) => array_filter(preg_split('/[^a-z0-9]+/', mb_strtolower($s)), fn ($w) => strlen($w) > 3
            && ! in_array($w, ['sports', 'ground', 'dubai', 'school', 'schools', 'pitch', 'field'], true));

        return (bool) array_intersect($words($a), $words($b)) || mb_strtolower($a) === mb_strtolower($b);
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
                    ->orWhere(fn ($q) => $q->where('status', 'pending_payment')->where(
                        // still within the hold, or still waiting for an admin to send the link
                        fn ($q) => $q->where('created_at', '>', $cutoff)->orWhereNull('payment_url')
                    ));
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
    /** "8-a-side", "8 vs 8", "8x8", "8V8" → "8v8"; "tournament" → "tournament"; anything else unchanged. */
    public static function normalizeFormat(?string $format): ?string
    {
        $f = mb_strtolower(trim((string) $format));
        if ($f === '') {
            return null;
        }
        if (str_contains($f, 'tournament') || str_contains($f, 'tourney')) {
            return 'tournament';
        }
        if (preg_match('/(\d{1,2})\s*(?:v|vs\.?|x|-?a-?side|aside|\s+a\s+side)/u', $f, $m)) {
            return $m[1].'v'.$m[1];
        }

        return mb_substr(trim((string) $format), 0, 40);
    }

    public function label(): string
    {
        $label = $this->localStart()->format('D j M, g:i A').' · '.$this->venue;
        $label = $this->format ? $label.' ('.$this->format.')' : $label;

        // Two games at the same time and place: "Game 1" / "Game 2" (in the order they were added).
        if ($this->exists && $this->starts_at) {
            $twins = static::where('starts_at', $this->starts_at)->where('status', '!=', 'cancelled')->orderBy('id')->get(['id', 'venue'])
                ->filter(fn (Game $g) => static::sameVenue($g->venue, $this->venue))->pluck('id')->values();
            if ($twins->count() > 1) {
                $label .= ' · Game '.($twins->search($this->id) + 1);
            }
        }

        return $label;
    }
}
