<?php

namespace App\Services\Booking;

use App\Models\Booking;
use App\Models\Game;
use App\Models\WaitlistEntry;
use App\Services\WhatsApp\Messenger;
use Illuminate\Support\Collection;

/**
 * The game list as people see it in the group. The bot can't post in the group itself,
 * so it hands admins a paste-ready version after every change and serves a live page.
 */
class RosterService
{
    public function __construct(private readonly Messenger $messenger) {}

    /** One line per player in booking order; a booking for 3 shows "Ali", "Ali +1", "Ali +2". */
    public function names(Game $game, bool $full = false): Collection
    {
        return $game->bookings()
            ->where('status', 'confirmed')
            ->orderByRaw('COALESCE(decided_at, created_at)')
            ->orderBy('id')
            ->get()
            ->flatMap(function (Booking $b) use ($full) {
                $name = $full ? trim($b->player_name) : self::publicName($b->player_name);
                $rows = [$name];
                for ($i = 1; $i < $b->players; $i++) {
                    $rows[] = "{$name} +{$i}";
                }

                return $rows;
            })
            ->values();
    }

    /** Text to paste in the group, in the usual numbered-list style. */
    public function groupText(Game $game): string
    {
        if ($game->source_text && ($filled = $this->fillTemplate($game)) !== null) {
            return $filled;
        }

        $names = $this->names($game);
        $lines = [];
        for ($i = 0; $i < $game->capacity; $i++) {
            $lines[] = ($i + 1).'. '.($names[$i] ?? '');
        }
        // Over capacity (admin added extra by hand): still show everyone.
        for ($i = $game->capacity; $i < $names->count(); $i++) {
            $lines[] = ($i + 1).'. '.$names[$i];
        }

        $left = max(0, $game->capacity - $names->count());
        $local = $game->localStart();
        $header = "⚽ {$local->format('l j M')} · {$local->format('g:i A')}\n📍 {$game->venue}"
            .($game->format ? " ({$game->format})" : '')
            ."\n💰 ".(float) $game->price.' '.config('fbl.currency');

        $footer = $left > 0
            ? "\n\n{$left} spot(s) left · book here: https://wa.me/".config('fbl.bot_number')
            : "\n\nFULL ✅ · waitlist: https://wa.me/".config('fbl.bot_number');

        return $header."\n\n".implode("\n", $lines).$footer;
    }

    /**
     * Re-use the admin's own group post: same header, emojis and footer, only the numbered
     * player slots (and empty waiting-list slots) are filled. Null if the post has no numbered list.
     */
    public function fillTemplate(Game $game): ?string
    {
        $lines = preg_split('/\r\n|\n|\r/', (string) $game->source_text);
        $slot = '/^(\s*)(\d{1,2})(\s*[.)\-]\s*)(.*)$/u';

        // The main list: the first run of lines numbered 1, 2, 3… before any "waiting" heading.
        $main = [];
        $waitingAt = null;
        foreach ($lines as $i => $line) {
            if (preg_match('/wait/iu', $line)) {
                $waitingAt = $i;
                break;
            }
            if (preg_match($slot, $line, $m) && (int) $m[2] === count($main) + 1) {
                $main[] = $i;
            } elseif (preg_match($slot, $line) && $main === []) {
                continue;
            }
        }
        if (count($main) < 2) {
            return null;
        }

        $names = $this->names($game, full: true)->all();
        $render = function (string $line, ?string $name) use ($slot) {
            preg_match($slot, $line, $m);

            return $name === null || $name === '' ? rtrim($m[1].$m[2].$m[3]) : $m[1].$m[2].$m[3].$name;
        };

        foreach ($main as $k => $i) {
            $lines[$i] = $render($lines[$i], $names[$k] ?? null);
        }
        // More players than slots (admin added extra by hand): continue the numbering.
        $extra = [];
        for ($k = count($main); $k < count($names); $k++) {
            preg_match($slot, $lines[end($main)], $m);
            $extra[] = $m[1].($k + 1).$m[3].$names[$k];
        }
        if ($extra) {
            array_splice($lines, end($main) + 1, 0, $extra);
            if ($waitingAt !== null) {
                $waitingAt += count($extra);
            }
        }

        // Waiting list: fill its empty numbered slots with people waiting through the bot.
        if ($waitingAt !== null) {
            $waiting = $game->waitlist()->with('contact')->orderBy('id')->get()
                ->map(fn ($w) => trim(($w->contact?->name ?: 'Player').($w->players > 1 ? ' +'.($w->players - 1) : '')))
                ->values()->all();
            $w = 0;
            for ($i = $waitingAt + 1; $i < count($lines) && $w < count($waiting); $i++) {
                if (! preg_match($slot, $lines[$i], $m)) {
                    if (trim($lines[$i]) === '') {
                        continue;
                    }
                    break;
                }
                if (trim($m[4]) === '') {
                    $lines[$i] = $m[1].$m[2].$m[3].$waiting[$w++];
                }
            }
        }

        return implode("\n", $lines);
    }

    /** After a change, give admins the updated list to paste (only where the 24h window is open). */
    public function shareUpdated(Game $game, string $reason): void
    {
        if (! config('fbl.auto_share_list', true)) {
            return;
        }

        $text = $this->groupText($game->fresh());
        foreach ($this->messenger->adminContacts() as $admin) {
            if ($admin->windowIsOpen()) {
                $this->messenger->text($admin, "📋 Updated list ({$reason}). Copy and paste in the group:");
                $this->messenger->text($admin, $text);
            }
        }
    }

    /** "Ali Hassan" → "Ali H." so the public page doesn't expose full names. */
    public static function publicName(string $name): string
    {
        $parts = array_values(array_filter(preg_split('/\s+/u', trim($name)) ?: [], fn ($p) => $p !== ''));
        $first = $parts[0] ?? 'Player';
        $last = count($parts) > 1 ? ' '.mb_strtoupper(mb_substr(end($parts), 0, 1)).'.' : '';

        return mb_convert_case($first, MB_CASE_TITLE).$last;
    }

    /** Waitlist size changed: nudge admins at a few thresholds so they can open a second game. */
    public function maybeAlertWaitlist(Game $game): void
    {
        $count = $game->waitlist()->whereNull('notified_at')->count(); // people waiting, not seats
        if (! in_array($count, config('fbl.waitlist_alert_at', [1, 4, 8]), true)) {
            return;
        }

        $seats = (int) $game->waitlist()->whereNull('notified_at')->sum('players');
        $time = $game->localStart()->addMinutes(90)->format('H:i');
        foreach ($this->messenger->adminContacts() as $admin) {
            $this->messenger->alertAdmin($admin,
                "🔥 Game #{$game->id} ({$game->label()}) is full and {$count} people are on the waitlist ({$seats} seats). "
                ."To open a second game, send: copy {$game->id} {$time}");
        }
    }

    /**
     * A new game was published: offer it to everyone waiting for a full game on the same day.
     * Returns how many people were messaged.
     */
    public function notifyWaitlist(Game $newGame): int
    {
        $day = $newGame->localStart()->toDateString();
        $tz = config('fbl.timezone');

        $entries = WaitlistEntry::whereNull('notified_at')
            ->with(['contact', 'game'])
            ->get()
            ->filter(fn (WaitlistEntry $w) => $w->game && $w->game->starts_at->copy()->setTimezone($tz)->toDateString() === $day);

        $sent = 0;
        foreach ($entries as $entry) {
            $contact = $entry->contact;
            if (! $contact) {
                continue;
            }
            $ok = $contact->windowIsOpen()
                ? $this->messenger->text($contact,
                    "Good news ⚽ A new game just opened: {$newGame->label()}, "
                    .(float) $newGame->price.' '.config('fbl.currency')." per player.\n"
                    .'Want a spot? Reply YES and how many players.')
                : $this->messenger->template($contact, (string) config('whatsapp.templates.waitlist_opened'), [
                    $contact->name ?: 'there',
                    $newGame->label(),
                ]);

            $entry->update(['notified_at' => now()]);
            $sent += $ok ? 1 : 0;
        }

        return $sent;
    }
}
