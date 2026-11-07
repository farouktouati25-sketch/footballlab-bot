<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Message;
use App\Services\Booking\AdminHandler;
use App\Services\Booking\ClientHandler;
use App\Services\WhatsApp\InboundMessage;
use App\Services\WhatsApp\WhatsAppClient;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

class InboundRouter
{
    public function __construct(
        private readonly ClientHandler $clients,
        private readonly AdminHandler $admins,
        private readonly WhatsAppClient $whatsapp,
    ) {}

    /**
     * Step 1 (outside the per-contact lock): store the message so newer messages are visible
     * to the job that's still answering older ones (debounce). Returns null if already handled.
     *
     * @return array{0:Contact,1:Message}|null
     */
    public function record(InboundMessage $inbound): ?array
    {
        $existing = Message::where('wa_message_id', $inbound->id)->first();
        if ($existing) {
            // Meta re-delivery or a job retry: retry only if the handler never finished.
            return ($existing->direction === 'in' && $existing->processed_at === null)
                ? [$existing->contact, $existing]
                : null;
        }

        $contact = Contact::firstOrCreate(['wa_id' => $inbound->from]);
        $updates = ['last_inbound_at' => now()];
        if ($inbound->profileName && ! $contact->name) {
            $updates['name'] = $inbound->profileName;
        }
        if ($inbound->referral && ! $contact->first_referral) {
            $updates['first_referral'] = $inbound->referral;
            $updates['source'] = ($inbound->referral['source_type'] ?? null) === 'ad' ? 'meta_ad' : 'referral';
        } elseif (! $contact->source) {
            $updates['source'] = 'organic';
        }
        $contact->update($updates);

        $isAdminMode = $contact->isAdmin()
            && (! $contact->act_as_client || str_starts_with(mb_strtolower((string) $inbound->text), '#test'));

        try {
            $message = Message::create([
                'contact_id' => $contact->id,
                'direction' => 'in',
                'author' => $isAdminMode ? 'admin' : 'client',
                'type' => $inbound->type,
                'body' => $inbound->summary(),
                'wa_message_id' => $inbound->id,
                'payload' => array_filter([
                    'button_id' => $inbound->buttonId,
                    'media_id' => $inbound->mediaId,
                    'mime' => $inbound->mediaMime,
                    'forwarded' => $inbound->forwarded ?: null,
                    'referral' => $inbound->referral,
                ]),
            ]);
        } catch (UniqueConstraintViolationException) {
            return null; // a parallel delivery of the same message won the race
        }

        return [$contact, $message];
    }

    /** Step 2 (inside the per-contact lock): act on it, then mark it processed. */
    public function process(Contact $contact, Message $message, InboundMessage $inbound): void
    {
        $message->refresh();
        if ($message->processed_at !== null) {
            return;
        }
        $contact->refresh();

        try {
            $this->whatsapp->markRead($inbound->id);
        } catch (\Throwable $e) {
            Log::debug('markRead failed', ['error' => $e->getMessage()]);
        }

        if ($inbound->type !== 'reaction') {
            $message->author === 'admin'
                ? $this->admins->handle($contact, $inbound)
                : $this->clients->handle($contact, $inbound, $message);
        }

        // Only reached if the handler didn't throw, so a failed job is retried for real.
        $message->update(['processed_at' => now()]);
    }
}
