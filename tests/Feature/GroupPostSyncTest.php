<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Contact;
use App\Models\Game;
use App\Services\Booking\BookingService;
use App\Services\Booking\RosterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GroupPostSyncTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_1 = '971500000091';

    private array $claude = [];

    private string $date;

    protected function setUp(): void
    {
        parent::setUp();
        $this->date = Carbon::now(config('fbl.timezone'))->addDays(3)->format('Y-m-d');

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'api.anthropic.com')) {
                return Http::response(array_shift($this->claude) ?? [
                    'content' => [['type' => 'text', 'text' => 'ok']], 'stop_reason' => 'end_turn',
                ]);
            }

            return Http::response(['messages' => [['id' => 'wamid.out.'.uniqid()]]]);
        });
    }

    private function groupPost(array $names, int $slots = 14): string
    {
        $rows = [];
        for ($i = 0; $i < $slots; $i++) {
            $rows[] = ($i + 1).'. '.($names[$i] ?? '');
        }

        return "⚽ SATURDAY NIGHT GAME ⚽\n📍 Al Safa 50\n⏰ 9:00 PM\n\n".implode("\n", array_map('rtrim', $rows))
            ."\n\n🪑 WAITING LIST\n1.\n2.\n\n📌 Game Details:\nFree water\nCash 50 Dhs\n❌ NO CANCELLATION BEFORE 12 HOURS";
    }

    private function parsed(array $names): array
    {
        return ['content' => [['type' => 'tool_use', 'id' => 'p1', 'name' => 'save_games', 'input' => ['games' => [[
            'date' => $this->date, 'start_time' => '21:00', 'venue' => 'Al Safa 50', 'price' => 50,
            'capacity' => 14, 'players' => $names,
        ]]]]], 'stop_reason' => 'tool_use'];
    }

    private function thirteen(): array
    {
        return ['Ali', 'Omar', 'Sami', 'Khalid', 'Yousef', 'Hamza', 'Adam', 'Zaid', 'Karim', 'Tariq', 'Nabil', 'Fadi', 'Rami Hassan'];
    }

    public function test_forwarded_post_counts_existing_names_and_reuses_the_format(): void
    {
        $names = $this->thirteen();
        $this->claude = [$this->parsed($names)];
        $this->admin($this->groupPost($names), forwarded: true);

        $game = Game::sole();
        $this->assertSame(13, $game->confirmedPlayers());
        $this->assertSame(1, $game->spotsLeft());
        Http::assertSent(fn (Request $r) => str_contains($r->data()['interactive']['body']['text'] ?? '', '13/14 already in · 1 left'));

        // A client pays and is confirmed: the list comes back in the admin's own layout, name added in slot 14.
        $client = Contact::create(['wa_id' => '971500000001', 'name' => 'Joe']);
        Booking::create(['contact_id' => $client->id, 'game_id' => $game->id, 'player_name' => 'Joe Smith', 'players' => 1,
            'amount' => 50, 'status' => 'confirmed', 'decided_at' => now()->addMinute()]);

        $text = app(RosterService::class)->groupText($game->fresh());
        $this->assertStringStartsWith("⚽ SATURDAY NIGHT GAME ⚽\n📍 Al Safa 50", $text);
        $this->assertStringContainsString("13. Rami Hassan\n14. Joe Smith\n\n🪑 WAITING LIST\n1.\n2.", $text);
        $this->assertStringEndsWith('❌ NO CANCELLATION BEFORE 12 HOURS', $text);
    }

    public function test_reforwarding_an_updated_list_syncs_instead_of_duplicating(): void
    {
        $names = $this->thirteen();
        $this->claude = [$this->parsed($names)];
        $this->admin($this->groupPost($names), forwarded: true);
        $game = Game::sole();
        $game->update(['status' => 'published']);

        // Someone dropped out in the group and a new guy joined.
        $updated = array_values(array_diff($names, ['Sami']));
        $updated[] = 'Bilal';
        $updated[] = 'Moe';
        $this->claude = [$this->parsed($updated)];
        $this->admin($this->groupPost($updated), forwarded: true);

        $this->assertSame(1, Game::count());
        $this->assertSame(14, $game->fresh()->confirmedPlayers());
        $this->assertSame('cancelled', Booking::where('player_name', 'Sami')->sole()->status);
        Http::assertSent(fn (Request $r) => str_contains($r->data()['text']['body'] ?? '', '14/14 confirmed · 0 left'));
    }

    public function test_bot_bookings_are_never_cancelled_by_a_post(): void
    {
        $game = Game::create(['starts_at' => now()->addDays(2), 'venue' => 'Al Safa', 'price' => 50, 'capacity' => 14, 'status' => 'published']);
        $client = Contact::create(['wa_id' => '971500000001']);
        Booking::create(['contact_id' => $client->id, 'game_id' => $game->id, 'player_name' => 'Joe', 'players' => 1,
            'amount' => 50, 'status' => 'confirmed', 'decided_at' => now()]);
        $admin = Contact::create(['wa_id' => self::ADMIN_1]);

        app(BookingService::class)->syncPostPlayers($game, ['Ali'], $admin);

        $this->assertSame('confirmed', Booking::where('player_name', 'Joe')->sole()->status);
        $this->assertSame(2, $game->fresh()->confirmedPlayers());
    }

    private function admin(string $text, bool $forwarded = false): void
    {
        $message = ['type' => 'text', 'text' => ['body' => $text]];
        if ($forwarded) {
            $message['context'] = ['forwarded' => true];
        }
        $json = json_encode(['entry' => [['changes' => [[
            'field' => 'messages',
            'value' => [
                'contacts' => [['wa_id' => self::ADMIN_1, 'profile' => ['name' => 'Farouk']]],
                'messages' => [$message + ['id' => 'wamid.in.'.uniqid(), 'from' => self::ADMIN_1, 'timestamp' => (string) time()]],
            ],
        ]]]]]);

        $this->call('POST', '/api/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $json, 'test-secret'),
        ], $json)->assertOk();
    }
}
