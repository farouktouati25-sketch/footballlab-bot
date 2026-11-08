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
    private const SLOT = '/^(\s*)(\d{1,2})(\s*[.)\-]\s*)(.*)$/u';

    /**
     * Numbered player lists in a post (before any "waiting" heading): each list restarts at 1.
     *
     * @return array{blocks: array<int, int[]>, waitingAt: ?int, lines: string[]}  blocks = line indexes per list
     */
    public static function listBlocks(string $text): array
    {
        $lines = preg_split('/\r\n|\n|\r/', $text);
        $blocks = [];
        $waitingAt = null;
        foreach ($lines as $i => $line) {
            if (preg_match('/wait/iu', $line)) {
                $waitingAt = $i;
                break;
            }
            if (! preg_match(self::SLOT, $line, $m)) {
                continue;
            }
            $n = (int) $m[2];
            if ($n === 1) {
                $blocks[] = [$i];
            } elseif ($blocks && $n === count($blocks[array_key_last($blocks)]) + 1) {
                $blocks[array_key_last($blocks)][] = $i;
            }
        }

        return ['blocks' => array_values(array_filter($blocks, fn ($b) => count($b) >= 2)), 'waitingAt' => $waitingAt, 'lines' => $lines];
    }

    /** Names written in each list of a post (lower-cased, empty slots skipped). */
    public static function blockNames(string $text): array
    {
        $parsed = self::listBlocks($text);

        return array_map(function ($block) use ($parsed) {
            $names = [];
            foreach ($block as $i) {
                preg_match(self::SLOT, $parsed['lines'][$i], $m);
                $n = mb_strtolower(trim($m[4] ?? ''));
                if ($n !== '') {
                    $names[] = $n;
                }
            }

            return $names;
        }, $parsed['blocks']);
    }

    /**
     * The admin's own post with the current names. A post with several games (Game 1 / Game 2 lists)
     * gets every list refreshed from its own game, so it can be pasted back into the group as is.
     */
    public function fillTemplate(Game $game): ?string
    {
        $parsed = self::listBlocks((string) $game->source_text);
        $blocks = $parsed['blocks'];
        $lines = $parsed['lines'];
        $waitingAt = $parsed['waitingAt'];
        if (! $blocks) {
            return null;
        }
        $slot = self::SLOT;

        $owners = [];
        if ($game->list_group) {
            foreach (Game::where('list_group', $game->list_group)->where('status', '!=', 'cancelled')->get() as $g) {
                $owners[(int) $g->list_part] = $g;
            }
        }
        $owners[(int) $game->list_part] = $game;

        $render = function (string $line, ?string $name) use ($slot) {
            preg_match($slot, $line, $m);

            return $name === null || $name === '' ? rtrim($m[1].$m[2].$m[3]) : $m[1].$m[2].rtrim($m[3]).' '.$name;
        };

        // Last list first, so lines added to one list don't shift the ones above it.
        for ($b = count($blocks) - 1; $b >= 0; $b--) {
            $owner = $owners[$b] ?? null;
            if (! $owner) {
                continue;
            }
            $main = $blocks[$b];
            $names = $this->names($owner, full: true)->all();
            foreach ($main as $k => $i) {
                $lines[$i] = $render($lines[$i], $names[$k] ?? null);
            }
            // More players than slots (admin added extra by hand): continue the numbering.
            $extra = [];
            for ($k = count($main); $k < count($names); $k++) {
                preg_match($slot, $lines[end($main)], $m);
                $extra[] = $m[1].($k + 1).rtrim($m[3]).' '.$names[$k];
            }
            if ($extra) {
                array_splice($lines, end($main) + 1, 0, $extra);
                if ($waitingAt !== null && $waitingAt > end($main)) {
                    $waitingAt += count($extra);
                }
            }
        }

        // Waiting list: fill its empty numbered slots with people waiting through the bot.
        if ($waitingAt !== null) {
            $waiting = \App\Models\WaitlistEntry::whereIn('game_id', array_map(fn (Game $g) => $g->id, $owners))
                ->with('contact')->orderBy('id')->get()
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
                    $lines[$i] = $m[1].$m[2].rtrim($m[3]).' '.$waiting[$w++];
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

    /**
     * A spot is free in a game with people waiting: ask the admins whether to offer it to the next
     * person. One question per free spot; an offer the client ignores for 2 hours stops counting.
     * Returns how many admin questions were sent.
     */
    public function offerFreedSpots(Game $game): int
    {
        $game = $game->fresh();
        if (! $game || $game->status !== 'published' || $game->starts_at->isPast()) {
            return 0;
        }
        $free = $game->spotsLeft();
        if ($free <= 0) {
            return 0;
        }

        $open = fn () => $game->waitlist()->whereNull('notified_at')->whereNull('skipped_at');
        $outstanding = $open()->whereNotNull('admin_asked_at')
            ->where(fn ($q) => $q->whereNull('offer_sent_at')->orWhere('offer_sent_at', '>', now()->subHours(2)))
            ->count();

        $asked = 0;
        while ($outstanding + $asked < $free) {
            $next = $open()->whereNull('admin_asked_at')->with('contact')->orderBy('id')->first();
            if (! $next) {
                break;
            }
            $next->update(['admin_asked_at' => now()]);
            $who = ($next->contact?->name ?: 'Player').($next->players > 1 ? " (+".($next->players - 1).')' : '')
                .($next->contact ? ' · +'.$next->contact->wa_id : '');
            $text = "🪑 A spot opened in #{$game->id} ({$game->label()}): {$free} free now.\n"
                ."Next on the waiting list: {$who}\n\nSend them the offer?";

            foreach ($this->messenger->adminContacts() as $admin) {
                $admin->windowIsOpen()
                    ? $this->messenger->buttons($admin, $text, ['wl_send:'.$next->id => '📩 Send offer', 'wl_skip:'.$next->id => '⏭ Skip'])
                    : $this->messenger->alertAdmin($admin, $text." Reply: offer {$next->id} (or skip {$next->id})");
            }
            $asked++;
        }

        return $asked;
    }

    /** Admin said yes: tell the waiting client a place opened. */
    public function sendWaitlistOffer(WaitlistEntry $entry, \App\Models\Contact $admin): string
    {
        $entry->loadMissing(['contact', 'game']);
        if ($entry->offer_sent_at) {
            return 'Already offered to '.($entry->contact?->name ?: 'them').' ✅';
        }
        if (! $entry->contact || ! $entry->game) {
            return 'That waiting list entry no longer exists.';
        }
        $game = $entry->game;
        $client = $entry->contact;
        $name = $client->name ? strtok(trim($client->name), ' ') : 'bro';

        $ok = $client->windowIsOpen()
            ? $this->messenger->text($client,
                "Good news {$name} ⚽ A place just opened in {$game->label()}!\n"
                .'Are you still in? Reply YES and I\'ll send you the payment link 👊',
                'bot', ['kind' => 'waitlist_offer', 'game_id' => $game->id])
            : $this->messenger->template($client, (string) config('whatsapp.templates.waitlist_opened'), [$name, $game->label()],
                [], ['kind' => 'waitlist_offer', 'game_id' => $game->id]);

        $entry->update(['offer_sent_at' => now()]);
        foreach ($this->messenger->adminContacts() as $other) {
            if ($other->wa_id !== $admin->wa_id) {
                $this->messenger->alertAdmin($other, "📩 Waiting-list offer for #{$game->id} sent to {$client->name} by {$admin->adminName()}");
            }
        }

        return $ok
            ? "📩 Offer sent to {$client->name}. When they say yes, the bot books them and asks for the payment link as usual."
            : "⚠️ Couldn't reach {$client->name} (chat closed and template not approved?). Message +{$client->wa_id} yourself.";
    }
}
