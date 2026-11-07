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

    /**
     * Called right after a game is published. Nothing is sent yet: the people to remind are listed
     * for the admins, who tap "Send" (or pick some). Returns how many people are waiting for that OK.
     */
    public function notifyForGame(Game $game): int
    {
        if (! config('fbl.reminders.enabled', true)) {
            return 0;
        }
        $tz = config('fbl.timezone');
        $local = $game->localStart();
        $day = $local->toDateString();

        $recipients = []; // contact_id => reason (first reason wins: asked > usual_day > last_week)
        AvailabilityRequest::where('play_date', $day)->whereNull('notified_at')->pluck('contact_id')
            ->each(function ($id) use (&$recipients) { $recipients[$id] ??= 'asked'; });

        Contact::whereNotNull('preferred_days')->get(['id', 'preferred_days'])
            ->filter(fn (Contact $c) => in_array($local->dayOfWeek, array_map('intval', (array) $c->preferred_days), true))
            ->each(function (Contact $c) use (&$recipients) { $recipients[$c->id] ??= 'usual_day'; });

        // Played the same weekday last week (bot bookings only: those are the numbers we have).
        $lastWeek = $local->copy()->subWeek();
        Booking::where('status', 'confirmed')->whereNotNull('contact_id')
            ->whereHas('game', fn ($q) => $q->whereBetween('starts_at', [$lastWeek->copy()->startOfDay()->utc(), $lastWeek->copy()->endOfDay()->utc()])
                ->where('status', '!=', 'cancelled'))
            ->pluck('contact_id')->unique()
            ->each(function ($id) use (&$recipients) { $recipients[$id] ??= 'last_week'; });

        if (! $recipients) {
            return 0;
        }

        $busy = $this->busyOn($day);
        $queued = 0;
        $contacts = Contact::whereIn('id', array_keys($recipients))->whereNull('reminders_opted_out_at')->get();
        foreach ($contacts as $contact) {
            if ($contact->isAdmin() || in_array($contact->id, $busy, true)) {
                continue;
            }
            // One reminder per person per day, even if two games publish at once.
            try {
                DB::table('game_reminders')->insert([
                    'contact_id' => $contact->id, 'game_id' => $game->id, 'play_date' => $day,
                    'reason' => $recipients[$contact->id], 'status' => 'pending', 'sent' => false,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $queued++;
            } catch (QueryException) {
                continue;
            }
        }

        if ($queued > 0) {
            $this->askAdmins($game);
        }

        return $queued;
    }

    /** The numbered list admins choose from. */
    public function pendingFor(Game $game): \Illuminate\Support\Collection
    {
        $rank = ['asked' => 0, 'usual_day' => 1, 'last_week' => 2];

        return DB::table('game_reminders')->where('game_id', $game->id)->where('status', 'pending')->orderBy('id')->get()
            ->sortBy(fn ($r) => [$rank[$r->reason] ?? 9, $r->id])->values()
            ->map(fn ($r) => (object) ['row' => $r, 'contact' => Contact::find($r->contact_id)])
            ->filter(fn ($r) => $r->contact)->values();
    }

    public function askAdmins(Game $game): void
    {
        $pending = $this->pendingFor($game);
        if ($pending->isEmpty()) {
            return;
        }
        $why = ['asked' => 'asked for this day', 'usual_day' => 'usually free this day', 'last_week' => 'played last week'];
        $lines = $pending->map(fn ($r, $i) => ($i + 1).'. '.($r->contact->player_name ?: $r->contact->name ?: 'Player')
            .' · +'.$r->contact->wa_id.' · '.($why[$r->row->reason] ?? $r->row->reason))->implode("\n");
        $text = "📣 Remind these players about #{$game->id} ({$game->label()})?\n\n{$lines}\n\n"
            ."Send to all, or pick: remind {$game->id} 1,3,5";

        foreach ($this->messenger->adminContacts() as $admin) {
            $admin->windowIsOpen()
                ? $this->messenger->buttons($admin, mb_substr($text, 0, 1024), ['rem_all:'.$game->id => '📩 Send to all', 'rem_skip:'.$game->id => '⏭ Don\'t send'])
                : $this->messenger->alertAdmin($admin, $text.' (or: remind '.$game->id.' all / remind '.$game->id.' skip)');
        }
    }

    /**
     * Admin decision. $pick = null → everyone pending; [] → skip all; [1,3] → those list numbers.
     * Returns a one-line summary for the admin.
     */
    public function sendApproved(Game $game, ?array $pick): string
    {
        $pending = $this->pendingFor($game);
        if ($pending->isEmpty()) {
            return 'Nothing waiting to send for #'.$game->id.' (already handled).';
        }
        if ($pick === []) {
            DB::table('game_reminders')->where('game_id', $game->id)->where('status', 'pending')->update(['status' => 'skipped', 'updated_at' => now()]);

            return '⏭ No reminders sent for #'.$game->id.'.';
        }

        $busy = $this->busyOn($pending->first()->row->play_date);
        $sent = 0;
        $failed = [];
        foreach ($pending as $i => $r) {
            if ($pick !== null && ! in_array($i + 1, $pick, true)) {
                continue;
            }
            // Claim the row so a second admin tapping at the same time can't double-send.
            $claimed = DB::table('game_reminders')->where('id', $r->row->id)->where('status', 'pending')->update(['status' => 'sending']);
            if (! $claimed) {
                continue;
            }
            $ok = ! in_array($r->contact->id, $busy, true) && ! $r->contact->reminders_opted_out_at
                && $this->send($r->contact, $game, $r->row->reason);
            DB::table('game_reminders')->where('id', $r->row->id)->update(['status' => $ok ? 'sent' : 'failed', 'sent' => $ok, 'updated_at' => now()]);
            AvailabilityRequest::where('contact_id', $r->contact->id)->where('play_date', $r->row->play_date)->update(['notified_at' => now()]);
            $ok ? $sent++ : $failed[] = ($r->contact->player_name ?: $r->contact->name ?: '+'.$r->contact->wa_id);
        }
        if ($pick !== null) {
            DB::table('game_reminders')->where('game_id', $game->id)->where('status', 'pending')->update(['status' => 'skipped', 'updated_at' => now()]);
        }

        return "📩 Reminded {$sent} player(s) about #{$game->id}."
            .($failed ? ' Not delivered: '.implode(', ', $failed).' (chat closed and the game_reminder template not approved yet, or already booked).' : '');
    }

    /** Contacts already in, holding, or waiting on a game that day. */
    private function busyOn(string $day): array
    {
        $tz = config('fbl.timezone');
        $start = Carbon::parse(substr($day, 0, 10), $tz)->startOfDay()->utc();
        $end = Carbon::parse(substr($day, 0, 10), $tz)->endOfDay()->utc();

        return Booking::whereIn('status', ['pending_payment', 'awaiting_approval', 'confirmed'])
            ->whereHas('game', fn ($q) => $q->whereBetween('starts_at', [$start, $end]))
            ->pluck('contact_id')
            ->merge(WaitlistEntry::whereHas('game', fn ($q) => $q->whereBetween('starts_at', [$start, $end]))->pluck('contact_id'))
            ->filter()->unique()->values()->all();
    }

    /** "My name is Farouk, I'm free Sundays": keep it on the contact for future games. */
    public function saveProfile(Contact $contact, ?string $name, array $days): array
    {
        $map = ['sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6];
        $nums = [];
        foreach ($days as $d) {
            $k = mb_substr(mb_strtolower(trim((string) $d)), 0, 3);
            if (isset($map[$k])) {
                $nums[] = $map[$k];
            }
        }
        $nums = array_values(array_unique($nums));
        sort($nums);

        $update = [];
        if ($name !== null && trim($name) !== '') {
            $update['player_name'] = mb_substr(trim($name), 0, 120);
        }
        if ($nums) {
            $update['preferred_days'] = $nums;
            $update['reminders_opted_out_at'] = null;
        }
        if (! $update) {
            return ['error' => 'Nothing to save: pass a name and/or days like ["Sunday","Friday"].'];
        }
        $contact->update($update);
        $names = array_map(fn ($n) => array_search($n, $map, true), $nums);

        return ['saved' => true, 'name' => $contact->player_name, 'days' => array_map('ucfirst', $names),
            'note' => 'Confirm in one friendly line, e.g. "Saved ✅ Farouk · Sundays. I\'ll ping you when a Sunday game is posted." If a game on that day is already listed, offer it now.'];
    }

    /** Spreadsheet (CSV, opens in Excel/Google Sheets) of everyone the bot knows. */
    public function playersCsv(): string
    {
        $dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF"); // so Excel reads Arabic names correctly
        fputcsv($out, ['Name', 'WhatsApp name', 'Phone', 'Usual days', 'Games played', 'Last game', 'Source', 'First contact', 'Reminders']);

        Contact::query()->orderByDesc('last_inbound_at')->get()
            ->reject(fn (Contact $c) => $c->isAdmin() || ! preg_match('/^\d{8,15}$/', $c->wa_id))
            ->each(function (Contact $c) use ($out, $dayNames) {
                $played = Booking::where('contact_id', $c->id)->where('status', 'confirmed')->with('game')->get();
                $last = $played->sortByDesc(fn ($b) => $b->game?->starts_at)->first()?->game;
                fputcsv($out, [
                    $c->player_name ?: '',
                    $c->name ?: '',
                    '+'.$c->wa_id,
                    implode(' ', array_map(fn ($d) => $dayNames[(int) $d] ?? '', (array) ($c->preferred_days ?? []))),
                    $played->count(),
                    $last ? $last->localStart()->format('D j M Y') : '',
                    $c->source ?: '',
                    $c->created_at?->setTimezone(config('fbl.timezone'))->format('Y-m-d'),
                    $c->reminders_opted_out_at ? 'STOPPED' : 'on',
                ]);
            });
        rewind($out);

        return (string) stream_get_contents($out);
    }

    private function send(Contact $contact, Game $game, string $reason): bool
    {
        $name = ($contact->player_name ?: $contact->name) ? strtok(trim($contact->player_name ?: $contact->name), ' ') : 'bro';
        $price = (float) $game->price.' '.config('fbl.currency');
        $spots = $game->spotsLeft();

        if ($contact->windowIsOpen()) {
            $dayName = $game->localStart()->format('l');
            $intro = match ($reason) {
                'asked' => "Hey {$name} ⚽ You asked about {$dayName}: the game is on!",
                'usual_day' => "Hey {$name} ⚽ {$dayName} game is on, you said that's your day 👊",
                default => "Hey {$name} ⚽ You played with us last {$dayName}. This week's game is on!",
            };

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
