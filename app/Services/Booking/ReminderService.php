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

        // No game posted for that day: the admins decide (post the game, and the client is told automatically).
        foreach ($this->messenger->adminContacts() as $admin) {
            $this->messenger->alertAdmin($admin, '🙋 '.($contact->player_name ?: $contact->name ?: 'A client').' (+'.$contact->wa_id.') wants to play on '
                .$day->format('l j M').($players > 1 ? " ({$players} players)" : '').".\nNo game posted for that day yet.\n↩️ Reply to THIS message with your answer and I'll send it to them (e.g. \"Yes we play Monday 9 PM, format confirmed tomorrow\"). When you post the game, I'll tell them too.",
                ['contact_id' => $contact->id, 'kind' => 'client_question']);
        }

        return ['saved' => true, 'day' => $day->format('l j F'),
            'note' => 'Tell the client in one short line that you\'ll check with the team and get back to them (e.g. "Let me check with the team and come back to you 👊"). Do not promise a game, format or pitch.'];
    }

    /**
     * Called right after a game is published. The bot sends nothing to players: it gives the admins
     * a list (people who asked for that day, usually play that day, or played 3+ days ago and haven't
     * been back) plus a ready-to-send invite. The admin messages them from the phone; when they
     * reply, the bot takes over. Returns how many people are on the list.
     */
    public function notifyForGame(Game $game): int
    {
        if (! config('fbl.reminders.enabled', true)) {
            return 0;
        }
        $local = $game->localStart();
        $day = $local->toDateString();

        $recipients = []; // contact_id => reason (first wins: asked > usual_day > same_day > lapsed)
        AvailabilityRequest::where('play_date', $day)->whereNull('notified_at')->pluck('contact_id')
            ->each(function ($id) use (&$recipients) { $recipients[$id] ??= 'asked'; });

        Contact::whereNotNull('preferred_days')->get(['id', 'preferred_days'])
            ->filter(fn (Contact $c) => in_array($local->dayOfWeek, array_map('intval', (array) $c->preferred_days), true))
            ->each(function (Contact $c) use (&$recipients) { $recipients[$c->id] ??= 'usual_day'; });

        // Players whose last game was 3–60 days ago and who have nothing booked since.
        $lookback = (int) config('fbl.reminders.lookback_days', 60);
        $lastGame = Booking::where('bookings.status', 'confirmed')->whereNotNull('contact_id')
            ->join('games', 'games.id', '=', 'bookings.game_id')
            ->where('games.status', '!=', 'cancelled')
            ->where('games.starts_at', '>', now()->subDays($lookback))
            ->groupBy('contact_id')
            ->selectRaw('contact_id, MAX(games.starts_at) as last_at')
            ->get();
        $active = Booking::whereIn('status', ['pending_payment', 'awaiting_approval', 'confirmed'])->whereNotNull('contact_id')
            ->whereHas('game', fn ($q) => $q->where('starts_at', '>', now()))->pluck('contact_id')->all();
        foreach ($lastGame as $row) {
            $last = Carbon::parse($row->last_at, 'UTC');
            if ($last->gt(now()->subDays(3)) || in_array($row->contact_id, $active, true)) {
                continue;
            }
            $recipients[$row->contact_id] ??= $last->copy()->setTimezone(config('fbl.timezone'))->dayOfWeek === $local->dayOfWeek ? 'same_day' : 'lapsed';
        }

        if ($game->format === 'tournament') {
            Contact::whereNotNull('tournament_interest_at')->pluck('id')
                ->each(function ($id) use (&$recipients) { $recipients[$id] = 'tournament'; });
        }

        if (! $recipients) {
            return 0;
        }

        $busy = $this->busyOn($day);
        $listed = 0;
        $contacts = Contact::whereIn('id', array_keys($recipients))->whereNull('reminders_opted_out_at')->get();
        foreach ($contacts as $contact) {
            if ($contact->isAdmin() || in_array($contact->id, $busy, true)) {
                continue;
            }
            try {
                DB::table('game_reminders')->insert([
                    'contact_id' => $contact->id, 'game_id' => $game->id, 'play_date' => $day,
                    'reason' => $recipients[$contact->id], 'status' => 'manual', 'sent' => false,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $listed++;
            } catch (QueryException) {
                continue; // already on a list for that day
            }
            AvailabilityRequest::where('contact_id', $contact->id)->where('play_date', $day)->update(['notified_at' => now()]);
        }

        if ($listed > 0) {
            $this->sendInviteList($game);
        }

        return $listed;
    }

    /**
     * Admin asked "suggest 12" for a game that isn't filling up. Candidates:
     *  - played in the last 3 days, or
     *  - played on the same weekday before (last 60 days),
     *  - plus anyone who asked for that day / usually plays that day.
     * Anyone who chatted with the bot in the last 24h gets the invite straight from the bot
     * (WhatsApp only allows free messages inside that window); everyone else goes on a list
     * for the admin to message from the phone.
     *
     * @return array{direct:int, manual:int, names:string[]}
     */
    public function suggestForGame(Game $game, Contact $admin): array
    {
        $tz = config('fbl.timezone');
        $local = $game->localStart();
        $day = $local->toDateString();

        $played = Booking::where('bookings.status', 'confirmed')
            ->join('games', 'games.id', '=', 'bookings.game_id')
            ->where('games.status', '!=', 'cancelled')
            ->where('games.id', '!=', $game->id)
            ->where('games.starts_at', '<', now())
            ->where('games.starts_at', '>', now()->subDays((int) config('fbl.reminders.lookback_days', 60)))
            ->get(['bookings.contact_id', 'bookings.player_name', 'games.starts_at']);

        $recipients = [];   // contact_id => reason
        $noNumber = [];     // names from your group lists (no phone saved)
        $others = [];       // played in the last weeks, other days: used when the first circle can't fill the game
        foreach ($played as $row) {
            $at = Carbon::parse($row->starts_at, 'UTC');
            $reason = $at->gt(now()->subDays(3)) ? 'recent'
                : ($at->copy()->setTimezone($tz)->dayOfWeek === $local->dayOfWeek ? 'same_day' : null);
            if (! $reason) {
                if ($row->contact_id) {
                    $others[$row->contact_id] = 'lapsed';
                }
                continue;
            }
            if ($row->contact_id) {
                // "recent" beats "same_day"
                if (($recipients[$row->contact_id] ?? null) !== 'recent') {
                    $recipients[$row->contact_id] = $reason;
                }
            } else {
                $noNumber[mb_strtolower(trim($row->player_name))] = trim($row->player_name);
            }
        }
        // Not enough people to fill the free spots (aim for 2 invites per spot): widen to everyone who played lately.
        if (count($recipients) < 2 * max(1, $game->spotsLeft())) {
            $recipients += $others;
        }
        AvailabilityRequest::where('play_date', $day)->pluck('contact_id')
            ->each(function ($id) use (&$recipients) { $recipients[$id] = 'asked'; });
        if ($game->format === 'tournament') {
            Contact::whereNotNull('tournament_interest_at')->pluck('id')
                ->each(function ($id) use (&$recipients) { $recipients[$id] = 'tournament'; });
        }
        Contact::whereNotNull('preferred_days')->get(['id', 'preferred_days'])
            ->filter(fn (Contact $c) => in_array($local->dayOfWeek, array_map('intval', (array) $c->preferred_days), true))
            ->each(function (Contact $c) use (&$recipients) { $recipients[$c->id] ??= 'usual_day'; });

        // Not the people already in that day, and not names already on this game's list.
        $busy = $this->busyOn($day);
        $inGame = $game->bookings()->whereIn('status', ['pending_payment', 'awaiting_approval', 'confirmed'])
            ->pluck('player_name')->map(fn ($n) => mb_strtolower(trim((string) $n)))->all();
        foreach ($inGame as $n) {
            unset($noNumber[$n]);
        }

        $direct = 0;
        $manual = 0;
        $contacts = Contact::whereIn('id', array_keys($recipients))->whereNull('reminders_opted_out_at')->get();
        foreach ($contacts as $contact) {
            if ($contact->isAdmin() || in_array($contact->id, $busy, true)) {
                continue;
            }

            $row = DB::table('game_reminders')->where('contact_id', $contact->id)->where('play_date', $day)->first();
            if ($row && $row->status === 'sent') {
                continue; // already invited for that day
            }

            $sent = false; // the admin confirms first ("Send invites" button), then sendInvites() messages them
            $values = ['game_id' => $game->id, 'reason' => $recipients[$contact->id], 'status' => $sent ? 'sent' : 'manual',
                'sent' => $sent, 'updated_at' => now()];
            $row
                ? DB::table('game_reminders')->where('id', $row->id)->update($values)
                : DB::table('game_reminders')->insert($values + ['contact_id' => $contact->id, 'play_date' => $day, 'created_at' => now()]);
            $sent ? $direct++ : $manual++;
        }

        $this->sendInviteList($game, $admin, null, array_values($noNumber));

        return ['direct' => $direct, 'manual' => $manual, 'names' => array_values($noNumber)];
    }

    private function inviteText(Game $game, ?Contact $contact): string
    {
        $first = trim((string) ($contact?->player_name ?: $contact?->name));
        $first = $first !== '' ? ' '.(preg_split('/\s+/u', $first)[0] ?? '') : '';
        $local = $game->localStart();
        $when = $local->isToday() ? 'tonight' : ($local->isTomorrow() ? 'tomorrow' : $local->format('l'));

        return "Hey{$first} 👋 we've got a ".($game->format ? $game->format.' ' : '')."game {$when} at {$local->format('g:i A')}, {$game->venue}. "
            .'Spots still open, want in? ⚽';
    }

    /** The list + a message the admin can copy and send from the phone. */
    public function sendInviteList(Game $game, ?Contact $only = null, ?int $directNow = null, array $namesWithoutNumber = []): bool
    {
        $rows = DB::table('game_reminders')->where('game_id', $game->id)->whereIn('status', ['manual', 'sent'])->get();
        $rank = ['tournament' => 0, 'asked' => 0, 'usual_day' => 1, 'recent' => 2, 'same_day' => 3, 'lapsed' => 4];
        $rows = $rows->sortBy(fn ($r) => [$r->status === 'sent' ? 1 : 0, $rank[$r->reason] ?? 9, $r->id])->values();
        $extra = $namesWithoutNumber ? "\n\nAlso played recently (from your group lists, no number saved): ".implode(', ', $namesWithoutNumber) : '';
        if ($rows->isEmpty()) {
            if ($only) {
                $this->messenger->alertAdmin($only, "Nobody to invite for #{$game->id} right now.".$extra);
            }

            return false;
        }

        $why = ['tournament' => 'asked about tournaments', 'asked' => 'asked for this day', 'usual_day' => 'usually free this day', 'recent' => 'played in the last 3 days', 'same_day' => 'played this weekday before', 'lapsed' => 'played in the last weeks'];
        $lines = $rows->map(function ($r, $i) use ($why) {
            $c = Contact::find($r->contact_id);

            // 🤖 = the bot can message them (they wrote to us in the last 24h), 📱 = only from your phone.
            $mark = $r->status === 'sent' ? '✅' : ($c && $c->windowIsOpen() && ! $c->botIsPaused() ? '🤖' : '📱');

            return "{$mark} ".($i + 1).'. '.($c?->player_name ?: $c?->name ?: 'Player').' · +'.($c?->wa_id ?? '?').' · '.($why[$r->reason] ?? $r->reason);
        })->implode("\n");

        $pending = $rows->where('status', 'manual')->count();
        $botCan = $rows->where('status', 'manual')->filter(function ($r) {
            $c = Contact::find($r->contact_id);

            return $c && $c->windowIsOpen() && ! $c->botIsPaused();
        })->count();
        $ok = false;
        foreach ($only ? [$only] : $this->messenger->adminContacts() as $admin) {
            $ok = $this->messenger->alertAdmin($admin,
                "📣 Players to invite for #{$game->id} ({$game->label()} · {$game->spotsLeft()} left):\n\n{$lines}\n\n"
                .($pending ? "🤖 I can message {$botCan} · 📱 {$this->plural($pending - $botCan)} only from your phone.\n💬 \"{$this->inviteText($game, null)}\"" : 'Everyone on the list has been messaged ✅')
                .$extra) || $ok;
            if ($pending && $admin->windowIsOpen()) {
                $this->messenger->buttons($admin, "Who invites them for #{$game->id}?",
                    ['inv:'.$game->id => '🤖 Bot invites', 'invno:'.$game->id => '👤 I\'ll invite']);
            } elseif ($pending) {
                $this->messenger->alertAdmin($admin, "Reply: bot invites {$game->id} · or invite them yourself");
            }
        }

        return $ok;
    }

    private function plural(int $n): string
    {
        return $n === 1 ? '1 player' : "{$n} players";
    }

    /** Admin chose to invite them himself: the list to copy, phone by phone. */
    public function adminInvites(Game $game): string
    {
        $rows = DB::table('game_reminders')->where('game_id', $game->id)->where('status', 'manual')->get();
        $lines = $rows->map(fn ($r) => Contact::find($r->contact_id))->filter()
            ->map(fn (Contact $c) => '• '.($c->player_name ?: $c->name ?: 'Player').' · wa.me/'.$c->wa_id)->implode("\n");

        return $lines === ''
            ? 'Nobody left to invite.'
            : "👤 OK, over to you. Tap a number to open the chat and send:\n💬 \"{$this->inviteText($game, null)}\"\n\n{$lines}\n\nWhen they reply, I take over and book them.";
    }

    /**
     * Admin tapped "Send invites": message everyone still on the list. WhatsApp only allows a free message to people
     * who wrote to us in the last 24h; the others come back as a list for the admin to text from the phone.
     *
     * @return string reply for the admin
     */
    public function sendInvites(Game $game, Contact $admin): string
    {
        if ($game->status !== 'published' || $game->starts_at->isPast()) {
            return "#{$game->id} isn't open anymore, nothing sent.";
        }
        $day = $game->localStart()->toDateString();
        $busy = $this->busyOn($day);
        $sent = [];
        $phone = [];
        foreach (DB::table('game_reminders')->where('game_id', $game->id)->where('status', 'manual')->get() as $row) {
            $c = Contact::find($row->contact_id);
            if (! $c || $c->reminders_opted_out_at || in_array($c->id, $busy, true)) {
                continue;
            }
            $name = $c->player_name ?: $c->name ?: '+'.$c->wa_id;
            if ($c->windowIsOpen() && ! $c->botIsPaused() && $this->messenger->text($c, $this->inviteText($game, $c))) {
                DB::table('game_reminders')->where('id', $row->id)->update(['status' => 'sent', 'sent' => true, 'updated_at' => now()]);
                $sent[] = $name;
            } else {
                $phone[] = "{$name} · +{$c->wa_id}";
            }
        }

        return ($sent ? '📨 Invited '.count($sent).': '.implode(', ', $sent).'.' : '📨 Nobody I could message directly.')
            .($phone ? "\n\n📱 WhatsApp doesn't let me message these (no chat with us in the last 24h). Send them the invite from your phone:\n"
                .implode("\n", $phone)."\n\n💬 {$this->inviteText($game, null)}" : '')
            .($sent ? "\nWhen they reply, I take over and book them." : '');
    }

    /**
     * The admin messaged someone from the invite list from the phone: that's an invite, not the team
     * taking over, so the bot keeps answering. Marks them as messaged.
     */
    public function isInviteEcho(Contact $contact): bool
    {
        $row = DB::table('game_reminders')
            ->join('games', 'games.id', '=', 'game_reminders.game_id')
            ->where('game_reminders.contact_id', $contact->id)
            ->whereIn('game_reminders.status', ['manual', 'sent'])
            ->where('games.starts_at', '>', now())
            ->where('game_reminders.created_at', '>', now()->subDays(7))
            ->select('game_reminders.id', 'game_reminders.status')
            ->first();
        if (! $row) {
            return false;
        }
        if ($row->status === 'manual') {
            DB::table('game_reminders')->where('id', $row->id)->update(['status' => 'sent', 'sent' => true, 'updated_at' => now()]);

            return true;
        }

        // Already messaged once: a second message from the phone is the team really talking.
        return false;
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
