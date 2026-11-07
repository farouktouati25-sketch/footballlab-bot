<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Contact;
use App\Models\Game;
use App\Models\WaitlistEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RosterAndWaitlistTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_1 = '971500000091';
    private const CLIENT = '971500000001';

    private array $claude = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'api.anthropic.com')) {
                return Http::response(array_shift($this->claude) ?? [
                    'content' => [['type' => 'text', 'text' => 'ok']], 'stop_reason' => 'end_turn',
                ]);
            }

            return Http::response(['messages' => [['id' => 'wamid.out.'.uniqid()]]]);
        });
    }

    public function test_admin_adds_group_player_and_gets_paste_ready_list(): void
    {
        $game = $this->game(['capacity' => 4]);

        $this->admin("add {$game->id} Omar Khalid 2");

        $booking = Booking::sole();
        $this->assertSame('manual', $booking->source);
        $this->assertNull($booking->contact_id);
        $this->assertSame('confirmed', $booking->status);

        // The pasted list numbers every slot and shortens names.
        Http::assertSent(fn (Request $r) => ($r->data()['to'] ?? null) === self::ADMIN_1
            && str_contains($r->data()['text']['body'] ?? '', "1. Omar K.\n2. Omar K. +1\n3. \n4. ")
            && str_contains($r->data()['text']['body'] ?? '', '2 spot(s) left'));
    }

    public function test_remove_by_name(): void
    {
        $game = $this->game();
        $this->admin("add {$game->id} Omar");
        $this->admin("remove {$game->id} omar");

        $this->assertSame('cancelled', Booking::sole()->status);
    }

    public function test_full_game_waitlist_then_copy_opens_second_game_and_notifies(): void
    {
        $game = $this->game(['capacity' => 1]);
        $this->admin("add {$game->id} Omar");

        // Client asks; agent puts them on the waitlist.
        $this->claude = [
            ['content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'join_waitlist', 'input' => ['game_id' => $game->id, 'players' => 2]]], 'stop_reason' => 'tool_use'],
            ['content' => [['type' => 'text', 'text' => "You're on the waitlist."]], 'stop_reason' => 'end_turn'],
        ];
        $this->inbound(self::CLIENT, ['type' => 'text', 'text' => ['body' => 'Any spot tonight?']]);

        $entry = WaitlistEntry::sole();
        $this->assertSame(2, $entry->players);
        // Admin got the "open a second game" nudge.
        Http::assertSent(fn (Request $r) => ($r->data()['to'] ?? null) === self::ADMIN_1
            && str_contains($r->data()['text']['body'] ?? '', "copy {$game->id}"));

        $time = $game->localStart()->addMinutes(90)->format('H:i');
        $this->admin("copy {$game->id} {$time}");

        $second = Game::where('id', '!=', $game->id)->sole();
        $this->assertSame('published', $second->status);
        $this->assertSame($game->venue, $second->venue);
        $this->assertNotNull($entry->fresh()->notified_at);
        Http::assertSent(fn (Request $r) => ($r->data()['to'] ?? null) === self::CLIENT
            && str_contains($r->data()['text']['body'] ?? '', 'A new game just opened'));
    }

    public function test_public_roster_page(): void
    {
        config(['fbl.public_list_token' => 'secret-token-123']);
        $game = $this->game();
        $this->admin("add {$game->id} Sara Khan");

        $this->get('/games/wrong')->assertNotFound();
        $this->get('/games/secret-token-123')
            ->assertOk()
            ->assertSee('Sara K.')
            ->assertDontSee('Khan')
            ->assertSee('spots left');
    }

    // ---------------------------------------------------------------- helpers

    private function game(array $overrides = []): Game
    {
        return Game::create($overrides + [
            'starts_at' => now()->addDays(2)->setTime(17, 0),
            'venue' => 'Al Safa Sports Ground, Nad Al Sheba',
            'price' => 50,
            'capacity' => 14,
            'status' => 'published',
        ]);
    }

    private function admin(string $text): void
    {
        $this->inbound(self::ADMIN_1, ['type' => 'text', 'text' => ['body' => $text]]);
    }

    private function inbound(string $from, array $message): void
    {
        $payload = ['entry' => [['changes' => [[
            'field' => 'messages',
            'value' => [
                'contacts' => [['wa_id' => $from, 'profile' => ['name' => 'Test']]],
                'messages' => [$message + ['id' => 'wamid.in.'.uniqid(), 'from' => $from, 'timestamp' => (string) time()]],
            ],
        ]]]]];
        $json = json_encode($payload);

        $this->call('POST', '/api/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $json, 'test-secret'),
        ], $json)->assertOk();
    }
}
