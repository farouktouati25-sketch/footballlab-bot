<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Contact;
use App\Models\Game;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminPaymentLinkTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT = '971500000001';
    private const ADMIN_1 = '971500000091';
    private const ADMIN_2 = '971500000092';

    private array $claude = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['fbl.payments.mode' => 'admin_link']);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'api.anthropic.com')) {
                return Http::response(array_shift($this->claude) ?? ['content' => [['type' => 'text', 'text' => 'ok']], 'stop_reason' => 'end_turn']);
            }

            return Http::response(['messages' => [['id' => 'wamid.out.'.uniqid()]]]);
        });
    }

    public function test_bot_asks_admins_and_forwards_the_quoted_reply_link_to_the_client(): void
    {
        Contact::create(['wa_id' => self::ADMIN_1, 'last_inbound_at' => now()]);
        Contact::create(['wa_id' => self::ADMIN_2, 'last_inbound_at' => now()]);
        $game = $this->game();

        $this->claude = [
            ['content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'create_booking',
                'input' => ['game_id' => $game->id, 'player_name' => 'Ali', 'players' => 2]]], 'stop_reason' => 'tool_use'],
            ['content' => [['type' => 'text', 'text' => 'The team will send your link shortly.']], 'stop_reason' => 'end_turn'],
        ];
        $this->whatsapp(self::CLIENT, ['type' => 'text', 'text' => ['body' => 'Book 2 please']]);

        $booking = Booking::sole();
        $this->assertNull($booking->payment_url);
        $this->assertNotNull($booking->link_requested_at);

        // Admin 1 got the request; the agent was told not to invent a link.
        Http::assertSent(fn (Request $r) => ($r->data()['to'] ?? null) === self::ADMIN_1
            && str_contains($r->data()['text']['body'] ?? '', 'needs a payment link')
            && str_contains($r->data()['text']['body'] ?? '', '100 AED'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'anthropic') && str_contains($r->body(), 'Do not invent a link'));

        // Admin 1 replies (quoting the request) with the link.
        $request = Message::where('author', 'bot')->get()->first(fn ($m) => ($m->payload['kind'] ?? null) === 'link_request'
            && $m->contact->wa_id === self::ADMIN_1);
        $this->whatsapp(self::ADMIN_1, ['type' => 'text', 'text' => ['body' => 'https://pay.ziina.com/abc123'],
            'context' => ['id' => $request->wa_message_id]]);

        $booking->refresh();
        $this->assertSame('https://pay.ziina.com/abc123', $booking->payment_url);
        $this->assertSame(self::ADMIN_1, $booking->link_sent_by);

        Http::assertSent(fn (Request $r) => ($r->data()['to'] ?? null) === self::CLIENT
            && str_contains($r->data()['text']['body'] ?? '', 'https://pay.ziina.com/abc123'));
        Http::assertSent(fn (Request $r) => ($r->data()['to'] ?? null) === self::ADMIN_2
            && str_contains($r->data()['text']['body'] ?? '', 'payment link sent by Farouk'));
    }

    public function test_link_with_reference_and_ambiguous_bare_link(): void
    {
        $a = $this->requested('Ali');
        $b = $this->requested('Omar', '971500000002');

        // Bare link with two open requests: the bot asks which one.
        $this->whatsapp(self::ADMIN_1, ['type' => 'text', 'text' => ['body' => 'https://pay.ziina.com/x']]);
        $this->assertNull($a->fresh()->payment_url);
        Http::assertSent(fn (Request $r) => ($r->data()['to'] ?? null) === self::ADMIN_1
            && str_contains($r->data()['text']['body'] ?? '', 'Which booking'));

        // With the reference it goes to the right client.
        $this->whatsapp(self::ADMIN_1, ['type' => 'text', 'text' => ['body' => "{$b->reference} https://pay.ziina.com/omar"]]);
        $this->assertSame('https://pay.ziina.com/omar', $b->fresh()->payment_url);
        $this->assertNull($a->fresh()->payment_url);
    }

    public function test_waiting_for_link_keeps_the_spot_past_the_normal_hold(): void
    {
        $game = $this->game(['capacity' => 1]);
        $client = Contact::create(['wa_id' => self::CLIENT, 'last_inbound_at' => now()]);
        $booking = Booking::create(['contact_id' => $client->id, 'game_id' => $game->id, 'player_name' => 'Ali',
            'players' => 1, 'amount' => 50, 'status' => 'pending_payment', 'link_requested_at' => now()]);
        $booking->forceFill(['created_at' => now()->subHours(3)])->save();

        $this->artisan('bookings:housekeeping')->assertSuccessful();

        $this->assertSame('pending_payment', $booking->fresh()->status);
        $this->assertSame(0, $game->fresh()->spotsLeft());
    }

    // ---------------------------------------------------------------- helpers

    private function game(array $overrides = []): Game
    {
        return Game::create($overrides + [
            'starts_at' => now()->addDays(2),
            'venue' => 'Al Safa Sports Ground',
            'price' => 50,
            'capacity' => 14,
            'status' => 'published',
        ]);
    }

    private function requested(string $name, string $waId = self::CLIENT): Booking
    {
        $client = Contact::firstOrCreate(['wa_id' => $waId], ['last_inbound_at' => now()]);

        return Booking::create(['contact_id' => $client->id, 'game_id' => $this->game()->id, 'player_name' => $name,
            'players' => 1, 'amount' => 50, 'status' => 'pending_payment', 'link_requested_at' => now()]);
    }

    private function whatsapp(string $from, array $message): void
    {
        $json = json_encode(['entry' => [['changes' => [[
            'field' => 'messages',
            'value' => [
                'contacts' => [['wa_id' => $from, 'profile' => ['name' => 'Test']]],
                'messages' => [$message + ['id' => 'wamid.in.'.uniqid(), 'from' => $from, 'timestamp' => (string) time()]],
            ],
        ]]]]]);

        $this->call('POST', '/api/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $json, 'test-secret'),
        ], $json)->assertOk();
    }
}
