<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Services\WhatsApp\Messenger;
use Illuminate\Console\Command;

class BookingHousekeeping extends Command
{
    protected $signature = 'bookings:housekeeping';

    protected $description = 'Release unpaid holds and remind admins about bookings waiting for approval';

    public function handle(Messenger $messenger): int
    {
        $cutoff = now()->subMinutes((int) config('fbl.payment_timeout_minutes', 120));

        // 1) Unpaid holds past the timeout free their spots.
        //    lazyById: rows leave the filter as we go, so offset paging would skip some.
        //    Conditional update: an admin may confirm, or the client report payment, in the meantime.
        Booking::where('status', 'pending_payment')
            ->where('created_at', '<=', $cutoff)
            ->lazyById(200)
            ->each(function (Booking $booking) use ($messenger, $cutoff) {
                $expired = Booking::whereKey($booking->id)
                    ->where('status', 'pending_payment')
                    ->where('created_at', '<=', $cutoff)
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
