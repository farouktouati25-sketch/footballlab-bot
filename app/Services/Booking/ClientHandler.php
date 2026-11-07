<?php

namespace App\Services\Booking;

use App\Models\Contact;
use App\Models\Message;
use App\Services\Ai\BookingAgent;
use App\Services\WhatsApp\InboundMessage;
use App\Services\WhatsApp\Messenger;
use App\Services\WhatsApp\WhatsAppClient;
use Illuminate\Support\Facades\Log;

class ClientHandler
{
    public function __construct(
        private readonly BookingAgent $agent,
        private readonly BookingService $bookings,
        private readonly Messenger $messenger,
        private readonly WhatsAppClient $whatsapp,
    ) {}

    public function handle(Contact $contact, InboundMessage $inbound, Message $logged): void
    {
        if ($contact->botIsPaused()) {
            return; // a human owns this chat right now
        }

        if ($inbound->isPaymentProofCandidate() && $this->handlePaymentProof($contact, $inbound)) {
            return;
        }

        // Debounce: if the client already sent another message, let that job answer everything at once.
        $newer = $contact->messages()->where('direction', 'in')->where('id', '>', $logged->id)
            ->whereIn('type', ['text', 'interactive', 'button'])->exists();
        if ($newer) {
            return;
        }

        try {
            $reply = $this->agent->reply($contact);
        } catch (\Throwable $e) {
            Log::error('Agent failed', ['contact' => $contact->wa_id, 'error' => $e->getMessage()]);
            $reply = "Thanks for your message! A team member will reply shortly ⚽";
        }

        if ($reply) {
            $this->messenger->text($contact->fresh(), $reply);
        }
    }

    /** Screenshot/PDF while the client has an unpaid or pending booking = payment proof. */
    private function handlePaymentProof(Contact $contact, InboundMessage $inbound): bool
    {
        $cutoff = now()->subMinutes((int) config('fbl.payment_timeout_minutes', 120));

        $booking = $contact->bookings()
            ->where(function ($q) use ($cutoff) {
                $q->where(fn ($q) => $q->where('status', 'pending_payment')->where('created_at', '>', $cutoff))
                    ->orWhere('status', 'awaiting_approval');
            })
            ->latest()
            ->first();

        if (! $booking || ! $inbound->mediaId) {
            return false; // let the agent handle a random image
        }

        $alreadyReported = $booking->status === 'awaiting_approval';
        $proof = $this->whatsapp->reuploadMedia($inbound->mediaId, $inbound->mediaLink);
        $note = $inbound->text ?: null;
        if (! $proof) {
            $note = trim('Screenshot received but could not be attached, check the chat. '.($note ?? ''));
        }

        if (! $this->bookings->reportPayment($booking, $proof, $note)) {
            return false; // booking changed meanwhile (expired/confirmed/full): let the agent explain
        }

        $this->messenger->text($contact->fresh(), $alreadyReported
            ? "Thanks, we've added this to booking {$booking->reference}. The team will confirm here shortly 🙏"
            : "Got it 🙏 We're checking your payment for booking {$booking->reference}.\nYou'll get a confirmation message here shortly.");

        return true;
    }
}
