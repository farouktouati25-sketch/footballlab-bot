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
