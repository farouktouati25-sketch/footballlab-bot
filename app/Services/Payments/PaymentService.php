<?php

namespace App\Services\Payments;

use App\Models\Booking;
use App\Models\Contact;
use App\Services\Booking\BookingService;
use App\Services\WhatsApp\Messenger;
use Illuminate\Support\Facades\Log;

/**
 * Turns a held booking into a pay link with the exact amount, and handles "paid" events.
 * Falls back to the game's fixed link (or the default link) when Ziina isn't configured.
 */
class PaymentService
{
    public function __construct(
        private readonly ZiinaClient $ziina,
        private readonly BookingService $bookings,
        private readonly Messenger $messenger,
    ) {}

    /** Returns the URL the client should pay at (creates a Ziina intent once per booking). */
    public function linkFor(Booking $booking): ?string
    {
        if ($booking->payment_url) {
            return $booking->payment_url;
        }

        // Spot is reserved now; the payment link goes out the day before the game.
        $due = $this->linkDueAt($booking);
        if ($due->isFuture()) {
            if (! $booking->link_due_at) {
                $booking->update(['link_due_at' => $due]);
            }

            return null;
        }

        $mode = config('fbl.payments.mode', 'admin_link');

        if ($mode === 'admin_link') {
            $this->requestLinkFromAdmins($booking);

            return null; // the client is told a team member will send it
        }

        if ($mode !== 'ziina' || ! $this->ziina->enabled()) {
            return $booking->game->paymentLink();
        }

        $fee = (float) config('fbl.payments.fee_per_booking', 0);
        $amountFils = (int) round(((float) $booking->amount + $fee) * 100);
        $expiresAt = $booking->created_at->copy()->addMinutes((int) config('fbl.payment_timeout_minutes', 120));

        $intent = $this->ziina->createIntent(
            $amountFils,
            "FootballLab {$booking->reference} · {$booking->players} player(s) · {$booking->game->label()}",
            $expiresAt->getTimestampMs(),
            config('fbl.payments.thank_you_url') ?: null,
        );

        if (! $intent) {
            // Ziina down: don't block the booking, use the fixed link if there is one.
            return $booking->game->paymentLink();
        }

        $booking->update(['payment_intent_id' => $intent['id'], 'payment_url' => $intent['url']]);

        return $intent['url'];
    }

    /** Day before the game at payment_link_day_before_at (Dubai time). */
    public function linkDueAt(Booking $booking): \Carbon\Carbon
    {
        $at = (string) config('fbl.payment_link_day_before_at', '12:00');
        if ($at === '') {
            return now()->subSecond(); // deferral switched off: link right away
        }
        $tz = config('fbl.timezone');
        $day = $booking->game->starts_at->copy()->setTimezone($tz)->subDay()->toDateString();

        return \Carbon\Carbon::createFromFormat('!Y-m-d H:i', $day.' '.$at, $tz)->utc();
    }

    /** Reserved bookings whose link day has come: request (admin_link) or send the link. */
    public function releaseDueLinks(): int
    {
        $count = 0;
        Booking::where('status', 'pending_payment')->whereNull('payment_url')->whereNull('link_requested_at')
            ->whereNotNull('link_due_at')->where('link_due_at', '<=', now())
            ->with(['game', 'contact'])
            ->get()
            ->each(function (Booking $b) use (&$count) {
                $link = $this->linkFor($b);
                if ($link && ! $b->fresh()->payment_url) {
                    $b->forceFill(['payment_url' => $link, 'created_at' => now()])->save(); // hold timer starts now
                }
                if ($link && $b->contact) {
                    $minutes = (int) config('fbl.payment_timeout_minutes', 120);
                    $this->messenger->updateClient($b->contact, $b->reference,
                        "⚽ {$b->game->label()} is tomorrow! Here's your payment link for {$b->reference} ({$b->amountLabel()}):\n{$link}\n\n"
                        ."Please pay within {$minutes} minutes to keep your spot, then send the screenshot here ✅");
                }
                $count++;
            });

        return $count;
    }

    /** Ask admins to reply with a payment link for this booking (once). */
    public function requestLinkFromAdmins(Booking $booking): void
    {
        if ($booking->link_requested_at) {
            return;
        }
        $booking->update(['link_requested_at' => now()]);
        $b = $booking->fresh(['game', 'contact']);

        $text = "💳 {$b->reference} needs a payment link\n"
            ."👤 {$b->player_name}".($b->contact ? ' · +'.$b->contact->wa_id : '')."\n"
            ."⚽ {$b->game->label()}\n"
            ."👥 {$b->players} player(s) · 💰 {$b->amountLabel()}\n\n"
            ."Reply to THIS message with the link, or send: {$b->reference} https://…";

        foreach ($this->messenger->adminContacts() as $admin) {
            $this->messenger->alertAdmin($admin, $text, ['booking_id' => $b->id, 'kind' => 'link_request']);
        }
    }

    /** An admin sent the link: pass it to the client and restart the hold from now. */
    public function attachLink(Booking $booking, string $url, Contact $admin): string
    {
        if ($booking->status !== 'pending_payment') {
            return "{$booking->reference} is {$booking->status}, link not sent.";
        }
        if ($booking->payment_url) {
            return "{$booking->reference} already got a link from ".($booking->link_sent_by ? '+'.$booking->link_sent_by : 'someone').'. Nothing sent.';
        }

        // created_at doubles as "hold started": the client gets the full payment window from now.
        $booking->forceFill(['payment_url' => $url, 'link_sent_by' => $admin->wa_id, 'created_at' => now()])->save();
        $b = $booking->fresh(['game', 'contact']);
        $minutes = (int) config('fbl.payment_timeout_minutes', 120);

        $sent = $b->contact && $this->messenger->updateClient($b->contact, $b->reference,
            "💳 Here's your payment link for booking {$b->reference} ({$b->amountLabel()}):\n{$url}\n\n"
            ."Your spot is held for {$minutes} minutes. After paying, send the screenshot here ✅");

        foreach ($this->messenger->adminContacts() as $other) {
            if ($other->wa_id !== $admin->wa_id) {
                $this->messenger->alertAdmin($other, "{$b->reference}: payment link sent by {$admin->adminName()} ✅");
            }
        }

        return $sent
            ? "✅ Link sent to {$b->player_name} for {$b->reference}. Hold restarted: {$minutes} min."
            : "⚠️ Couldn't deliver the link to {$b->player_name} (chat closed?). Send it to +".($b->contact->wa_id ?? '?').' yourself.';
    }

    /** Ziina says this intent is completed: mark the booking paid (verified) and notify / confirm. */
    public function markPaidByIntent(string $intentId): ?Booking
    {
        $booking = Booking::where('payment_intent_id', $intentId)->first();
        if (! $booking || $booking->payment_verified) {
            return $booking;
        }

        $booking->update(['payment_verified' => true, 'paid_at' => now()]);

        $handled = false;
        if (config('fbl.payments.auto_confirm_verified')) {
            // Money is verified by Ziina, so no human tap is needed.
            $system = new Contact(['wa_id' => 'ziina', 'name' => 'Ziina (auto)']);
            $handled = $this->bookings->decide($booking->id, $system, true)['result'] === 'done';
        }
        if (! $handled && $this->bookings->reportPayment($booking->fresh(), null, 'Paid online via Ziina ✅ verified')) {
            $handled = true;
            $b = $booking->fresh(['contact']);
            if ($b->contact) {
                $this->messenger->updateClient($b->contact, $b->reference,
                    "Payment received ✅ Booking {$b->reference}, your confirmation comes here 👊");
            }
        }
        if (! $handled) {
            // Paid, but the hold expired or the game filled up meanwhile: a human must sort it out.
            $b = $booking->fresh(['game']);
            foreach ($this->messenger->adminContacts() as $admin) {
                $this->messenger->alertAdmin($admin, "⚠️ {$b->reference} PAID via Ziina ({$b->amountLabel()}) but the booking is {$b->status}"
                    ." and couldn't be placed in {$b->game->label()}. Add them with \"add {$b->game_id} {$b->player_name}\" or refund.");
            }
        }

        Log::info('Booking paid via Ziina', ['booking' => $booking->reference]);

        return $booking->fresh();
    }

    /** Safety net for missed webhooks: ask Ziina directly about recent unpaid bookings. */
    public function reconcile(): int
    {
        if (! $this->ziina->enabled()) {
            return 0;
        }

        $count = 0;
        Booking::whereNotNull('payment_intent_id')
            ->where('payment_verified', false)
            ->whereIn('status', ['pending_payment', 'awaiting_approval', 'expired'])
            ->where('created_at', '>', now()->subHours(6))
            ->limit(50)
            ->get()
            ->each(function (Booking $b) use (&$count) {
                if ($this->ziina->status($b->payment_intent_id) === 'completed') {
                    $this->markPaidByIntent($b->payment_intent_id);
                    $count++;
                }
            });

        return $count;
    }
}
