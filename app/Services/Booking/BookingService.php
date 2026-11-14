<?php

namespace App\Services\Booking;

use App\Models\Booking;
use App\Models\Contact;
use App\Models\Game;
use Illuminate\Support\Facades\DB;

/** All booking state changes live here, so the AI and the admin buttons share one set of rules. */
class BookingService
{
    public function __construct(private readonly ApprovalNotifier $notifier) {}

    /** @return array{ok:bool, error?:string, booking?:Booking} */
    public function create(Contact $contact, int $gameId, string $playerName, int $players): array
    {
        $max = (int) config('fbl.max_players_per_booking', 6);
        if (Game::whereKey($gameId)->value('format') === 'tournament') {
            $max = (int) config('fbl.max_players_per_team', 12); // a whole team signs up together
        }
        if ($players < 1 || $players > $max) {
            return ['ok' => false, 'error' => "Players must be between 1 and {$max}. For bigger groups hand off to a human."];
        }
        $playerName = trim($playerName);
        if ($playerName === '') {
            return ['ok' => false, 'error' => 'Ask the client for their name first.'];
        }

        return DB::transaction(function () use ($contact, $gameId, $playerName, $players) {
            /** @var Game|null $game */
            $game = Game::whereKey($gameId)->lockForUpdate()->first();
            if (! $game || $game->status !== 'published' || $game->starts_at->isPast()) {
                return ['ok' => false, 'error' => 'That game is not open for booking. Call list_games again.'];
            }

            if ($contact->bookings()->where('game_id', $game->id)->whereIn('status', ['awaiting_approval', 'confirmed'])->exists()) {
                return ['ok' => false, 'error' => 'The client already has a paid or confirmed booking for this game. Use my_bookings.'];
            }

            // Their own valid unpaid hold on this game is replaced, so its spots count as available to them.
            $cutoff = now()->subMinutes((int) config('fbl.payment_timeout_minutes', 120));
            $ownHold = $contact->bookings()->where('game_id', $game->id)->where('status', 'pending_payment');
            $ownHeldPlayers = (int) (clone $ownHold)->where('created_at', '>', $cutoff)->sum('players');

            $left = $game->spotsLeft() + $ownHeldPlayers;
            if ($left < $players) {
                // Fail before touching the existing hold, so the client keeps what they had.
                return ['ok' => false, 'error' => $left === 0
                    ? 'Game is full. Offer another game, or the waitlist (join_waitlist).'
                    : "Only {$left} spot(s) left in this game. Their current hold is unchanged."];
            }

            $ownHold->update(['status' => 'cancelled']);

            $booking = Booking::create([
                'contact_id' => $contact->id,
                'game_id' => $game->id,
                'player_name' => mb_substr($playerName, 0, 120),
                'players' => $players,
                'amount' => (float) $game->price * $players,
                'status' => 'pending_payment',
            ]);

            // Booked now, so off the waiting list for this game.
            \App\Models\WaitlistEntry::where('contact_id', $contact->id)->where('game_id', $game->id)->delete();

            return ['ok' => true, 'booking' => $booking->load('game')];
        });
    }

    /**
     * Client says they paid (with or without a screenshot). Moves to awaiting_approval and pings admins.
     *
     * @param  array{id:string,mime:string}|null  $proof
     */
    public function reportPayment(Booking $booking, ?array $proof, ?string $note = null): bool
    {
        $updated = DB::transaction(function () use ($booking, $proof, $note) {
            $fresh = Booking::whereKey($booking->id)->lockForUpdate()->first();
            if (! $fresh || ! in_array($fresh->status, ['pending_payment', 'awaiting_approval'], true)) {
                return false;
            }

            // Hold already timed out (housekeeping hasn't swept it yet): its spots may be gone.
            $cutoff = now()->subMinutes((int) config('fbl.payment_timeout_minutes', 120));
            if ($fresh->status === 'pending_payment' && $fresh->created_at->lte($cutoff)) {
                $game = Game::whereKey($fresh->game_id)->lockForUpdate()->first();
                if (! $game || $game->spotsLeft($fresh->id) < $fresh->players) {
                    return false;
                }
            }

            $fresh->update(array_filter([
                'status' => 'awaiting_approval',
                'payment_reported_at' => now(),
                'payment_proof_media_id' => $proof['id'] ?? $fresh->payment_proof_media_id,
                'payment_proof_mime' => $proof['mime'] ?? $fresh->payment_proof_mime,
                'payment_note' => $note ? mb_substr($note, 0, 250) : $fresh->payment_note,
            ], fn ($v) => $v !== null));

            return true;
        });

        if ($updated) {
            $this->notifier->requestApproval($booking->fresh(['game', 'contact']));
        }

        return $updated;
    }

    /**
     * Admin tapped Confirm or Reject. The first admin to tap wins.
     *
     * @return array{result:'done'|'already'|'missing'|'full', booking?:Booking, game?:Game}
     */
    public function decide(int $bookingId, Contact $admin, bool $approve): array
    {
        $outcome = DB::transaction(function () use ($bookingId, $admin, $approve) {
            $booking = Booking::whereKey($bookingId)->lockForUpdate()->first();
            if (! $booking) {
                return ['result' => 'missing'];
            }
            if (! in_array($booking->status, ['awaiting_approval', 'pending_payment'], true)) {
                return ['result' => 'already', 'booking' => $booking];
            }

            if ($approve) {
                $game = Game::whereKey($booking->game_id)->lockForUpdate()->first();
                $confirmedOthers = (int) $game->bookings()->where('status', 'confirmed')->where('id', '!=', $booking->id)->sum('players');
                if ($confirmedOthers + $booking->players > $game->capacity) {
                    return ['result' => 'full', 'booking' => $booking, 'game' => $game];
                }
            }

            $booking->update([
                'status' => $approve ? 'confirmed' : 'rejected',
                'decided_by' => $admin->wa_id,
                'decided_at' => now(),
            ]);

            return ['result' => 'done', 'booking' => $booking];
        });

        if ($outcome['result'] === 'done') {
            $booking = $outcome['booking']->fresh(['game', 'contact']);
            $this->notifier->announceDecision($booking, $admin);
            $outcome['booking'] = $booking;
        }

        return $outcome;
    }

    /** Admin adds a player who joined through the group (no WhatsApp chat with the bot). */
    public function addManual(int $gameId, string $playerName, int $players, Contact $admin): array
    {
        return DB::transaction(function () use ($gameId, $playerName, $players, $admin) {
            $game = Game::whereKey($gameId)->lockForUpdate()->first();
            if (! $game || $game->status === 'cancelled') {
                return ['ok' => false, 'error' => "No open game #{$gameId}."];
            }

            $overBy = $players - $game->spotsLeft();
            $booking = Booking::create([
                'contact_id' => null,
                'source' => 'manual',
                'game_id' => $game->id,
                'player_name' => mb_substr(trim($playerName), 0, 120),
                'players' => max(1, $players),
                'amount' => (float) $game->price * max(1, $players),
                'status' => 'confirmed',
                'decided_by' => $admin->wa_id,
                'decided_at' => now(),
            ]);

            // Admins can overrule capacity (it's their pitch), but they get warned.
            return ['ok' => true, 'booking' => $booking, 'game' => $game, 'over_capacity' => max(0, $overBy)];
        });
    }

    /**
     * Make the game's confirmed list match the names in the admin's group post.
     * Adds names that aren't on the list yet; cancels earlier post-imported names the post no longer has.
     * Bot bookings are never cancelled by this. Returns [added, removed].
     *
     * @param  string[]  $names  rows as written in the post ("Ali", "Ali +1", ...)
     */
    public function syncPostPlayers(Game $game, array $names, Contact $admin): array
    {
        return DB::transaction(function () use ($game, $names, $admin) {
            $norm = fn (string $n) => mb_strtolower(preg_replace('/\s+/u', ' ', trim($n)));

            // Current rows, expanded the same way the list shows them ("Ali", "Ali +1").
            $current = [];
            foreach ($game->bookings()->where('status', 'confirmed')->orderBy('id')->get() as $b) {
                $current[] = ['key' => $norm($b->player_name), 'booking' => $b];
                for ($i = 1; $i < $b->players; $i++) {
                    $current[] = ['key' => $norm($b->player_name.' +'.$i), 'booking' => $b];
                }
            }

            $wanted = array_map($norm, $names);
            $remainingCurrent = $current;
            $added = 0;

            foreach ($names as $i => $name) {
                $key = $wanted[$i];
                $match = null;
                foreach ($remainingCurrent as $idx => $row) {
                    if ($row['key'] === $key) {
                        $match = $idx;
                        break;
                    }
                }
                if ($match !== null) {
                    unset($remainingCurrent[$match]);

                    continue;
                }
                Booking::create([
                    'contact_id' => null,
                    'source' => 'post',
                    'game_id' => $game->id,
                    'player_name' => mb_substr(trim($name), 0, 120),
                    'players' => 1,
                    'amount' => (float) $game->price,
                    'status' => 'confirmed',
                    'decided_by' => $admin->wa_id,
                    'decided_at' => now(),
                ]);
                $added++;
            }

            // Names imported from an earlier post that the admin has since removed.
            $removed = 0;
            foreach ($remainingCurrent as $row) {
                if ($row['booking']->source === 'post' && $row['booking']->status === 'confirmed') {
                    $row['booking']->update(['status' => 'cancelled']);
                    $removed++;
                }
            }

            return [$added, $removed];
        });
    }

    /** Admin removes a player by name (latest matching confirmed booking) or by reference. */
    public function removeManual(int $gameId, string $nameOrRef): ?Booking
    {
        $needle = trim($nameOrRef);
        $query = Booking::where('game_id', $gameId)->where('status', 'confirmed');

        $booking = preg_match('/^fbl-[a-z0-9]{5}$/i', $needle)
            ? (clone $query)->where('reference', mb_strtoupper($needle))->first()
            : (clone $query)->whereRaw('LOWER(player_name) = ?', [mb_strtolower($needle)])->latest('id')->first()
                ?? (clone $query)->whereRaw('LOWER(player_name) LIKE ?', [mb_strtolower($needle).'%'])->latest('id')->first();

        if ($booking) {
            $booking->update(['status' => 'cancelled']);
        }

        return $booking;
    }

    public function cancelByClient(Booking $booking): bool
    {
        if ($booking->status !== 'pending_payment') {
            return false;
        }

        return (bool) $booking->update(['status' => 'cancelled']);
    }
}
