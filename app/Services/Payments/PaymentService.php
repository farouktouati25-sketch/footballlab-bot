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

        if (! $this->ziina->enabled()) {
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
                    "Payment received ✅ for booking {$b->reference}. The team will confirm your spot here shortly.");
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
