<?php

namespace App\Services\Booking;

use App\Models\AvailabilityRequest;
use App\Models\Booking;
use App\Models\Contact;
use App\Models\Game;
use App\Models\WaitlistEntry;
use App\Services\WhatsApp\Messenger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * When a game is published, message the people who said they're free that day
 * and the people who played the same weekday last week. Once per person per day.
 */
class ReminderService
{
    public function __construct(private readonly Messenger $messenger) {}

    /** Client said "I want to play on <date>". */
    public function remember(Contact $contact, string $date, int $players = 1): array
    {
        $tz = config('fbl.timezone');
        try {
            $day = Carbon::createFromFormat('!Y-m-d', $date, $tz);
        } catch (\Throwable) {
            return ['error' => 'Use the date format YYYY-MM-DD.'];
        }
        $today = Carbon::now($tz)->startOfDay();
        if ($day->lt($today) || $day->gt($today->copy()->addDays(60))) {
            return ['error' => 'Pick a date between today and the next 60 days.'];
        }

        $games = Game::upcoming()->get()->filter(fn (Game $g) => $g->localStart()->toDateString() === $day->toDateString());
        if ($games->isNotEmpty()) {
            return ['already_published' => true, 'note' => 'Games are already published for that day: call list_games and offer them now instead.'];
        }

        AvailabilityRequest::updateOrCreate(
            ['contact_id' => $contact->id, 'play_date' => $day->toDateString()],
            ['players' => max(1, min(10, $players)), 'notified_at' => null]
        );
        if ($contact->reminders_opted_out_at) {
            $contact->update(['reminders_opted_out_at' => null]); // asking again = opting back in
        }

        return ['saved' => true, 'day' => $day->format('l j F'),
            'note' => 'Tell the client: saved, we will message them here as soon as the game for that day is posted. No payment needed now.'];
    }

    /** Called right after a game is published. Returns how many reminders went out. */
    public function notifyForGame(Game $game): int
    {
        if (! config('fbl.reminders.enabled', true)) {
            return 0;
        }
        $tz = config('fbl.timezone');
        $day = $game->localStart()->toDateString();

        $recipients = []; // contact_id => reason
        AvailabilityRequest::where('play_date', $day)->whereNull('notified_at')->pluck('contact_id')
            ->each(function ($id) use (&$recipients) { $recipients[$id] = 'asked'; });

        // Played the same weekday last week (bot bookings only: those are the numbers we have).
        $lastWeek = $game->localStart()->subWeek();
        $from = $lastWeek->copy()->startOfDay()->utc();
        $to = $lastWeek->copy()->endOfDay()->utc();
        Booking::where('status', 'confirmed')->whereNotNull('contact_id')
            ->whereHas('game', fn ($q) => $q->whereBetween('starts_at', [$from, $to])->where('status', '!=', 'cancelled'))
            ->pluck('contact_id')->unique()
            ->each(function ($id) use (&$recipients) { $recipients[$id] ??= 'last_week'; });

        if (! $recipients) {
            return 0;
        }

        // Already in a game that day, or waiting on one (the waitlist message covers them).
        $dayStart = Carbon::parse($day, $tz)->startOfDay()->utc();
        $dayEnd = Carbon::parse($day, $tz)->endOfDay()->utc();
        $busy = Booking::whereIn('status', ['pending_payment', 'awaiting_approval', 'confirmed'])
            ->whereHas('game', fn ($q) => $q->whereBetween('starts_at', [$dayStart, $dayEnd]))
            ->pluck('contact_id')
            ->merge(WaitlistEntry::whereHas('game', fn ($q) => $q->whereBetween('starts_at', [$dayStart, $dayEnd]))->pluck('contact_id'))
            ->filter()->unique()->all();

        $sent = 0;
        $contacts = Contact::whereIn('id', array_keys($recipients))->whereNull('reminders_opted_out_at')->get();
        foreach ($contacts as $contact) {
            if ($contact->isAdmin() || in_array($contact->id, $busy, true)) {
                continue;
            }
            // Claim the slot first: one reminder per person per day, even if two games publish at once.
            try {
                $claimId = DB::table('game_reminders')->insertGetId([
                    'contact_id' => $contact->id, 'game_id' => $game->id, 'play_date' => $day,
                    'reason' => $recipients[$contact->id], 'created_at' => now(), 'updated_at' => now(),
                ]);
            } catch (QueryException) {
                continue;
            }

            $ok = $this->send($contact, $game, $recipients[$contact->id]);
            DB::table('game_reminders')->where('id', $claimId)->update(['sent' => $ok]);
            AvailabilityRequest::where('contact_id', $contact->id)->where('play_date', $day)->update(['notified_at' => now()]);
            $sent += $ok ? 1 : 0;
        }

        return $sent;
    }

    private function send(Contact $contact, Game $game, string $reason): bool
    {
        $name = $contact->name ? strtok(trim($contact->name), ' ') : 'there';
        $price = (float) $game->price.' '.config('fbl.currency');
        $spots = $game->spotsLeft();

        if ($contact->windowIsOpen()) {
            $intro = $reason === 'asked'
                ? "Hi {$name} ⚽ You asked about {$game->localStart()->format('l')}: the game is on!"
                : "Hi {$name} ⚽ You played with us last {$game->localStart()->format('l')}. This week's game is on!";

            return $this->messenger->text($contact,
                "{$intro}\n📅 {$game->label()}\n💰 {$price} · {$spots} spot(s) left\n\n"
                ."Want a spot? Reply YES and how many players.\nReply STOP for no more reminders.",
                'bot', ['kind' => 'game_reminder', 'game_id' => $game->id]);
        }

        // Outside the 24h window only an approved template can go out (Marketing category, paid per message).
        return $this->messenger->template($contact, (string) config('whatsapp.templates.game_reminder'),
            [$name, $game->label(), $price], [], ['kind' => 'game_reminder', 'game_id' => $game->id]);
    }

    /** "STOP" / "START" from a client. Returns the reply to send, or null if it isn't one of those. */
    public function handleOptCommand(Contact $contact, string $text): ?string
    {
        $t = mb_strtolower(trim($text, " \t\n.!"));
        if (in_array($t, ['stop', 'unsubscribe', 'stop reminders', 'توقف', 'ستوب', 'الغاء الاشتراك'], true)) {
            $contact->update(['reminders_opted_out_at' => now()]);
            AvailabilityRequest::where('contact_id', $contact->id)->whereNull('notified_at')->delete();

            return "Done ✅ No more reminders. Message us anytime to book a game ⚽";
        }
        if (in_array($t, ['start', 'start reminders'], true) && $contact->reminders_opted_out_at) {
            $contact->update(['reminders_opted_out_at' => null]);

            return "Reminders are back on ✅ We'll message you when games are posted.";
        }

        return null;
    }
}
