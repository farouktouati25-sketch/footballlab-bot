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
        $posted = Game::where('status', '!=', 'cancelled')
            ->whereBetween('starts_at', [$startsAt->copy()->subHours(2), $startsAt->copy()->addHours(2)])
            ->get()
            ->first(fn (Game $g) => Game::sameVenue($g->venue, $slot['venue']));
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
            'status' => $format ? 'published' : 'draft',
            'weekly_key' => $key,
        ]);

        if ($format) {
            // Same as a manual publish: tell people who asked for that day.
            $this->roster->notifyWaitlist($game);
            app(ReminderService::class)->notifyForGame($game);
        }

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
