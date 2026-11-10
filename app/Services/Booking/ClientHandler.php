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
        private readonly ReminderService $reminders,
    ) {}

    public function handle(Contact $contact, InboundMessage $inbound, Message $logged): void
    {
        // "bot off": stay silent for clients. Admins in #test mode still get answers so they can try things.
        if (\App\Services\BotSwitch::isOff() && ! $contact->isAdmin()) {
            return;
        }

        if ($contact->isSaved()) {
            return; // old client saved in the team's phone: humans answer, not the bot
        }

        if ($inbound->type === 'text' && ($optReply = $this->reminders->handleOptCommand($contact, (string) $inbound->text)) !== null) {
            $this->messenger->text($contact->fresh(), $optReply);

            return;
        }

        if ($contact->botIsPaused()) {
            return; // a human owns this chat right now
        }

        if ($inbound->isPaymentProofCandidate() && $this->handlePaymentProof($contact, $inbound)) {
            return;
        }

        // Debounce: if the client already sent another message, let that job answer everything at once.
        $newer = $contact->messages()->where('direction', 'in')->where('id', '>', $logged->id)
            ->where('created_at', '>=', $logged->created_at) // ignore old chats arriving from history sync
            ->whereIn('type', ['text', 'interactive', 'button'])->exists();
        if ($newer) {
            return;
        }

        // Unknown number (no ad, no history): ask the team first, stay quiet until they answer (max 15 min).
        if (! $contact->act_as_client && $this->agent->needsAdminCheck($contact)) {
            if (! $contact->client_type_asked_at) {
                $this->askAdminsWhoThisIs($contact, (string) $logged->body);
            }

            return;
        }

        $this->replyNow($contact, $logged);
    }

    /** Let the agent answer the chat now. */
    public function replyNow(Contact $contact, ?Message $logged = null): void
    {
        try {
            $reply = $this->agent->reply($contact);
        } catch (\Throwable $e) {
            Log::error('Agent failed', ['contact' => $contact->wa_id, 'error' => $e->getMessage()]);
            $reply = null;
            $last = mb_substr((string) ($logged?->body ?? $contact->messages()->where('direction', 'in')->latest('id')->value('body')), 0, 300);
            foreach ($this->messenger->adminContacts() as $admin) {
                $this->messenger->alertAdmin($admin, "⚠️ The bot couldn't answer {$contact->displayName()} (+{$contact->wa_id}): \"{$last}\". Reply from the WhatsApp app.");
            }
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
            ? "Got it ✅ Added to booking {$booking->reference}."
            : "Received ✅ Booking {$booking->reference}, your confirmation comes here 👊");

        return true;
    }

    private function askAdminsWhoThisIs(Contact $contact, string $firstMessage): void
    {
        $contact->update(['client_type_asked_at' => now()]);
        $text = "❓ Do you know +{$contact->wa_id}".($contact->name ? " ({$contact->name})" : '')."?\n"
            ."💬 \"".mb_substr($firstMessage, 0, 200)."\"\n"
            .'No ad, no chat history. I wait for you before replying (max 15 min, then I guess from what they write).';
        foreach ($this->messenger->adminContacts() as $admin) {
            $admin->windowIsOpen()
                ? $this->messenger->buttons($admin, $text, ['who:'.$contact->id.':old' => '✅ Old client', 'who:'.$contact->id.':new' => '🆕 New player'])
                : $this->messenger->alertAdmin($admin, $text."\nReply: old {$contact->wa_id} / new {$contact->wa_id}");
        }
    }
}
