<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Contact;
use App\Models\Game;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZiinaPaymentTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT = '971500000001';
    private const ADMIN_1 = '971500000091';

    private array $claude = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'fbl.payments.mode' => 'ziina',
            'fbl.payments.ziina_token' => 'ziina-test-token',
            'fbl.payments.ziina_webhook_secret' => 'ziina-webhook-secret-123456',
        ]);

        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_contains($url, 'api.anthropic.com')) {
                return Http::response(array_shift($this->claude) ?? ['content' => [['type' => 'text', 'text' => 'ok']], 'stop_reason' => 'end_turn']);
            }
            if (str_contains($url, 'api-v2.ziina.com/api/payment_intent')) {
                return Http::response(['id' => 'pi_123', 'redirect_url' => 'https://pay.ziina.com/pi_123', 'status' => 'requires_payment_instrument']);
            }

            return Http::response(['messages' => [['id' => 'wamid.out.'.uniqid()]]]);
        });
    }

    public function test_each_booking_gets_its_own_link_for_the_exact_amount(): void
    {
        $game = $this->game(['price' => 60]);
        $this->claude = [
            ['content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'create_booking',
                'input' => ['game_id' => $game->id, 'player_name' => 'Ali', 'players' => 2]]], 'stop_reason' => 'tool_use'],
            ['content' => [['type' => 'text', 'text' => 'Pay here: https://pay.ziina.com/pi_123']], 'stop_reason' => 'end_turn'],
        ];

        $this->whatsapp(self::CLIENT, ['type' => 'text', 'text' => ['body' => 'Book 2 for Thursday']]);

        $booking = Booking::sole();
        $this->assertSame('pi_123', $booking->payment_intent_id);
        $this->assertSame('https://pay.ziina.com/pi_123', $booking->payment_url);

        // 2 players × 60 AED = 120 AED = 12000 fils
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'ziina.com/api/payment_intent')
            && $r->data()['amount'] === 12000 && $r->data()['currency_code'] === 'AED');
        // The agent was handed the personal link.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'anthropic') && str_contains($r->body(), 'pay.ziina.com\/pi_123'));
    }

    public function test_ziina_webhook_marks_paid_and_asks_admins(): void
    {
        $booking = $this->heldBooking();
        Contact::create(['wa_id' => self::ADMIN_1, 'last_inbound_at' => now()]);

        $this->ziina(['event' => 'payment_intent.status.updated', 'data' => ['id' => 'pi_123', 'status' => 'completed']])->assertOk();

        $booking->refresh();
        $this->assertTrue($booking->payment_verified);
        $this->assertSame('awaiting_approval', $booking->status);

        Http::assertSent(fn (Request $r) => ($r->data()['to'] ?? null) === self::ADMIN_1
            && str_contains($r->data()['interactive']['body']['text'] ?? '', 'PAID online via Ziina'));
        Http::assertSent(fn (Request $r) => ($r->data()['to'] ?? null) === self::CLIENT
            && str_contains($r->data()['text']['body'] ?? '', 'Payment received'));
    }

    public function test_auto_confirm_when_enabled(): void
    {
        config(['fbl.payments.auto_confirm_verified' => true]);
        $booking = $this->heldBooking();

        $this->ziina(['event' => 'payment_intent.status.updated', 'data' => ['id' => 'pi_123', 'status' => 'completed']])->assertOk();

        $this->assertSame('confirmed', $booking->fresh()->status);
        Http::assertSent(fn (Request $r) => ($r->data()['to'] ?? null) === self::CLIENT
            && str_contains($r->data()['text']['body'] ?? '', "You're in"));
    }

    public function test_rejects_unsigned_ziina_webhook(): void
    {
        $this->heldBooking();

        $this->postJson('/api/webhooks/ziina', ['event' => 'payment_intent.status.updated', 'data' => ['id' => 'pi_123', 'status' => 'completed']],
            ['X-Hmac-Signature' => 'nope'])->assertStatus(401);

        $this->assertFalse(Booking::sole()->payment_verified);
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

    private function heldBooking(): Booking
    {
        $client = Contact::create(['wa_id' => self::CLIENT, 'name' => 'Ali', 'last_inbound_at' => now()]);

        return Booking::create(['contact_id' => $client->id, 'game_id' => $this->game()->id, 'player_name' => 'Ali',
            'players' => 1, 'amount' => 50, 'status' => 'pending_payment',
            'payment_intent_id' => 'pi_123', 'payment_url' => 'https://pay.ziina.com/pi_123']);
    }

    private function ziina(array $payload)
    {
        $json = json_encode($payload);

        return $this->call('POST', '/api/webhooks/ziina', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HMAC_SIGNATURE' => hash_hmac('sha256', $json, 'ziina-webhook-secret-123456'),
        ], $json);
    }

    private function whatsapp(string $from, array $message): void
    {
        $json = json_encode(['entry' => [['changes' => [[
            'field' => 'messages',
            'value' => [
                'contacts' => [['wa_id' => $from, 'profile' => ['name' => 'Ali']]],
                'messages' => [$message + ['id' => 'wamid.in.'.uniqid(), 'from' => $from, 'timestamp' => (string) time()]],
            ],
        ]]]]]);

        $this->call('POST', '/api/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $json, 'test-secret'),
        ], $json)->assertOk();
    }
}
