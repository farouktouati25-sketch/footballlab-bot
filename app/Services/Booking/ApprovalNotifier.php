<?php

namespace App\Services\Booking;

use App\Models\Booking;
use App\Models\Contact;
use App\Services\WhatsApp\Messenger;

/** Messages around the approval loop: admins ← request, client ← result, other admins ← who decided. */
class ApprovalNotifier
{
    public function __construct(
        private readonly Messenger $messenger,
        private readonly RosterService $roster,
    ) {}

    public function requestApproval(Booking $booking): void
    {
        $game = $booking->game;
        $client = $booking->contact;
        $spotsAfter = $game->confirmedPlayers() + $booking->players;
        $proof = $booking->payment_verified
            ? 'PAID online via Ziina ✅ (verified)'
            : ($booking->payment_proof_media_id
            ? 'screenshot attached'
            : 'client says paid, NO screenshot'.($booking->payment_note ? ' ('.$booking->payment_note.')' : ''));

        $summary = "🆕 Booking {$booking->reference} needs your OK\n"
            ."👤 {$booking->player_name} · +{$client->wa_id}\n"
            ."⚽ {$game->label()}\n"
            ."👥 {$booking->players} player(s) · 💰 {$booking->amountLabel()}\n"
            ."🧾 Payment: {$proof}\n"
            ."📊 After confirming: {$spotsAfter}/{$game->capacity}";

        $buttons = [
            'approve:'.$booking->id => '✅ Confirm',
            'reject:'.$booking->id => '❌ Reject',
        ];

        foreach ($this->messenger->adminContacts() as $admin) {
            if ($admin->windowIsOpen()) {
                $header = null;
                if ($booking->payment_proof_media_id) {
                    if (str_starts_with((string) $booking->payment_proof_mime, 'image/')) {
                        $header = $booking->payment_proof_media_id;
                    } else {
                        $this->messenger->media($admin, $booking->payment_proof_media_id, (string) $booking->payment_proof_mime, $booking->reference);
                    }
                }
                $this->messenger->buttons($admin, $summary, $buttons, $header, 'First tap wins');
            } else {
                // Window closed: use the approved template. Its quick-reply buttons carry the same payloads.
                $this->messenger->template($admin, (string) config('whatsapp.templates.admin_approval'), [
                    $booking->reference,
                    $booking->player_name.' (+'.$client->wa_id.')',
                    $game->label(),
                    $booking->players.' player(s), '.$booking->amountLabel(),
                    $proof,
                ], array_keys($buttons));
            }
        }
    }

    public function announceDecision(Booking $booking, Contact $decidedBy): void
    {
        $game = $booking->game;
        $client = $booking->contact;
        $confirmed = $booking->status === 'confirmed';

        // 1) Client
        if ($confirmed) {
            $groupLink = config('fbl.facts.group_link');
            $this->messenger->updateClient($client, $booking->reference,
                "✅ You're in, {$booking->player_name}! Booking {$booking->reference} is confirmed.\n"
                ."⚽ {$game->label()}\n"
                ."👥 {$booking->players} player(s)\n\n"
                ."Please arrive 10 minutes early. See you on the pitch!"
                .($groupLink ? "\n\nGame updates are posted in our group: {$groupLink}" : '')
            );
        } else {
            $this->messenger->updateClient($client, $booking->reference,
                "We couldn't verify the payment for booking {$booking->reference} yet. "
                .'A team member will message you shortly to sort it out.'
            );
            // A human should follow up, so the bot steps back from this chat.
            $client->update(['bot_paused_until' => now()->addHours((int) config('fbl.human_takeover_hours', 12))]);
        }

        // 2) The admin who tapped, and 3) every other admin
        $verb = $confirmed ? '✅ confirmed' : '❌ rejected';
        $count = $game->confirmedPlayers();
        $line = "{$booking->reference} {$verb} by {$decidedBy->adminName()} · {$booking->player_name} · {$game->label()} · {$count}/{$game->capacity} confirmed";
        if (! $confirmed) {
            $line .= ' · bot paused in that chat, please message +'.$client->wa_id;
        }

        foreach ($this->messenger->adminContacts() as $admin) {
            $this->messenger->alertAdmin($admin, $line);
        }

        if ($confirmed) {
            $this->roster->shareUpdated($game, "{$booking->player_name} confirmed");
        }
    }

    /** Tell the admin who tapped late that someone else already handled it. */
    public function alreadyDecided(Contact $admin, Booking $booking): void
    {
        $who = $booking->decided_by
            ? (Contact::where('wa_id', $booking->decided_by)->first()?->adminName() ?? '+'.$booking->decided_by)
            : 'the system';

        $this->messenger->alertAdmin($admin, "{$booking->reference} was already {$booking->status} by {$who}. Nothing changed.");
    }
}
