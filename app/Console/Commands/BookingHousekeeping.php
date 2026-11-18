<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Services\WhatsApp\Messenger;
use Illuminate\Console\Command;

class BookingHousekeeping extends Command
{
    protected $signature = 'bookings:housekeeping';

    protected $description = 'Release unpaid holds and remind admins about bookings waiting for approval';

    public function handle(Messenger $messenger, \App\Services\Payments\PaymentService $payments): int
    {
        if (\App\Services\BotSwitch::isOff()) {
            $this->info('Bot is off (admin sent "bot off"): skipping timed messages.');

            return self::SUCCESS;
        }

        // 0) Catch any Ziina payments whose webhook was missed, before releasing holds.
        $payments->reconcile();

        // 0b) Day-before payment links for spots reserved in advance.
        $payments->releaseDueLinks();

        $cutoff = now()->subMinutes((int) config('fbl.payment_timeout_minutes', 120));

        // 0c) Link sent, no payment yet, halfway through the hold: remind the client once and tell the admins.
        $half = now()->subMinutes(intdiv((int) config('fbl.payment_timeout_minutes', 120), 2));
        Booking::with(['game', 'contact'])->where('status', 'pending_payment')->where('payment_verified', false)
            ->whereNotNull('payment_url')->whereNull('payment_proof_media_id')->whereNull('payment_reminded_at')
            ->where('created_at', '<=', $half)->where('created_at', '>', $cutoff)
            ->get()->each(function (Booking $b) use ($messenger) {
                $b->update(['payment_reminded_at' => now()]);
                $left = max(5, (int) config('fbl.payment_timeout_minutes', 120) - (int) $b->created_at->diffInMinutes(now()));
                if ($b->contact && ! $b->contact->botIsPaused()) {
                    $first = preg_split('/\s+/u', trim(str_replace(Booking::GK_MARK, '', (string) $b->player_name)))[0] ?? '';
                    $messenger->updateClient($b->contact, $b->reference,
                        "hey {$first} 👋 kind reminder please, your spot is held for about {$left} more minutes\n{$b->payment_url}\n\nsend the screenshot here after paying, thank you 🙏");
                }
                foreach ($messenger->adminContacts() as $admin) {
                    $messenger->alertAdmin($admin, "⏰ {$b->player_name} ({$b->reference}) hasn't paid yet · {$b->game->label()} · {$left} min left on the hold. I reminded them.");
                }
            });

        // 1) Unpaid holds past the timeout free their spots.
        //    lazyById: rows leave the filter as we go, so offset paging would skip some.
        //    Conditional update: an admin may confirm, or the client report payment, in the meantime.
        Booking::where('status', 'pending_payment')
            ->where('payment_verified', false)
            ->where(fn ($q) => $q->where(fn ($q) => $q->whereNotNull('payment_url')->where('created_at', '<=', $cutoff))
                // waiting for the admin's link for 12h+ (reserved-in-advance spots only count once their link is due)
                ->orWhere(fn ($q) => $q->whereNull('payment_url')
                    ->where(fn ($q) => $q->whereNull('link_due_at')->orWhere('link_due_at', '<=', now()))
                    ->whereRaw('COALESCE(link_requested_at, created_at) <= ?', [now()->subHours(12)])))
            ->lazyById(200)
            ->each(function (Booking $booking) use ($messenger, $cutoff) {
                $expired = Booking::whereKey($booking->id)
                    ->where('status', 'pending_payment')
                    ->when($booking->payment_url,
                        fn ($q) => $q->where('created_at', '<=', $cutoff),
                        fn ($q) => $q->whereRaw('COALESCE(link_requested_at, created_at) <= ?', [now()->subHours(12)]))
                    ->update(['status' => 'expired']);
                if ($expired === 0) {
                    return;
                }

                $b = $booking->fresh(['game']);
                foreach ($messenger->adminContacts() as $admin) {
                    $messenger->alertAdmin($admin, "⌛ {$b->player_name} ({$b->reference}) didn't pay in time: spot released · {$b->game?->label()}");
                }
                $contact = $booking->contact()->first();
                if ($contact && ! $contact->botIsPaused()) {
                    $messenger->text($contact,
                        "Your held spot ({$booking->reference}) was released because we didn't receive the payment. "
                        .'Message us anytime if you still want to play ⚽');
                }
            });

        // 1b) Spots freed (expired holds, cancellations): ask admins about the next person waiting.
        $roster = app(\App\Services\Booking\RosterService::class);
        \App\Models\Game::where('status', 'published')->where('starts_at', '>', now())
            ->whereHas('waitlist', fn ($q) => $q->whereNull('notified_at')->whereNull('skipped_at')->whereNull('admin_asked_at'))
            ->get()
            ->each(fn ($game) => $roster->offerFreedSpots($game));

        // 2c) Unknown numbers nobody identified yet: the bot keeps waiting for you (it never guesses), reminders at 15 min and 1 h.
        \App\Models\Contact::whereNull('client_type')->whereNotNull('client_type_asked_at')
            ->where('client_type_asked_at', '>', now()->subDay())->get()
            ->each(function (\App\Models\Contact $c) use ($messenger) {
                $mins = (int) $c->client_type_asked_at->diffInMinutes(now());
                foreach ([15, 60] as $step) {
                    if ($mins >= $step && \Illuminate\Support\Facades\Cache::add("fbl:who-reminded:{$c->id}:{$step}", true, now()->addDay())) {
                        foreach ($messenger->adminContacts() as $admin) {
                            $messenger->alertAdmin($admin, "⏰ Still waiting ({$mins} min): is +{$c->wa_id}".($c->name ? " ({$c->name})" : '')
                                ." an old client or a new player? I haven't replied to them. Reply: old {$c->wa_id} / new {$c->wa_id}");
                        }
                    }
                }
            });

        // 2b) Chats the bot handed to the team that nobody has answered yet: remind at 15 min and 1 h.
        \App\Models\Contact::whereNotNull('handoff_at')->get()->each(function (\App\Models\Contact $c) use ($messenger) {
            $due = [15, 60][$c->handoff_reminders] ?? null;
            if ($due === null || $c->handoff_at->gt(now()->subMinutes($due))) {
                return;
            }
            $waiting = $c->handoff_at->diffForHumans(now(), \Carbon\CarbonInterface::DIFF_ABSOLUTE);
            $more = $c->messages()->where('direction', 'in')->where('created_at', '>', $c->handoff_at)->count();
            foreach ($messenger->adminContacts() as $admin) {
                $messenger->alertAdmin($admin, "⏰ Still waiting for you ({$waiting}): {$c->displayName()} (+{$c->wa_id}) · {$c->handoff_reason}"
                    .($more ? " · sent {$more} more message(s)" : '')
                    .'. Reply from the WhatsApp app, or send "resume '.$c->wa_id.'" to let the bot answer again.');
            }
            $c->update(['handoff_reminders' => $c->handoff_reminders + 1]);
        });

        // 2a) Clients waiting 15+ min for a payment link: one nudge to admins.
        $noLink = Booking::where('status', 'pending_payment')->whereNull('payment_url')
            ->whereNotNull('link_requested_at')->whereNull('admin_reminded_at')
            ->where('link_requested_at', '<=', now()->subMinutes(15))->get();
        if ($noLink->isNotEmpty()) {
            foreach ($messenger->adminContacts() as $admin) {
                $messenger->alertAdmin($admin, "⏳ {$noLink->count()} client(s) still waiting for a payment link: "
                    .$noLink->pluck('reference')->implode(', ').'. Send: FBL-XXXXX https://…');
            }
            Booking::whereIn('id', $noLink->pluck('id'))->update(['admin_reminded_at' => now()]);
        }

        // 2) One reminder for anything waiting on an admin for 30+ minutes.
        $waiting = Booking::where('status', 'awaiting_approval')
            ->whereNull('admin_reminded_at')
            ->where('payment_reported_at', '<=', now()->subMinutes(30))
            ->get();

        if ($waiting->isNotEmpty()) {
            $refs = $waiting->pluck('reference')->implode(', ');
            foreach ($messenger->adminContacts() as $admin) {
                $messenger->alertAdmin($admin, "⏳ {$waiting->count()} booking(s) still waiting for your OK: {$refs}. Send \"pending\" to see them.");
            }
            Booking::whereIn('id', $waiting->pluck('id'))->update(['admin_reminded_at' => now()]);
        }

        $this->info('Housekeeping done.');

        return self::SUCCESS;
    }
}
