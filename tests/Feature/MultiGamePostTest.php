<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Contact;
use App\Models\Game;
use App\Services\Booking\RosterService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MultiGamePostTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_1 = '971500000091';

    public function test_game_1_and_game_2_lists_go_to_their_own_games_and_the_post_is_reused(): void
    {
        $date = now('Asia/Dubai')->addDay()->format('Y-m-d');
        $startsAt = Carbon::parse("{$date} 20:50", 'Asia/Dubai')->utc();
        $g1 = Game::create(['starts_at' => $startsAt, 'venue' => 'Dubai Schools Al Barsha', 'format' => '8-a-side', 'price' => 50, 'capacity' => 3, 'status' => 'published']);
        $g2 = Game::create(['starts_at' => $startsAt, 'venue' => 'Dubai Schools Al Barsha', 'format' => '8-a-side', 'price' => 50, 'capacity' => 2, 'status' => 'published']);

        $this->assertStringEndsWith('· Game 1', $g1->label());
        $this->assertStringEndsWith('· Game 2', $g2->label());

        // The parser happens to return Game 2 first: names decide which list is whose.
        Http::fake(function (Request $r) use ($date) {
            if (str_contains($r->url(), 'anthropic')) {
                return Http::response(['content' => [['type' => 'tool_use', 'id' => 's1', 'name' => 'save_games', 'input' => ['games' => [
                    ['date' => $date, 'start_time' => '20:50', 'venue' => 'Dubai Schools Al Barsha', 'price' => 50, 'capacity' => 2, 'format' => '8-a-side', 'players' => ['Sami']],
                    ['date' => $date, 'start_time' => '20:50', 'venue' => 'Dubai Schools Al Barsha', 'price' => 50, 'capacity' => 3, 'format' => '8-a-side', 'players' => ['Ali', 'Omar']],
                ]]]], 'stop_reason' => 'tool_use']);
            }

            return Http::response(['messages' => [['id' => 'wamid.out.'.uniqid()]]]);
        });

        $post = "SUNDAY GAMES ⚽\n\nGAME 1\n1. Ali\n2. Omar\n3.\n\nGAME 2\n1. Sami\n2.\n\nWAITING LIST\n1.\n\nSee you there!";
        Contact::create(['wa_id' => self::ADMIN_1, 'last_inbound_at' => now()]);
        $this->whatsapp(self::ADMIN_1, ['type' => 'text', 'text' => ['body' => $post], 'context' => ['forwarded' => true]]);

        $g1->refresh();
        $g2->refresh();
        $this->assertSame(['Ali', 'Omar'], $g1->bookings()->where('status', 'confirmed')->orderBy('id')->pluck('player_name')->all());
        $this->assertSame(['Sami'], $g2->bookings()->where('status', 'confirmed')->pluck('player_name')->all());
        $this->assertSame([0, 1], [$g1->list_part, $g2->list_part]);
        $this->assertSame($g1->list_group, $g2->list_group);
        $this->assertSame(1, $g1->spotsLeft());

        // Someone books Game 2 through the bot: the whole post comes back with both lists up to date.
        $client = Contact::create(['wa_id' => '971500000001']);
        Booking::create(['contact_id' => $client->id, 'game_id' => $g2->id, 'player_name' => 'Karim', 'players' => 1, 'amount' => 50, 'status' => 'confirmed']);

        $text = app(RosterService::class)->groupText($g2->fresh());
        $this->assertStringContainsString("GAME 1\n1. Ali\n2. Omar\n3.\n\nGAME 2\n1. Sami\n2. Karim\n\nWAITING LIST\n1.\n\nSee you there!", $text);
    }

    private function whatsapp(string $from, array $message): void
    {
        $json = json_encode(['entry' => [['changes' => [[
            'field' => 'messages',
            'value' => [
                'contacts' => [['wa_id' => $from, 'profile' => ['name' => 'Admin']]],
                'messages' => [$message + ['id' => 'wamid.in.'.uniqid(), 'from' => $from, 'timestamp' => (string) time()]],
            ],
        ]]]]]);

        $this->call('POST', '/api/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $json, 'test-secret'),
        ], $json)->assertOk();
    }
}
