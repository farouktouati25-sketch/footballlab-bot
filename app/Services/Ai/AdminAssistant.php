<?php

namespace App\Services\Ai;

use App\Models\Booking;
use App\Models\Contact;
use App\Models\Game;
use Illuminate\Support\Facades\Log;

/**
 * Free chat for admins: anything that isn't a command goes here. The assistant reads the bot's data
 * (games, lists, bookings, client chats) and answers; for changes it gives the exact command to send.
 */
class AdminAssistant
{
    public function __construct(private readonly ClaudeClient $claude) {}

    public function reply(Contact $admin, string $help): ?string
    {
        $rows = $admin->messages()->latest('id')->limit(16)->get(['author', 'body'])->reverse()
            ->map(fn ($m) => ['author' => $m->author === 'admin' ? 'client' : ($m->author === 'bot' ? 'bot' : null), 'body' => $m->body])
            ->filter(fn ($r) => $r['author'])->values()->all();
        $messages = ConversationHistory::build($rows);
        if (! $messages || end($messages)['role'] !== 'user') {
            return null;
        }

        $tz = config('fbl.timezone');
        $system = 'You are the private assistant of the FootballLab DXB team (admin: '.$admin->adminName().') inside their WhatsApp booking bot. '
            .'Now in Dubai: '.now($tz)->format('l j F Y, g:i A').".\n"
            ."Answer their questions about games, lists, bookings, payments and client chats using the tools. Be short (WhatsApp), concrete, no fluff. Reply in the admin's language.\n"
            ."You can't change anything yourself. When they want something done, give the exact command to send (one per line), from this menu:\n{$help}\n"
            .'Never invent data: if a tool returns nothing, say so.';

        $tools = self::definitions();
        try {
            for ($i = 0; $i < 5; $i++) {
                $response = $this->claude->messages($system, $messages, $tools, null, 900);
                $uses = ClaudeClient::toolUses($response);
                if (($response['stop_reason'] ?? null) !== 'tool_use' || ! $uses) {
                    return ClaudeClient::text($response) ?: null;
                }
                $messages[] = ['role' => 'assistant', 'content' => array_map(function ($b) {
                    if (($b['type'] ?? null) === 'tool_use') {
                        $b['input'] = (object) ($b['input'] ?? []);
                    }

                    return $b;
                }, $response['content'])];
                $results = [];
                foreach ($uses as $use) {
                    $results[] = ['type' => 'tool_result', 'tool_use_id' => $use['id'],
                        'content' => json_encode($this->run($use['name'], (array) ($use['input'] ?? [])), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
                }
                $messages[] = ['role' => 'user', 'content' => $results];
            }
        } catch (\Throwable $e) {
            Log::error('Admin assistant failed', ['error' => $e->getMessage()]);

            return null;
        }

        return null;
    }

    public static function definitions(): array
    {
        $obj = fn (array $props = [], array $req = []) => ['type' => 'object', 'properties' => (object) $props] + ($req ? ['required' => $req] : []);

        return [
            ['name' => 'games', 'description' => 'Games from a date on (default today), including drafts waiting for the admins and recent past ones if from_date is in the past.',
                'input_schema' => $obj(['from_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'], 'days' => ['type' => 'integer']])],
            ['name' => 'game_players', 'description' => 'Everyone on one game: name, players, status (confirmed / awaiting the admins / waiting to pay), phone.',
                'input_schema' => $obj(['game_id' => ['type' => 'integer']], ['game_id'])],
            ['name' => 'find_player', 'description' => 'Find a player or client by name or phone: their bookings (last 30 days and upcoming) and contact info.',
                'input_schema' => $obj(['query' => ['type' => 'string']], ['query'])],
            ['name' => 'pending', 'description' => 'Bookings waiting for the admins (confirm / link / full) or for a payment link, and chats handed to the team.',
                'input_schema' => $obj()],
            ['name' => 'client_chat', 'description' => 'The last messages between the bot/team and one client (by phone or name).',
                'input_schema' => $obj(['query' => ['type' => 'string'], 'limit' => ['type' => 'integer']], ['query'])],
        ];
    }

    public function run(string $name, array $in): array
    {
        $tz = config('fbl.timezone');

        return match ($name) {
            'games' => (function () use ($in, $tz) {
                $from = isset($in['from_date']) ? \Carbon\Carbon::parse($in['from_date'], $tz)->startOfDay() : now($tz)->startOfDay();

                return ['games' => Game::where('status', '!=', 'cancelled')
                    ->whereBetween('starts_at', [$from->copy()->utc(), $from->copy()->addDays(max(1, min(21, (int) ($in['days'] ?? 7))))->utc()])
                    ->orderBy('starts_at')->get()->map(fn (Game $g) => ['id' => $g->id, 'when' => $g->localStart()->format('D j M g:i A'),
                        'venue' => $g->venue, 'format' => $g->format, 'status' => $g->status === 'draft' ? 'not published (waiting for the admins)' : $g->status,
                        'confirmed' => $g->confirmedPlayers(), 'capacity' => $g->capacity, 'spots_left' => $g->spotsLeft(), 'price' => (float) $g->price])->all()];
            })(),
            'game_players' => ($g = Game::find((int) ($in['game_id'] ?? 0)))
                ? ['game' => $g->label(), 'status' => $g->status, 'players' => $g->bookings()->whereIn('status', ['confirmed', 'awaiting_approval', 'pending_payment'])->with('contact')->orderBy('id')->get()
                    ->map(fn (Booking $b) => ['name' => $b->player_name, 'players' => $b->players, 'status' => $b->status, 'ref' => $b->reference,
                        'phone' => $b->contact ? '+'.$b->contact->wa_id : null, 'pays_on_spot' => $b->wantsCash()])->all()]
                : ['error' => 'No game with that number.'],
            'find_player' => (function () use ($in) {
                $q = trim((string) ($in['query'] ?? ''));
                $digits = preg_replace('/\D/', '', $q);
                $contacts = Contact::when(strlen($digits) >= 6, fn ($x) => $x->where('wa_id', 'like', '%'.$digits.'%'),
                    fn ($x) => $x->where(fn ($y) => $y->where('name', 'like', "%{$q}%")->orWhere('player_name', 'like', "%{$q}%")))->limit(5)->get();
                $bookings = Booking::with('game')->where('player_name', 'like', "%{$q}%")
                    ->whereHas('game', fn ($g) => $g->where('starts_at', '>', now()->subDays(30)))->latest('id')->limit(15)->get();

                return ['contacts' => $contacts->map(fn (Contact $c) => ['name' => $c->player_name ?: $c->name, 'phone' => '+'.$c->wa_id,
                    'old_client' => $c->client_type === 'old', 'last_message' => optional($c->last_inbound_at)?->diffForHumans()])->all(),
                    'bookings' => $bookings->map(fn (Booking $b) => ['name' => $b->player_name, 'game' => $b->game?->label(), 'game_id' => $b->game_id, 'status' => $b->status, 'ref' => $b->reference])->all()];
            })(),
            'pending' => [
                'waiting_for_admins' => Booking::with(['game', 'contact'])->where('status', 'awaiting_approval')->get()
                    ->map(fn (Booking $b) => ['ref' => $b->reference, 'name' => $b->player_name, 'game' => $b->game?->label(), 'players' => $b->players, 'note' => $b->payment_note])->all(),
                'waiting_for_payment_link' => Booking::with('game')->whereIn('status', ['pending_payment', 'confirmed'])->whereNotNull('link_requested_at')->whereNull('payment_url')->get()
                    ->map(fn (Booking $b) => ['ref' => $b->reference, 'name' => $b->player_name, 'game' => $b->game?->label()])->all(),
                'chats_handed_to_team' => Contact::whereNotNull('handoff_at')->get()->map(fn (Contact $c) => ['name' => $c->displayName(), 'phone' => '+'.$c->wa_id, 'why' => $c->handoff_reason])->all(),
            ],
            'client_chat' => (function () use ($in) {
                $q = trim((string) ($in['query'] ?? ''));
                $digits = preg_replace('/\D/', '', $q);
                $c = strlen($digits) >= 6 ? Contact::where('wa_id', 'like', '%'.$digits.'%')->first()
                    : Contact::where('name', 'like', "%{$q}%")->orWhere('player_name', 'like', "%{$q}%")->orderByDesc('last_inbound_at')->first();
                if (! $c) {
                    return ['error' => 'No client found.'];
                }

                return ['client' => ($c->player_name ?: $c->name).' +'.$c->wa_id, 'messages' => $c->messages()->latest('id')->limit(max(1, min(30, (int) ($in['limit'] ?? 12))))->get()->reverse()
                    ->map(fn ($m) => $m->created_at->setTimezone(config('fbl.timezone'))->format('D H:i').' '.($m->direction === 'in' ? 'client' : $m->author).': '.mb_substr((string) $m->body, 0, 300))->values()->all()];
            })(),
            default => ['error' => 'Unknown tool'],
        };
    }
}
