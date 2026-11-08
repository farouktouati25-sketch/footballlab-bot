<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Contact;
use App\Models\Game;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SuggestPlayersTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_1 = '971500000091';

    public function test_suggest_messages_recent_players_in_window_and_lists_the_rest(): void
    {
        Http::fake(fn () => Http::response(['messages' => [['id' => 'wamid.out.'.uniqid()]]]));
        $this->travelTo(Carbon::parse('2026-11-09 12:00', 'Asia/Dubai')); // Monday

        $game = $this->game('2026-11-10 21:00'); // Tuesday
        $sat = $this->game('2026-11-07 21:00');  // 2 days ago
        $prevTue = $this->game('2026-11-03 21:00'); // same weekday
        $thu = $this->game('2026-11-05 21:00');  // 4 days ago, other weekday

        $recent = $this->player('971500000001', 'Ali Hassan', now()->subHour(), $sat);
        $sameDay = $this->player('971500000002', 'Omar', now()->subDays(3), $prevTue);
        $other = $this->player('971500000003', 'Sami', now()->subHour(), $thu);
        $already = $this->player('971500000004', 'Yusuf', now()->subHour(), $sat);
        Booking::create(['contact_id' => $already->id, 'game_id' => $game->id, 'player_name' => 'Yusuf', 'players' => 1, 'amount' => 53, 'status' => 'confirmed']);
        Booking::create(['contact_id' => null, 'source' => 'manual', 'game_id' => $sat->id, 'player_name' => 'Khalid', 'players' => 1, 'amount' => 53, 'status' => 'confirmed']);

        Contact::create(['wa_id' => self::ADMIN_1, 'last_inbound_at' => now()]);
        $this->whatsapp(self::ADMIN_1, "suggest {$game->id}");

        // Ali chatted within 24h: the bot invites him itself.
        Http::assertSent(fn (Request $r) => ($r->data()['to'] ?? null) === $recent->wa_id
            && str_contains($r->data()['text']['body'] ?? '', 'Hey Ali 👋')
            && str_contains($r->data()['text']['body'] ?? '', 'tomorrow at 9:00 PM'));
        // Omar's window is closed: he is on the admin's list. Sami (other weekday) and Yusuf (already in) are not messaged.
        Http::assertNotSent(fn (Request $r) => in_array($r->data()['to'] ?? null, [$sameDay->wa_id, $other->wa_id, $already->wa_id], true));
        Http::assertSent(function (Request $r) use ($sameDay, $other) {
            $body = $r->data()['text']['body'] ?? '';

            return ($r->data()['to'] ?? null) === self::ADMIN_1
                && str_contains($body, 'Players to invite')
                && str_contains($body, '+'.$sameDay->wa_id.' · played this weekday before')
                && str_contains($body, 'played in the last 3 days ✅ messaged')
                && str_contains($body, 'no number saved): Khalid')
                && ! str_contains($body, $other->wa_id);
        });
    }

    private function game(string $local): Game
    {
        return Game::create(['starts_at' => Carbon::parse($local, 'Asia/Dubai')->utc(), 'venue' => 'Dubai Schools Al Barsha',
            'format' => '8v8', 'price' => 53, 'capacity' => 16, 'status' => 'published']);
    }

    private function player(string $waId, string $name, Carbon $lastInbound, Game $played): Contact
    {
        $c = Contact::create(['wa_id' => $waId, 'name' => $name, 'last_inbound_at' => $lastInbound]);
        Booking::create(['contact_id' => $c->id, 'game_id' => $played->id, 'player_name' => $name, 'players' => 1, 'amount' => 53, 'status' => 'confirmed']);

        return $c;
    }

    private function whatsapp(string $from, string $text): void
    {
        $json = json_encode(['entry' => [['changes' => [[
            'field' => 'messages',
            'value' => [
                'contacts' => [['wa_id' => $from, 'profile' => ['name' => 'Admin']]],
                'messages' => [['id' => 'wamid.in.'.uniqid(), 'from' => $from, 'timestamp' => (string) time(), 'type' => 'text', 'text' => ['body' => $text]]],
            ],
        ]]]]]);

        $this->call('POST', '/api/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $json, 'test-secret'),
        ], $json)->assertOk();
    }
}
