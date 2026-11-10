<?php

namespace App\Services\Booking;

use App\Models\Booking;
use App\Models\Contact;
use App\Models\Game;
use App\Services\Ai\ScheduleParser;
use App\Services\WhatsApp\InboundMessage;
use App\Services\WhatsApp\Messenger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Everything an admin can do by messaging the bot number. */
class AdminHandler
{
    public const HELP = "FootballLab bot · admin commands\n"
        ."• bot off / bot on / bot status → pause or restart the bot for all clients\n"
        ."• After you publish, I send who to invite (asked for that day / usual day / no game in 3+ days) . You text them from the phone; I take over when they reply. Again anytime: invite N\n"
        ."• players → the player sheet (CSV for Excel)\n"
        ."• Spot frees up in a full game → I ask you to offer it to the next on the waiting list (or: offer N / skip N)\n"
        ."• Forward your group post (or start with #games) → I read the games + names, you tap Publish. Forward an updated list anytime to sync names.\n"
        ."• games → upcoming games + spots\n"
        ."• list 12 → game 12's list, ready to paste in the group\n"
        ."• who 12 → same with phone numbers + booking refs\n"
        ."• add 12 Omar (or: add 12 Omar 2) → player who joined in the group\n"
        ."• remove 12 Omar (or: remove 12 FBL-XXXXX)\n"
        ."• copy 12 22:30 → second game like #12 at 22:30 (same day), waitlist gets told\n"
        ."• copy 12 2026-11-07 21:00 → same, another day\n"
        ."• pending → bookings waiting for your OK\n"
        ."• Payment link: reply to my 💳 request with the link (or send FBL-XXXXX https://…)\n"
        ."• confirm FBL-XXXXX / reject FBL-XXXXX\n"
        ."• suggest 12 → game not filling up? I invite people who played in the last 3 days or on the same weekday (myself if they chatted in the last 24h, otherwise I list them for you)\n"
        ."• chats → conversations waiting for you (I remind you after 15 min and 1 h until you reply)\n"
        ."• cancel game 12 → stop bookings for a game\n"
        ."• edit 12 format 8v8 / edit 12 price 60 / edit 12 spots 16 → change a game (format also opens a weekly game)\n"
        ."• pause 9715… / resume 9715… → bot off/on for a client\n"
        ."• Old clients: share their contact cards (or a .vcf) with me, or send saved 9715… → the bot never talks to them (unsave 9715… to undo)\n"
        ."• #test → chat with the bot as a client (send #test again to exit)";

    public function __construct(
        private readonly BookingService $bookings,
        private readonly ApprovalNotifier $notifier,
        private readonly ScheduleParser $parser,
        private readonly Messenger $messenger,
        private readonly RosterService $roster,
        private readonly \App\Services\Payments\PaymentService $payments,
    ) {}

    public function handle(Contact $admin, InboundMessage $inbound): void
    {
        if ($inbound->isButton()) {
            $this->handleButton($admin, (string) $inbound->buttonId);

            return;
        }

        // Contact cards or a contacts file (.vcf) from the admin: those people are old clients, the bot leaves them alone.
        if ($inbound->type === 'contacts' || $this->isVcf($inbound)) {
            $this->importSavedContacts($admin, $inbound);

            return;
        }

        $text = trim((string) $inbound->text);
        $lower = mb_strtolower($text);

        if (preg_match('/^bot\s+(off|stop|on|start|status)$/i', $text, $m)) {
            $this->botSwitch($admin, mb_strtolower($m[1]));

            return;
        }

        if (str_starts_with($lower, '#test')) {
            $admin->update(['act_as_client' => ! $admin->act_as_client]);
            $this->reply($admin, $admin->act_as_client
                ? '🧪 Test mode ON. You are now a client. Send "#test" to go back to admin mode.'
                : '🛠 Test mode OFF. Back to admin mode.');

            return;
        }

        if (! $inbound->forwarded && preg_match('#https?://\S+#i', $text, $urlMatch) && $this->handlePaymentLink($admin, $inbound, $text, $urlMatch[0])) {
            return;
        }

        if ($inbound->forwarded || str_starts_with($lower, '#games') || str_starts_with($lower, '#schedule')) {
            $this->importSchedule($admin, preg_replace('/^#(games|schedule)\s*/i', '', $text) ?? $text);

            return;
        }

        if (preg_match('/^(confirm|approve|reject)\s+(fbl-[a-z0-9]{5})$/i', $text, $m)) {
            $booking = Booking::findByReference($m[2]);
            $booking
                ? $this->decide($admin, $booking->id, mb_strtolower($m[1]) !== 'reject')
                : $this->reply($admin, 'No booking '.Str::upper($m[2]).'.');

            return;
        }

        if (preg_match('/^suggest\s+(\d+)$/i', $text, $m)) {
            $game = Game::find((int) $m[1]);
            if (! $game || $game->status !== 'published' || $game->starts_at->isPast()) {
                $this->reply($admin, "No open upcoming game #{$m[1]}.");
            } else {
                app(\App\Services\Booking\ReminderService::class)->suggestForGame($game, $admin);
            }

            return;
        }

        if (preg_match('/^invite\s+(\d+)$/i', $text, $m)) {
            $game = Game::find((int) $m[1]);
            $game ? app(\App\Services\Booking\ReminderService::class)->sendInviteList($game, $admin) : $this->reply($admin, "No game #{$m[1]}.");

            return;
        }

        if (preg_match('/^(players|sheet)$/i', $text)) {
            $csv = app(\App\Services\Booking\ReminderService::class)->playersCsv();
            $rows = max(0, substr_count($csv, "\n") - 1);
            $ok = $this->messenger->file($admin, $csv, 'footballlab-players-'.now(config('fbl.timezone'))->format('Y-m-d').'.csv', 'text/csv',
                "📇 {$rows} players. Opens in Excel / Google Sheets.");
            if (! $ok) {
                $this->reply($admin, "Couldn't send the sheet right now. Try again in a minute.");
            }

            return;
        }

        if (preg_match('/^(offer|skip)\s+(\d+)$/i', $text, $m)) {
            $this->waitlistAnswer($admin, (int) $m[2], mb_strtolower($m[1]) === 'offer');

            return;
        }

        if (preg_match('/^list\s+(\d+)$/i', $text, $m)) {
            $game = Game::find((int) $m[1]);
            $this->reply($admin, $game ? $this->roster->groupText($game) : "No game #{$m[1]}.");

            return;
        }

        if (preg_match('/^who\s+(\d+)$/i', $text, $m)) {
            $this->listPlayers($admin, (int) $m[1]);

            return;
        }

        if (preg_match('/^add\s+(\d+)\s+(.+?)(?:\s+(\d{1,2}))?$/iu', $text, $m)) {
            $this->addPlayer($admin, (int) $m[1], trim($m[2]), isset($m[3]) && $m[3] !== '' ? (int) $m[3] : 1);

            return;
        }

        if (preg_match('/^remove\s+(\d+)\s+(.+)$/iu', $text, $m)) {
            $removed = $this->bookings->removeManual((int) $m[1], trim($m[2]));
            if (! $removed) {
                $this->reply($admin, "Couldn't find \"{$m[2]}\" in game #{$m[1]}. Send \"who {$m[1]}\" to see names.");
            } else {
                $this->reply($admin, "Removed {$removed->player_name} ×{$removed->players} from game #{$m[1]}."
                    .($removed->source === 'bot' ? ' ⚠️ They paid through the bot: message them about a refund.' : ''));
                $this->roster->shareUpdated($removed->game, "{$removed->player_name} removed");
                $this->roster->offerFreedSpots($removed->game);
            }

            return;
        }

        if (preg_match('/^copy\s+(\d+)\s+(?:(\d{4}-\d{2}-\d{2})\s+)?(\d{1,2}[:.]\d{2})$/i', $text, $m)) {
            $this->copyGame($admin, (int) $m[1], $m[2] !== '' ? $m[2] : null, str_replace('.', ':', $m[3]));

            return;
        }

        if (preg_match('/^edit\s+(\d+)\s+(format|price|spots)\s+(\S+)$/i', $text, $m)) {
            $this->editGame($admin, (int) $m[1], mb_strtolower($m[2]), $m[3]);

            return;
        }

        if (preg_match('/^cancel\s+game\s+(\d+)$/i', $text, $m)) {
            $this->cancelGame($admin, (int) $m[1]);

            return;
        }

        if (preg_match('/^(pause|resume)\s+\+?(\d{8,15})$/i', $text, $m)) {
            $client = Contact::firstOrCreate(['wa_id' => $m[2]]);
            $pause = mb_strtolower($m[1]) === 'pause';
            $client->update(['bot_paused_until' => $pause ? now()->addHours((int) config('fbl.human_takeover_hours', 12)) : null] + ($pause ? [] : ['handoff_at' => null]));
            $this->reply($admin, $pause ? "Bot paused for +{$m[2]}." : "Bot active again for +{$m[2]}.");

            return;
        }

        if (preg_match('/^(saved|known|unsave|unsaved|forget)\s+\+?([\d\s]{8,20})$/i', $text, $m)) {
            $wa = \App\Support\Phone::normalize($m[2]);
            $client = Contact::firstOrCreate(['wa_id' => $wa]);
            $save = in_array(mb_strtolower($m[1]), ['saved', 'known'], true);
            $client->update(['saved_at' => $save ? now() : null] + ($save ? ['handoff_at' => null] : []));
            $this->reply($admin, $save
                ? "📇 +{$wa} marked as a saved client: the bot won't reply to them or message them."
                : "🤖 +{$wa} is handled by the bot again.");

            return;
        }

        if (preg_match('/^(old|new)\s+\+?([\d\s]{8,20})$/i', $text, $m)) {
            $this->setClientType($admin, Contact::firstOrCreate(['wa_id' => \App\Support\Phone::normalize($m[2])]), mb_strtolower($m[1]));

            return;
        }

        if ($lower === 'saved') {
            $this->reply($admin, '📇 '.Contact::whereNotNull('saved_at')->count().' saved clients the bot leaves alone. Send contact cards or a .vcf file to add more.');

            return;
        }

        if ($lower === 'chats') {
            $open = Contact::whereNotNull('handoff_at')->orderBy('handoff_at')->get();
            $this->reply($admin, $open->isEmpty() ? 'No chats waiting for you 👍' : "🙋 Chats waiting for you:\n".$open->map(fn (Contact $c) =>
                "• {$c->displayName()} (+{$c->wa_id}) · {$c->handoff_reason} · ".$c->handoff_at->diffForHumans())->implode("\n"));

            return;
        }

        match ($lower) {
            'games' => $this->listGames($admin),
            'pending' => $this->listPending($admin),
            default => $this->reply($admin, self::HELP),
        };
    }

    /** Admin replies with a payment link: to the bot's request (quoted), with the reference, or the only open request. */
    private function handlePaymentLink(Contact $admin, InboundMessage $inbound, string $text, string $url): bool
    {
        $booking = null;

        if ($inbound->replyToId) {
            $quoted = \App\Models\Message::where('wa_message_id', $inbound->replyToId)->first();
            $bookingId = $quoted?->payload['booking_id'] ?? null;
            $booking = $bookingId ? Booking::find($bookingId) : null;
        }
        if (! $booking && preg_match('/\bfbl-[a-z0-9]{5}\b/i', $text, $m)) {
            $booking = Booking::findByReference($m[0]);
        }
        if (! $booking) {
            $waiting = Booking::where('status', 'pending_payment')->whereNotNull('link_requested_at')->whereNull('payment_url')->get();
            if ($waiting->count() === 1) {
                $booking = $waiting->first();
            } elseif ($waiting->count() > 1) {
                $this->reply($admin, 'Which booking is this link for? Reply to my request message, or send: '
                    .$waiting->pluck('reference')->implode(' / ').' followed by the link.');

                return true;
            } else {
                return false; // not a payment link (e.g. a schedule with a link): handle normally
            }
        }

        $this->reply($admin, $this->payments->attachLink($booking, rtrim($url, '.,)'), $admin));

        return true;
    }

    private function botSwitch(Contact $admin, string $action): void
    {
        $others = array_filter($this->messenger->adminContacts(), fn (Contact $a) => $a->wa_id !== $admin->wa_id);

        if (in_array($action, ['off', 'stop'], true)) {
            \App\Services\BotSwitch::turnOff($admin->wa_id);
            $this->reply($admin, "⏸ Bot is OFF. Clients get no automatic replies and no timed messages; answer them from the WhatsApp app.\n"
                .'Admin commands still work. Send "bot on" to restart.');
            foreach ($others as $o) {
                $this->messenger->alertAdmin($o, "⏸ Bot turned OFF by {$admin->adminName()}. Clients get no automatic replies until someone sends \"bot on\".");
            }

            return;
        }

        if (in_array($action, ['on', 'start'], true)) {
            \App\Services\BotSwitch::turnOn();
            $this->reply($admin, '▶️ Bot is ON. It answers new client messages again (messages sent while it was off are not answered automatically).');
            foreach ($others as $o) {
                $this->messenger->alertAdmin($o, "▶️ Bot turned ON by {$admin->adminName()}.");
            }

            return;
        }

        $info = \App\Services\BotSwitch::offInfo();
        $this->reply($admin, $info
            ? '⏸ Bot is OFF since '.\Carbon\Carbon::parse($info['at'])->setTimezone(config('fbl.timezone'))->format('D j M, g:i A')
                .' (by '.(Contact::where('wa_id', $info['by'])->first()?->adminName() ?? '+'.$info['by']).'). Send "bot on" to restart.'
            : '▶️ Bot is ON and answering clients. Send "bot off" to pause it.');
    }

    private function pickFormat(Contact $admin, string $ref): void
    {
        [$gameId, $format] = array_pad(explode(':', $ref, 2), 2, '');
        $game = Game::find((int) $gameId);
        if (! $game || $game->status === 'cancelled') {
            $this->reply($admin, 'That game no longer exists.');

            return;
        }
        if ($game->format && $game->status === 'published') {
            $this->reply($admin, "#{$game->id} is already set to {$game->format}. To change it: edit {$game->id} format …");

            return;
        }

        $this->reply($admin, app(WeeklySchedule::class)->setFormat($game, $format, $admin));
    }

    private function editGame(Contact $admin, int $gameId, string $field, string $value): void
    {
        $game = Game::find($gameId);
        if (! $game || $game->status === 'cancelled') {
            $this->reply($admin, "No open game #{$gameId}.");

            return;
        }

        if ($field === 'format') {
            $this->reply($admin, app(WeeklySchedule::class)->setFormat($game, $value, $admin));

            return;
        }

        $number = (float) str_replace(',', '.', $value);
        if ($number <= 0) {
            $this->reply($admin, 'Use a number, e.g. edit '.$gameId.' '.$field.' '.($field === 'price' ? '60' : '16'));

            return;
        }

        if ($field === 'spots') {
            $taken = $game->spotsTaken();
            if ((int) $number < $game->confirmedPlayers()) {
                $this->reply($admin, "#{$game->id} already has {$game->confirmedPlayers()} confirmed players. Can't go below that.");

                return;
            }
            $game->update(['capacity' => (int) $number]);
            if ((int) $number > $taken) {
                $this->roster->offerFreedSpots($game->fresh());
            }
        } else {
            $game->update(['price' => $number]);
        }

        $g = $game->fresh();
        $this->reply($admin, "✅ #{$g->id} {$g->label()} · ".(float) $g->price.' '.config('fbl.currency')." · {$g->capacity} spots · {$g->spotsLeft()} left"
            .($field === 'price' ? ' (new bookings only; existing ones keep their amount)' : ''));
    }

    private function handleButton(Contact $admin, string $id): void
    {
        [$action, $ref] = array_pad(explode(':', $id, 2), 2, '');

        match ($action) {
            'approve' => $this->decide($admin, (int) $ref, true),
            'reject' => $this->decide($admin, (int) $ref, false),
            'publish' => $this->publishBatch($admin, $ref, true),
            'discard' => $this->publishBatch($admin, $ref, false),
            'wl_send' => $this->waitlistAnswer($admin, (int) $ref, true),
            'wl_skip' => $this->waitlistAnswer($admin, (int) $ref, false),
            'fmt' => $this->pickFormat($admin, $ref),
            'who' => $this->setClientType($admin, Contact::find((int) strtok($ref, ':')), (string) substr((string) strrchr($ref, ':'), 1)),
            default => $this->reply($admin, self::HELP),
        };
    }

    private function payFirstInstead(Contact $admin, Booking $booking): void
    {
        $moved = Booking::whereKey($booking->id)->where('status', 'awaiting_approval')->update([
            'status' => 'pending_payment', 'payment_note' => null, 'payment_reported_at' => null, 'link_requested_at' => null, 'created_at' => now(),
        ]);
        if (! $moved) {
            $this->notifier->alreadyDecided($admin, $booking->fresh());

            return;
        }
        $b = $booking->fresh(['game', 'contact']);
        $payments = app(\App\Services\Payments\PaymentService::class);
        $link = $payments->linkFor($b); // admin_link mode: asks you for the link (reply to that message)

        if ($b->contact) {
            // The client always hears back: the first confirmation goes through the payment link.
            $minutes = (int) config('fbl.payment_timeout_minutes', 120);
            $due = $b->fresh()->link_due_at;
            $text = $link
                ? "First time confirmation needs to go through the link 👊\n💰 {$b->amountLabel()}: {$link}\nSend the screenshot after paying (spot held {$minutes} min)"
                : ($due && $due->isFuture()
                    ? 'First time confirmation needs to go through the link 👊 Your spot is saved, I\'ll send you the link the day before the game.'
                    : 'First time confirmation needs to go through the link 👊 I\'ll send it to you shortly to confirm your spot.');
            $this->messenger->updateClient($b->contact, $b->reference, $text);
        }
        foreach ($this->messenger->adminContacts() as $other) {
            if ($other->wa_id !== $admin->wa_id) {
                $this->messenger->alertAdmin($other, "{$b->reference}: {$admin->adminName()} chose pay-first for {$b->player_name}.");
            }
        }
        $this->reply($admin, $link
            ? "💳 {$b->player_name} got the payment link for {$b->reference}."
            : "💳 OK, {$b->player_name} pays first. Reply to the link request above with the payment link.");
    }

    private function waitlistAnswer(Contact $admin, int $entryId, bool $send): void
    {
        $entry = \App\Models\WaitlistEntry::with(['contact', 'game'])->find($entryId);
        if (! $entry) {
            $this->reply($admin, 'That waiting-list entry is gone (they probably booked already).');

            return;
        }
        if ($send) {
            $this->reply($admin, $this->roster->sendWaitlistOffer($entry, $admin));

            return;
        }
        if (! $entry->skipped_at && ! $entry->offer_sent_at) {
            $entry->update(['skipped_at' => now()]);
        }
        $this->reply($admin, '⏭ Skipped '.($entry->contact?->name ?: 'them').'.');
        $this->roster->offerFreedSpots($entry->game); // ask about the next person
    }

    private function decide(Contact $admin, int $bookingId, bool $approve): void
    {
        // "Pay later" refused: not a rejection, they just pay first like everyone else.
        $pending = Booking::with(['game', 'contact'])->find($bookingId);
        if (! $approve && $pending && $pending->status === 'awaiting_approval' && $pending->wantsCash()
            && ! $pending->payment_proof_media_id && ! $pending->payment_verified) {
            $this->payFirstInstead($admin, $pending);

            return;
        }

        $outcome = $this->bookings->decide($bookingId, $admin, $approve);
        if (! $approve && $outcome['result'] === 'done' && isset($outcome['booking'])) {
            $this->roster->offerFreedSpots($outcome['booking']->game);
        }

        if ($outcome['result'] === 'missing') {
            $this->reply($admin, 'That booking no longer exists.');
        } elseif ($outcome['result'] === 'already') {
            $this->notifier->alreadyDecided($admin, $outcome['booking']);
        } elseif ($outcome['result'] === 'full') {
            $g = $outcome['game'];
            $this->reply($admin, "⚠️ Can't confirm {$outcome['booking']->reference}: game #{$g->id} would go over {$g->capacity} players. "
                ."Reject it (and refund), or free a spot first. Send \"who {$g->id}\" to see who's in.");
        }
        // 'done': ApprovalNotifier already messaged the client and all admins.
    }

    private function importSchedule(Contact $admin, string $post): void
    {
        if (mb_strlen($post) < 10) {
            $this->reply($admin, 'Send the full game announcement (forward it from the group, or start with #games).');

            return;
        }

        try {
            $games = $this->parser->parse($post);
        } catch (\Throwable $e) {
            Log::error('Schedule parse failed', ['error' => $e->getMessage()]);
            $this->reply($admin, "I couldn't read that post right now. Try again in a minute.");

            return;
        }

        if (! $games) {
            $this->reply($admin, "I didn't find any upcoming games in that post. Include day, time and venue.");

            return;
        }

        $batch = (string) Str::uuid();
        $lines = [];
        $updated = [];
        $freed = [];
        $single = count($games) === 1; // your post is reused as the list layout only when it's about one game

        // A post with several games (Game 1 / Game 2 lists): remember which list belongs to which game,
        // matching names first, then the order of the games in the post.
        $group = $single ? null : (string) Str::uuid();
        $parts = [];
        if (! $single) {
            $blockNames = RosterService::blockNames($post);
            $free = array_keys($blockNames);
            foreach ($games as $k => $g) {
                $mine = array_map(fn ($n) => mb_strtolower(trim((string) $n)), (array) ($g['players'] ?? []));
                $best = null;
                $bestScore = 0;
                foreach ($free as $b) {
                    $score = count(array_intersect($mine, $blockNames[$b]));
                    if ($score > $bestScore) {
                        [$best, $bestScore] = [$b, $score];
                    }
                }
                $parts[$k] = $best;
                if ($best !== null) {
                    $free = array_values(array_diff($free, [$best]));
                }
            }
            foreach ($games as $k => $g) {
                $parts[$k] ??= array_shift($free);
            }
        }

        DB::transaction(function () use ($games, $batch, $post, $single, $admin, $group, $parts, &$lines, &$updated, &$freed) {
            $usedExisting = [];
            foreach ($games as $k => $g) {
                $players = $g['players'] ?? [];
                $priceStated = (bool) ($g['price_stated'] ?? false);
                unset($g['players'], $g['price_stated']);
                // No price written in the post: use the price for that format (8v8 = 53, 11v11 = 58…).
                if (! $priceStated && ! empty($g['format'])) {
                    $g['price'] = (float) app(\App\Services\Booking\WeeklySchedule::class)->terms($g['format'], $g['venue'])['price'];
                }
                $listMeta = $single ? [] : ['source_text' => $post, 'list_group' => $group, 'list_part' => $parts[$k] ?? null];

                // Same game already live? Then this post is an updated list: sync it instead of creating a duplicate.
                // Two games at the same time and place (Game 1 / Game 2): each list goes to its own game.
                $candidates = Game::where('starts_at', $g['starts_at'])
                    ->where(fn ($q) => $q->where('status', 'published')
                        ->orWhere(fn ($q) => $q->where('status', 'draft')->whereNotNull('weekly_key')))
                    ->orderBy('id')
                    ->get()
                    ->filter(fn (Game $e) => $this->sameVenue($e->venue, $g['venue']))
                    ->values();
                $free = $candidates->reject(fn (Game $e) => in_array($e->id, $usedExisting, true));
                $part = $parts[$k] ?? null;
                $byPosition = $part !== null ? $candidates->get($part) : null; // Game 1 = first added, Game 2 = second…
                $existing = $free->first(fn (Game $e) => $e->list_part !== null && $e->list_part === $part)
                    ?? ($byPosition && ! in_array($byPosition->id, $usedExisting, true) ? $byPosition : null)
                    ?? $free->first();
                if ($existing) {
                    $usedExisting[] = $existing->id;
                }

                if ($existing) {
                    // A weekly game still waiting for its format: your post decides it and opens it.
                    // Your latest post wins: format and price follow it even if the game was already set
                    // (e.g. you answered 11v11 yesterday, today's post says 8v8).
                    $publishWeekly = $existing->status === 'draft';
                    $formatChanged = ! empty($g['format']) && $g['format'] !== $existing->format;
                    $existing->update($listMeta + array_filter([
                        'capacity' => $g['capacity'],
                        'source_text' => $single ? $post : $existing->source_text,
                        'format' => $publishWeekly || $formatChanged ? ($g['format'] ?? null) : null,
                        'price' => $publishWeekly || $formatChanged || $priceStated ? $g['price'] : null,
                        'status' => $publishWeekly ? 'published' : null,
                    ], fn ($v) => $v !== null));
                    [$add, $rem] = $this->bookings->syncPostPlayers($existing, $players, $admin);
                    if ($rem > 0 || $g['capacity'] > $existing->getOriginal('capacity')) {
                        $freed[] = $existing;
                    }
                    $fresh = $existing->fresh();
                    $updated[] = "#{$fresh->id} {$fresh->label()} · {$fresh->confirmedPlayers()}/{$fresh->capacity} confirmed · {$fresh->spotsLeft()} left"
                        .($add || $rem ? " (+{$add} / -{$rem} from your list)" : '');

                    continue;
                }

                // Game 2 next to a game that's already live (same time and pitch): open it straight away.
                $twinLive = $candidates->contains(fn (Game $e) => $e->status === 'published');
                $game = Game::create($listMeta + $g + ['status' => $twinLive ? 'published' : 'draft', 'batch_id' => $batch, 'source_text' => $single ? $post : null]);
                $this->bookings->syncPostPlayers($game, $players, $admin);
                if ($twinLive) {
                    $fresh = $game->fresh();
                    $updated[] = "#{$fresh->id} {$fresh->label()} · new, open for booking · {$fresh->confirmedPlayers()}/{$fresh->capacity} · {$fresh->spotsLeft()} left";

                    continue;
                }
                $lines[] = "#{$game->id} {$game->label()} · ".(float) $game->price.' '.config('fbl.currency')
                    ." · {$game->confirmedPlayers()}/{$game->capacity} already in · {$game->spotsLeft()} left";
            }
        });

        if ($updated) {
            $this->reply($admin, "🔄 Updated from your list:\n".implode("\n", $updated));
        }
        foreach ($freed as $game) {
            $this->roster->offerFreedSpots($game);
        }
        if ($lines) {
            $this->messenger->buttons(
                $admin,
                'I found '.count($lines)." new game(s):\n".implode("\n", $lines)."\n\nPublish so the bot can take bookings?",
                ['publish:'.$batch => '✅ Publish', 'discard:'.$batch => '🗑 Discard']
            );
        }
    }

    /** Admin says who an unknown number is; the bot then answers them (if it hasn't already). */
    private function setClientType(Contact $admin, ?Contact $client, string $type): void
    {
        if (! $client || ! in_array($type, ['old', 'new'], true)) {
            $this->reply($admin, 'That contact is gone.');

            return;
        }
        $already = in_array($client->client_type, ['old', 'new'], true);
        $client->update(['client_type' => $type]);
        $who = $client->displayName().' (+'.$client->wa_id.')';

        foreach ($this->messenger->adminContacts() as $other) {
            if ($other->wa_id !== $admin->wa_id) {
                $this->messenger->alertAdmin($other, "{$admin->adminName()}: {$who} is ".($type === 'old' ? 'an old client' : 'a new player').'.');
            }
        }

        // Their last message is still unanswered (and nobody replied from the phone): answer it now.
        $last = $client->messages()->latest('id')->first();
        $answerNow = $last && $last->direction === 'in' && ! $client->fresh()->botIsPaused();
        $this->reply($admin, ($type === 'old' ? "✅ {$who}: old client. " : "🆕 {$who}: new player. ")
            .($answerNow ? 'Answering them now.' : ($already ? 'Updated.' : 'Noted.')));
        if ($answerNow) {
            app(ClientHandler::class)->replyNow($client->fresh(), $last);
        }
    }

    private function isVcf(InboundMessage $inbound): bool
    {
        if ($inbound->type !== 'document') {
            return false;
        }
        $name = mb_strtolower((string) ($inbound->raw['document']['filename'] ?? ''));

        return str_ends_with($name, '.vcf') || in_array($inbound->mediaMime, ['text/vcard', 'text/x-vcard', 'text/directory'], true);
    }

    /** Mark every number in the shared contact cards / .vcf as a saved client. */
    private function importSavedContacts(Contact $admin, InboundMessage $inbound): void
    {
        $numbers = [];
        if ($inbound->type === 'contacts') {
            foreach ((array) ($inbound->raw['contacts'] ?? []) as $card) {
                foreach ((array) ($card['phones'] ?? []) as $phone) {
                    $numbers[] = (string) ($phone['wa_id'] ?? $phone['phone'] ?? '');
                }
            }
        } else {
            $vcf = app(\App\Services\WhatsApp\WhatsAppClient::class)->downloadMedia($inbound->mediaId, $inbound->mediaLink);
            if ($vcf === null) {
                $this->reply($admin, "I couldn't open that contacts file. Try again, or share the contacts as contact cards.");

                return;
            }
            $numbers = self::vcfNumbers($vcf);
        }

        $admins = array_map(fn (Contact $a) => $a->wa_id, $this->messenger->adminContacts());
        $saved = 0;
        foreach (array_unique(array_filter(array_map(fn ($n) => \App\Support\Phone::normalize($n), $numbers))) as $wa) {
            if (strlen($wa) < 8 || in_array($wa, $admins, true) || $wa === ltrim((string) config('whatsapp.business_number'), '+')) {
                continue;
            }
            Contact::updateOrCreate(['wa_id' => $wa], ['saved_at' => now()]);
            $saved++;
        }

        $this->reply($admin, $saved
            ? "📇 {$saved} saved client(s): the bot won't reply to them or message them. Undo one with: unsave 9715…"
            : "I didn't find any phone numbers in that.");
    }

    /** @return string[] phone numbers in a vCard file */
    public static function vcfNumbers(string $vcf): array
    {
        preg_match_all('/^(?:item\d+\.)?TEL[^:\r\n]*:(.+)$/mi', $vcf, $m);

        return array_map('trim', $m[1] ?? []);
    }

    private function sameVenue(string $a, string $b): bool
    {
        return Game::sameVenue($a, $b);
    }

    private function publishBatch(Contact $admin, string $batch, bool $publish): void
    {
        $games = Game::where('batch_id', $batch)->where('status', 'draft')->get();
        $count = Game::whereIn('id', $games->pluck('id'))->where('status', 'draft')
            ->update(['status' => $publish ? 'published' : 'cancelled']);

        if ($count === 0) {
            $this->reply($admin, 'Those games were already handled.');

            return;
        }
        if (! $publish) {
            $this->reply($admin, "🗑 Discarded {$count} draft game(s).");

            return;
        }

        $notified = $games->sum(fn (Game $g) => $this->roster->notifyWaitlist($g->fresh()));
        $reminded = $games->sum(fn (Game $g) => app(\App\Services\Booking\ReminderService::class)->notifyForGame($g->fresh()));
        $this->reply($admin, "✅ {$count} game(s) published. The bot is taking bookings."
            .($notified ? " Told {$notified} waitlisted player(s)." : '')
            .($reminded ? " {$reminded} player(s) to invite: list below 👇" : ''));
    }

    private function addPlayer(Contact $admin, int $gameId, string $name, int $players): void
    {
        $result = $this->bookings->addManual($gameId, $name, $players, $admin);
        if (! $result['ok']) {
            $this->reply($admin, $result['error']);

            return;
        }

        $game = $result['game']->fresh();
        $booking = $result['booking'];
        $this->reply($admin, "Added {$booking->player_name} ×{$booking->players} to #{$game->id}. "
            ."{$game->confirmedPlayers()}/{$game->capacity} confirmed."
            .($result['over_capacity'] ? " ⚠️ That's {$result['over_capacity']} over capacity." : ''));
        $this->roster->shareUpdated($game, "{$booking->player_name} added");
    }

    private function copyGame(Contact $admin, int $gameId, ?string $date, string $time): void
    {
        $source = Game::find($gameId);
        if (! $source) {
            $this->reply($admin, "No game #{$gameId}.");

            return;
        }

        $tz = config('fbl.timezone');
        $day = $date ?? $source->localStart()->toDateString();
        try {
            $startsAt = \Carbon\Carbon::createFromFormat('!Y-m-d H:i', "{$day} {$time}", $tz)->utc();
        } catch (\Throwable) {
            $this->reply($admin, 'Use: copy 12 22:30  or  copy 12 2026-11-07 21:00');

            return;
        }
        if ($startsAt->isPast()) {
            $this->reply($admin, 'That time has already passed.');

            return;
        }

        $game = Game::create([
            'starts_at' => $startsAt,
            'venue' => $source->venue,
            'format' => $source->format,
            'price' => $source->price,
            'capacity' => $source->capacity,
            'payment_link' => $source->payment_link,
            'notes' => $source->notes,
            'status' => 'published',
        ]);

        $notified = $this->roster->notifyWaitlist($game);
        $this->reply($admin, "✅ Game #{$game->id} published: {$game->label()}. The bot is taking bookings."
            .($notified ? " Told {$notified} waitlisted player(s)." : '')
            ."\n\nPaste this in the group:");
        $this->reply($admin, $this->roster->groupText($game));
    }

    private function listGames(Contact $admin): void
    {
        $games = Game::upcoming()->limit(15)->get();
        if ($games->isEmpty()) {
            $this->reply($admin, 'No upcoming published games. Forward your group post to add some.');

            return;
        }

        $lines = $games->map(fn (Game $g) => "#{$g->id} {$g->label()} · {$g->confirmedPlayers()}/{$g->capacity} confirmed · {$g->spotsLeft()} left");
        $this->reply($admin, "Upcoming games:\n".$lines->implode("\n"));
    }

    private function listPlayers(Contact $admin, int $gameId): void
    {
        $game = Game::find($gameId);
        if (! $game) {
            $this->reply($admin, "No game #{$gameId}.");

            return;
        }

        $rows = $game->bookings()->whereIn('status', ['confirmed', 'awaiting_approval'])->with('contact')->orderBy('id')->get();
        $lines = $rows->map(fn (Booking $b) => ($b->status === 'confirmed' ? '✅' : '⏳')." {$b->player_name} ×{$b->players} · ".($b->contact ? '+'.$b->contact->wa_id : 'added by hand')." · {$b->reference}");

        $this->reply($admin, "#{$game->id} {$game->label()}\n{$game->confirmedPlayers()}/{$game->capacity} confirmed\n"
            .($lines->isEmpty() ? 'No players yet.' : $lines->implode("\n")));
    }

    private function listPending(Contact $admin): void
    {
        $rows = Booking::where('status', 'awaiting_approval')->with('game')->orderBy('payment_reported_at')->limit(15)->get();
        if ($rows->isEmpty()) {
            $this->reply($admin, 'Nothing waiting for approval 👍');

            return;
        }

        $lines = $rows->map(fn (Booking $b) => "{$b->reference} · {$b->player_name} ×{$b->players} · {$b->amountLabel()} · {$b->game->label()}"
            .($b->payment_proof_media_id ? '' : ' · no screenshot'));
        $this->reply($admin, "Waiting for approval:\n".$lines->implode("\n")."\n\nReply \"confirm FBL-XXXXX\" or \"reject FBL-XXXXX\".");
    }

    private function cancelGame(Contact $admin, int $gameId): void
    {
        $game = Game::find($gameId);
        if (! $game) {
            $this->reply($admin, "No game #{$gameId}.");

            return;
        }

        $game->update(['status' => 'cancelled']);
        $paid = $game->bookings()->whereIn('status', ['confirmed', 'awaiting_approval'])->count();
        $game->bookings()->where('status', 'pending_payment')->update(['status' => 'cancelled']);

        $this->reply($admin, "Game #{$game->id} cancelled. No new bookings."
            .($paid ? " ⚠️ {$paid} paid booking(s) need a message/refund from you: send \"who {$game->id}\"." : ''));
    }

    private function reply(Contact $admin, string $text): void
    {
        $this->messenger->text($admin->fresh(), $text);
    }
}
