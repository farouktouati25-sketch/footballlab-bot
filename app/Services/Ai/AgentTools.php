<?php

namespace App\Services\Ai;

use App\Models\Booking;
use App\Models\Contact;
use App\Models\Game;
use App\Services\Booking\BookingService;
use App\Services\WhatsApp\Messenger;

/** Tools the client-facing agent can call. Each returns a JSON-serialisable array. */
class AgentTools
{
    public function __construct(
        private readonly BookingService $bookings,
        private readonly Messenger $messenger,
        private readonly \App\Services\Booking\RosterService $roster,
        private readonly \App\Services\Payments\PaymentService $payments,
        private readonly \App\Services\Booking\ReminderService $reminders,
    ) {}

    /** Set by a tool when the client must get NO reply now (the admins decide first). */
    public bool $silent = false;

    public static function definitions(): array
    {
        return [
            [
                'name' => 'list_games',
                'description' => 'Upcoming published games with venue, local date/time (Dubai), price per player, spots left and game_id. Always call this before mentioning any game, date, time or price for a specific game.',
                'input_schema' => ['type' => 'object', 'properties' => new \stdClass],
            ],
            [
                'name' => 'create_booking',
                'description' => 'Hold spot(s) in a game for this client. Only call after the client chose a game and you know the player name and number of players. Returns a booking reference, amount and payment link to send.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'game_id' => ['type' => 'integer'],
                        'player_name' => ['type' => 'string', 'description' => 'Name the booking is under'],
                        'players' => ['type' => 'integer', 'description' => 'Number of players including the client', 'minimum' => 1],
                    ],
                    'required' => ['game_id', 'player_name', 'players'],
                ],
            ],
            [
                'name' => 'report_payment',
                'description' => 'Use when the client says they have paid but did NOT send a screenshot. Sends the booking to the team for approval. Ask for a screenshot first; only use this if they cannot send one.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'booking_reference' => ['type' => 'string'],
                        'note' => ['type' => 'string', 'description' => 'What the client said about the payment, e.g. paid via Ziina at 8pm under name Ali'],
                    ],
                    'required' => ['booking_reference', 'note'],
                ],
            ],
            [
                'name' => 'pay_later',
                'description' => 'The client says they pay after the game, pay cash on the spot, or are used to paying later (old clients). Never offer this yourself. Books them pending the admins\' OK and stays silent: the client gets NO reply from you now. Pass game_id + player_name + players for a new booking, or booking_reference if they already have one.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'game_id' => ['type' => 'integer'],
                        'player_name' => ['type' => 'string'],
                        'players' => ['type' => 'integer', 'minimum' => 1],
                        'booking_reference' => ['type' => 'string'],
                        'what_they_said' => ['type' => 'string', 'description' => 'Their words, short, e.g. "I always pay after the game"'],
                    ],
                ],
            ],
            [
                'name' => 'join_waitlist',
                'description' => 'Put the client on the waiting list for a FULL game (spots_left = 0). No payment. When a place frees up (and the team approves) or a second game opens, the client is messaged automatically.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'game_id' => ['type' => 'integer'],
                        'players' => ['type' => 'integer', 'minimum' => 1],
                    ],
                    'required' => ['game_id', 'players'],
                ],
            ],
            [
                'name' => 'remember_availability',
                'description' => 'The client wants to play on a day that has no published game yet (or no games are published at all). Saves the date; when the game for that day is posted they get a message automatically. Resolve phrases like "next Friday" to a date using the current Dubai date.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                        'players' => ['type' => 'integer', 'minimum' => 1],
                    ],
                    'required' => ['date'],
                ],
            ],
            [
                'name' => 'save_player_profile',
                'description' => 'Save the name the client gives and the weekdays they are usually free to play (e.g. "I\'m Farouk, free Sundays"). Used to invite them when a game on those days is posted. Call it whenever they tell you their name or usual days, even if no game is published.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'days' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']]],
                    ],
                ],
            ],
            [
                'name' => 'my_bookings',
                'description' => "This client's bookings for upcoming games with status.",
                'input_schema' => ['type' => 'object', 'properties' => new \stdClass],
            ],
            [
                'name' => 'cancel_booking',
                'description' => 'Cancel an UNPAID booking (status pending_payment). Paid or confirmed bookings must be handed to a human.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['booking_reference' => ['type' => 'string']],
                    'required' => ['booking_reference'],
                ],
            ],
            [
                'name' => 'handoff_to_human',
                'description' => 'Silently pass the chat to the team (the client gets no reply from you; the admins answer): refunds, complaints, private pitch bookings, tournaments, corporate events, groups larger than the booking limit, anything you cannot answer from the facts, or when the client asks for a person. The bot stops replying in this chat afterwards.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['reason' => ['type' => 'string']],
                    'required' => ['reason'],
                ],
            ],
        ];
    }

    public function run(Contact $contact, string $name, array $input): array
    {
        return match ($name) {
            'list_games' => $this->listGames(),
            'create_booking' => $this->createBooking($contact, $input),
            'report_payment' => $this->reportPayment($contact, $input),
            'pay_later', 'pay_cash_on_spot' => $this->payLater($contact, $input),
            'my_bookings' => $this->myBookings($contact),
            'save_player_profile' => $this->reminders->saveProfile($contact, isset($input['name']) ? (string) $input['name'] : null, (array) ($input['days'] ?? [])),
            'remember_availability' => $this->reminders->remember($contact, (string) ($input['date'] ?? ''), (int) ($input['players'] ?? 1)),
            'join_waitlist' => $this->joinWaitlist($contact, $input),
            'cancel_booking' => $this->cancelBooking($contact, $input),
            'handoff_to_human' => $this->handoff($contact, $input),
            default => ['error' => 'Unknown tool '.$name],
        };
    }

    private function listGames(): array
    {
        $games = Game::upcoming()->limit(15)->get();
        if ($games->isEmpty()) {
            return [
                'games' => [],
                'note' => 'No games are published right now. Ask which day they want to play; when they name a day, call remember_availability so they get a message when that game is posted. You can also share the community group link.',
            ];
        }

        return ['games' => $games->map(fn (Game $g) => [
            'game_id' => $g->id,
            'when' => $g->localStart()->format('l j F, g:i A'),
            'venue' => $g->venue,
            'format' => $g->format,
            'price_per_player' => (float) $g->price.' '.config('fbl.currency'),
            'spots_left' => $g->spotsLeft(),
            'full' => $g->spotsLeft() === 0,
            'notes' => $g->notes,
        ])->values()->all()];
    }

    private function createBooking(Contact $contact, array $input): array
    {
        $result = $this->bookings->create(
            $contact,
            (int) ($input['game_id'] ?? 0),
            (string) ($input['player_name'] ?? ''),
            (int) ($input['players'] ?? 1),
        );

        if (! $result['ok']) {
            return ['error' => $result['error']];
        }

        $booking = $result['booking'];
        $link = $this->payments->linkFor($booking);
        $online = (bool) $booking->fresh()->payment_intent_id;

        return [
            'booking_reference' => $booking->reference,
            'game' => $booking->game->label(),
            'players' => $booking->players,
            'amount' => $booking->amountLabel(),
            'payment_link' => $link,
            'hold_minutes' => (int) config('fbl.payment_timeout_minutes', 120),
            'instructions' => ! $link && config('fbl.payments.mode') === 'admin_link'
                ? 'A personal payment link is being prepared. Tell them in one friendly line: booked, the reference and amount, payment link coming in a moment, then pay and send the screenshot here. Never say you will check with the team. Do not invent a link.'
                : ($link
                ? ($online
                    ? 'Send the amount and this personal payment link. Payment is detected automatically, so no screenshot is needed; the client gets a message here once paid. Spot is held for hold_minutes.'
                    : 'Send the amount and payment link. Ask the client to send the payment screenshot here after paying. Spot is held for hold_minutes.')
                : 'No payment link is configured: hand off to a human so the team can send payment details.'),
        ];
    }

    private function reportPayment(Contact $contact, array $input): array
    {
        $booking = $this->ownBooking($contact, (string) ($input['booking_reference'] ?? ''));
        if (! $booking) {
            $booking = $contact->bookings()->where('status', 'pending_payment')
                ->where('created_at', '>', now()->subMinutes((int) config('fbl.payment_timeout_minutes', 120)))
                ->latest()->first();
        }
        if (! $booking) {
            return ['error' => 'No unpaid booking found for this client. Use my_bookings.'];
        }
        if (! $this->bookings->reportPayment($booking, null, (string) ($input['note'] ?? ''))) {
            return ['error' => 'Booking '.$booking->reference.' can no longer take a payment report (status '.$booking->status.', or the hold expired and the game filled up). Hand off to a human so the team can check the payment.'];
        }

        $this->silent = true; // admins confirm; the client hears back only with the confirmation

        return ['ok' => true, 'booking_reference' => $booking->reference, 'status' => 'awaiting_approval',
            'instructions' => 'Done. Do not reply to the client.'];
    }

    private function payLater(Contact $contact, array $input): array
    {
        $note = Booking::PAY_LATER_NOTE.(! empty($input['what_they_said']) ? ': '.mb_substr((string) $input['what_they_said'], 0, 150) : '');

        $booking = $this->ownBooking($contact, (string) ($input['booking_reference'] ?? ''))
            ?? $contact->bookings()->where('status', 'pending_payment')
                ->when(! empty($input['game_id']), fn ($q) => $q->where('game_id', (int) $input['game_id']))
                ->latest()->first();

        if (! $booking) {
            if (empty($input['game_id']) || empty($input['player_name'])) {
                return ['error' => 'Need game_id and player_name (ask for the name if you don\'t know it).'];
            }
            // No payment link: the admins decide whether this client can pay later.
            $result = $this->bookings->create($contact, (int) $input['game_id'], (string) $input['player_name'], (int) ($input['players'] ?? 1));
            if (! $result['ok']) {
                return ['error' => $result['error']];
            }
            $booking = $result['booking'];
        }

        if (! $this->bookings->reportPayment($booking, null, $note)) {
            return ['error' => 'Could not send this to the team (booking '.$booking->status.' or game full). Offer other games or the waiting list.'];
        }
        $this->silent = true;

        return ['ok' => true, 'instructions' => 'Done. Do not reply to the client.'];
    }

    private function joinWaitlist(Contact $contact, array $input): array
    {
        $game = Game::upcoming()->whereKey((int) ($input['game_id'] ?? 0))->first();
        if (! $game) {
            return ['error' => 'Game not found or no longer open.'];
        }
        if ($game->spotsLeft() > 0) {
            return ['error' => 'This game still has spots: book it with create_booking instead.'];
        }

        $players = max(1, min((int) config('fbl.max_players_per_booking', 6), (int) ($input['players'] ?? 1)));
        \App\Models\WaitlistEntry::updateOrCreate(
            ['contact_id' => $contact->id, 'game_id' => $game->id],
            ['players' => $players, 'notified_at' => null],
        );
        $this->roster->maybeAlertWaitlist($game);
        $this->roster->shareUpdated($game, ($contact->name ?: 'Someone').' joined the waiting list');

        return ['ok' => true, 'instructions' => 'Tell the client (friendly, e.g. "Done bro 👊") they are on the waiting list, no payment needed now, and you will message them here as soon as a place opens.'];
    }

    private function myBookings(Contact $contact): array
    {
        $rows = $contact->bookings()
            ->with('game')
            ->whereIn('status', [...Booking::OPEN_STATUSES, 'rejected'])
            ->whereHas('game', fn ($q) => $q->where('starts_at', '>', now()))
            ->latest()
            ->limit(10)
            ->get();

        return ['bookings' => $rows->map(fn (Booking $b) => [
            'booking_reference' => $b->reference,
            'game' => $b->game->label(),
            'players' => $b->players,
            'amount' => $b->amountLabel(),
            'status' => $b->status,
            'payment_link' => $b->status === 'pending_payment' ? $this->payments->linkFor($b) : null,
        ])->values()->all()];
    }

    private function cancelBooking(Contact $contact, array $input): array
    {
        $booking = $this->ownBooking($contact, (string) ($input['booking_reference'] ?? ''));
        if (! $booking) {
            return ['error' => 'Booking not found for this client.'];
        }
        if (! $this->bookings->cancelByClient($booking)) {
            return ['error' => 'Only unpaid bookings can be cancelled here. This one is '.$booking->status.'; hand off to a human.'];
        }

        $this->roster->offerFreedSpots($booking->game);

        return ['ok' => true, 'booking_reference' => $booking->reference, 'status' => 'cancelled'];
    }

    private function handoff(Contact $contact, array $input): array
    {
        $reason = (string) ($input['reason'] ?? 'Client needs a person');
        $contact->update(['bot_paused_until' => now()->addHours((int) config('fbl.human_takeover_hours', 12))]);

        $last = (string) $contact->messages()->where('direction', 'in')->latest('id')->value('body');
        foreach ($this->messenger->adminContacts() as $admin) {
            $this->messenger->alertAdmin($admin, "🙋 {$contact->displayName()} (+{$contact->wa_id}): {$reason}\n"
                .($last !== '' ? "💬 \"".mb_substr($last, 0, 300)."\"\n" : '')
                ."I didn't reply. Answer them from the WhatsApp app (bot is paused in that chat).");
        }
        $this->silent = true;

        return ['ok' => true, 'instructions' => 'Done. Do not reply to the client.'];
    }

    private function ownBooking(Contact $contact, string $reference): ?Booking
    {
        if (trim($reference) === '') {
            return null;
        }
        $booking = Booking::findByReference($reference);

        return $booking && $booking->contact_id === $contact->id ? $booking : null;
    }
}
