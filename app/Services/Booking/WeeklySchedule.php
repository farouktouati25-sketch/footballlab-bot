<?php

namespace App\Services\Booking;

use App\Models\Contact;
use App\Models\Game;
use App\Services\WhatsApp\Messenger;
use Carbon\Carbon;

/**
 * Fixed weekly games from config('fbl.weekly'):
 *  - creates each game "weekly_days_ahead" days in advance (once; a cancelled one is never re-created),
 *  - games with a fixed format are published straight away,
 *  - games whose format changes weekly stay draft until an admin picks the format (asked the day before).
 */
class WeeklySchedule
{
    public function __construct(
        private readonly Messenger $messenger,
        private readonly RosterService $roster,
    ) {}

    /** @return Game[] games created this run */
    public function generate(): array
    {
        $tz = config('fbl.timezone');
        $created = [];
        $today = now($tz)->startOfDay();

        for ($d = 0; $d <= (int) config('fbl.weekly_days_ahead', 7); $d++) {
            $date = $today->copy()->addDays($d);
            foreach ((array) config('fbl.weekly', []) as $slot) {
                if (mb_strtolower($slot['day']) !== mb_strtolower($date->englishDayOfWeek)) {
                    continue;
                }
                $game = $this->createIfMissing($slot, $date);
                if ($game) {
                    $created[] = $game;
                }
            }
        }

        return $created;
    }

    private function createIfMissing(array $slot, Carbon $date): ?Game
    {
        $startsAt = Carbon::createFromFormat('!Y-m-d H:i', $date->toDateString().' '.$slot['time'], config('fbl.timezone'))->utc();
        if ($startsAt->isPast()) {
            return null;
        }
        $key = mb_strtolower($slot['day']).'@'.$slot['time'];

        // Already created (even if cancelled), or the admins already posted that game themselves.
        if (Game::where('weekly_key', $key)->where('starts_at', $startsAt)->exists()) {
            return null;
        }
        // Any game already set for that evening (any venue, ±2h) replaces the fixed slot that week.
        $posted = Game::where('status', '!=', 'cancelled')
            ->whereBetween('starts_at', [$startsAt->copy()->subHours(2), $startsAt->copy()->addHours(2)])
            ->exists();
        if ($posted) {
            return null;
        }

        $format = $slot['format'] ?? null;
        $terms = $this->terms($format ?? ($slot['choices'][0] ?? '11v11'), $slot['venue']);

        $game = Game::create([
            'starts_at' => $startsAt,
            'venue' => $slot['venue'],
            'format' => $format,
            'price' => $terms['price'],
            'capacity' => $terms['capacity'],
            'status' => 'draft', // only games the admins publish (post or confirm) are offered to clients
            'weekly_key' => $key,
        ]);

        return $game;
    }

    /** Price and spots for a format at a venue (venue rule wins). */
    public function terms(string $format, string $venue): array
    {
        foreach ((array) config('fbl.venue_pricing', []) as $needle => $terms) {
            if (str_contains(mb_strtolower($venue), mb_strtolower($needle))) {
                return $terms;
            }
        }

        return config('fbl.formats.'.$format) ?? ['price' => (float) config('fbl.defaults.price', 50), 'capacity' => (int) config('fbl.defaults.capacity', 14)];
    }

    /** Day before a "format changes weekly" game: ask admins which format it is. */
    public function askFormats(): int
    {
        $tz = config('fbl.timezone');
        $asked = 0;

        Game::where('status', 'draft')->whereNotNull('weekly_key')->whereNull('format')->whereNull('format_asked_at')
            ->where('starts_at', '>', now())
            ->get()
            ->each(function (Game $game) use ($tz, &$asked) {
                $askAt = Carbon::createFromFormat('!Y-m-d H:i',
                    $game->starts_at->copy()->setTimezone($tz)->subDay()->toDateString().' '.config('fbl.format_question_at', '12:00'), $tz);
                if ($askAt->isFuture()) {
                    return;
                }

                $choices = $this->choicesFor($game);
                $buttons = [];
                foreach (array_slice($choices, 0, 3) as $c) {
                    $buttons['fmt:'.$game->id.':'.$c] = $c === 'tournament' ? '🏆 Tournament' : $c;
                }
                $when = $game->localStart()->format('l j M, g:i A');
                $text = "📅 {$when} at {$game->venue}: what are we playing? (#{$game->id})";
                $typed = 'Reply: edit '.$game->id.' format '.implode(' / ', $choices);

                foreach ($this->messenger->adminContacts() as $admin) {
                    $admin->windowIsOpen()
                        ? $this->messenger->buttons($admin, $text."\nThe game opens for booking once you pick.", $buttons)
                        : $this->messenger->alertAdmin($admin, $text.' '.$typed);
                }
                $game->update(['format_asked_at' => now()]);
                $asked++;
            });

        return $asked;
    }

    /** Tue/Fri/Sat/Sun: the pitch is confirmed by an admin each night. */
    public function needsVenue(Game $game): bool
    {
        if ($game->venue_confirmed_at || ! $game->weekly_key) {
            return false;
        }
        foreach ((array) config('fbl.weekly', []) as $slot) {
            if ($game->weekly_key === mb_strtolower($slot['day']).'@'.$slot['time']) {
                return ($slot['venues'] ?? null) === 'nightly';
            }
        }

        return false;
    }

    /** Ask the admins where tonight's game is (buttons, or a typed command if their chat window is closed). */
    public function askVenue(Game $game, ?Contact $picker = null): void
    {
        $buttons = [];
        $i = 0;
        foreach (array_slice((array) config('fbl.nightly_venues', []), 0, 3, true) as $title => $venue) {
            $buttons['loc:'.$game->id.':'.$i++] = $title;
        }
        $when = $game->localStart()->format('l j M, g:i A');
        $text = "📍 {$when} ({$game->format}): which pitch? (#{$game->id})";
        foreach ($this->messenger->adminContacts() as $admin) {
            if ($picker && $admin->wa_id !== $picker->wa_id && ! $admin->windowIsOpen()) {
                continue;
            }
            $admin->windowIsOpen()
                ? $this->messenger->buttons($admin, $text."\nPlayers get confirmed once you pick.", $buttons)
                : $this->messenger->alertAdmin($admin, $text.' Reply: edit '.$game->id.' pitch safa / schools / sheba');
        }
    }

    /** Turn "safa", "barsha", "nad al sheba"… into the venue name. */
    public static function resolveVenue(string $text): ?string
    {
        $t = mb_strtolower(trim($text));
        foreach ((array) config('fbl.nightly_venues', []) as $title => $venue) {
            if ($t === mb_strtolower($title) || $t === mb_strtolower($venue)) {
                return $venue;
            }
        }
        return match (true) {
            str_contains($t, 'safa') => 'Al Safa Sports Ground',
            str_contains($t, 'school') || str_contains($t, 'barsha') => 'Dubai Schools Al Barsha',
            str_contains($t, 'sheba') || str_contains($t, 'north london') || str_contains($t, 'nlcs') => 'North London Collegiate School - Nad Al Sheba',
            default => null,
        };
    }

    /**
     * Admin confirmed the pitch: set price/spots for that pitch and publish if the format is known too.
     *
     * @return string reply for the admin
     */
    public function setVenue(Game $game, string $venue, Contact $admin): string
    {
        $wasDraft = $game->status === 'draft';
        $terms = $this->terms($game->format ?: ($this->choicesFor($game)[0] ?? '11v11'), $venue);
        $game->update(['venue' => $venue, 'venue_confirmed_at' => now(), 'price' => $terms['price'],
            'capacity' => max($terms['capacity'], $game->confirmedPlayers())]);
        $game = $game->fresh();

        if ($wasDraft && ! $game->format) {
            return "📍 #{$game->id} at {$venue}. Now the format: edit {$game->id} format ".implode(' / ', $this->choicesFor($game));
        }
        if (! $wasDraft) {
            return "✅ #{$game->id} {$game->label()} · ".(float) $game->price.' '.config('fbl.currency')." · {$game->capacity} spots.";
        }

        $game->update(['status' => 'published']);
        $game = $game->fresh();
        $line = "#{$game->id} {$game->label()} · ".(float) $game->price.' '.config('fbl.currency')." · {$game->capacity} spots";
        foreach ($this->messenger->adminContacts() as $other) {
            if ($other->wa_id !== $admin->wa_id) {
                $this->messenger->alertAdmin($other, "✅ {$admin->adminName()} set {$line} and it is open for booking.");
            }
        }
        $notified = $this->roster->notifyWaitlist($game);
        $reminded = app(ReminderService::class)->notifyForGame($game);

        return "✅ {$line} is open for booking."
            .($game->format === 'tournament' ? "\nChange price/spots with: edit {$game->id} price 70 · edit {$game->id} spots 24" : '')
            .($notified ? " Told {$notified} waitlisted player(s)." : '')
            .($reminded ? " {$reminded} player(s) to invite: list below 👇" : '');
    }

    /** @return string[] */
    public function choicesFor(Game $game): array
    {
        foreach ((array) config('fbl.weekly', []) as $slot) {
            if ($game->weekly_key === mb_strtolower($slot['day']).'@'.$slot['time']) {
                return $slot['choices'] ?? [$slot['format'] ?? '11v11'];
            }
        }

        return array_keys((array) config('fbl.formats', []));
    }

    /**
     * Admin picked the format: set price/spots and publish (if still a draft).
     *
     * @return string reply for the admin
     */
    public function setFormat(Game $game, string $format, Contact $admin): string
    {
        $format = mb_strtolower(trim($format));
        if (! array_key_exists($format, (array) config('fbl.formats', []))) {
            return 'Unknown format "'.$format.'". Use one of: '.implode(', ', array_keys((array) config('fbl.formats', []))).'.';
        }

        $terms = $this->terms($format, $game->venue);
        $wasDraft = $game->status === 'draft';

        // Pitch still to confirm for tonight: save the format, then ask where.
        if ($wasDraft && $this->needsVenue($game)) {
            $game->update(['format' => $format, 'price' => $terms['price'], 'capacity' => max($terms['capacity'], $game->confirmedPlayers())]);
            $this->askVenue($game->fresh(), $admin);

            return "👍 #{$game->id} is {$format}. Which pitch? 👇";
        }

        $game->update(['format' => $format, 'price' => $terms['price'], 'capacity' => max($terms['capacity'], $game->confirmedPlayers())]
            + ($wasDraft ? ['status' => 'published'] : []));
        $game = $game->fresh();

        $line = "#{$game->id} {$game->label()} · ".(float) $game->price.' '.config('fbl.currency')." · {$game->capacity} spots";
        foreach ($this->messenger->adminContacts() as $other) {
            if ($other->wa_id !== $admin->wa_id) {
                $this->messenger->alertAdmin($other, "✅ {$admin->adminName()} set {$line}".($wasDraft ? ' and it is open for booking.' : '.'));
            }
        }

        if (! $wasDraft) {
            return "✅ Updated {$line}.";
        }

        $notified = $this->roster->notifyWaitlist($game);
        $reminded = app(ReminderService::class)->notifyForGame($game);

        return "✅ {$line} is open for booking."
            .($format === 'tournament' ? "\nChange price/spots with: edit {$game->id} price 70 · edit {$game->id} spots 24" : '')
            .($notified ? " Told {$notified} waitlisted player(s)." : '')
            .($reminded ? " {$reminded} player(s) to invite: list below 👇" : '');
    }
}
