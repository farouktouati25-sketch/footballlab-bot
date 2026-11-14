<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Contact;
use App\Models\Game;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminConfirmsFirstTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT = '971500000001';
    private const ADMIN_1 = '971500000091';

    private array $claude = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['fbl.payments.mode' => 'admin_link', 'fbl.payments.admin_confirms_first' => true]);
        Http::fake(function (Request $r) {
            if (str_contains($r->url(), 'anthropic')) {
                return Http::response(array_shift($this->claude) ?? ['content' => [['type' => 'text', 'text' => 'Noted 👍 the team will confirm your spot shortly.']], 'stop_reason' => 'end_turn']);
            }

            return Http::response(['messages' => [['id' => 'wamid.out.'.uniqid()]]]);
        });
        Contact::create(['wa_id' => self::ADMIN_1, 'last_inbound_at' => now()]);
    }

    private function book(): Booking
    {
        $game = Game::create(['starts_at' => now()->addHours(6), 'venue' => 'Al Safa Sports Ground', 'format' => '7v7', 'price' => 53, 'capacity' => 14, 'status' => 'published']);
        $this->claude = [
            ['content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'create_booking', 'input' => ['game_id' => $game->id, 'player_name' => 'Ali', 'players' => 1]]], 'stop_reason' => 'tool_use'],
            ['content' => [['type' => 'text', 'text' => 'Noted 👍 the team will confirm your spot shortly.']], 'stop_reason' => 'end_turn'],
        ];
        $this->whatsapp(self::CLIENT, 'Add me for tonight, Ali');

        return Booking::sole();
    }

    public function test_admin_confirms_without_a_link(): void
    {
        $booking = $this->book();
        $this->assertSame('awaiting_approval', $booking->status);
        $this->assertNull($booking->link_requested_at);
        Http::assertSent(fn (Request $r) => ($r->data()['to'] ?? null) === self::ADMIN_1
            && ($r->data()['interactive']['action']['buttons'][0]['reply']['title'] ?? null) === '✅ Confirm'
            && ($r->data()['interactive']['action']['buttons'][1]['reply']['title'] ?? null) === '💳 Send link');
        Http::assertNotSent(fn (Request $r) => ($r->data()['to'] ?? null) === self::CLIENT && str_contains($r->data()['text']['body'] ?? '', "You're in"));

        $this->tap("approve:{$booking->id}");

        $this->assertSame('confirmed', $booking->fresh()->status);
        Http::assertSent(fn (Request $r) => ($r->data()['to'] ?? null) === self::CLIENT && str_contains($r->data()['text']['body'] ?? '', "You're in"));
    }

    public function test_admin_chooses_to_send_a_link(): void
    {
        $booking = $this->book();
        $this->tap("reject:{$booking->id}");

        $booking->refresh();
        $this->assertSame('pending_payment', $booking->status);
        $this->assertNotNull($booking->link_requested_at);
        Http::assertSent(fn (Request $r) => ($r->data()['to'] ?? null) === self::ADMIN_1
            && str_contains($r->data()['text']['body'] ?? '', 'needs a payment link'));
    }

    private function tap(string $id): void
    {
        $this->post_(self::ADMIN_1, ['type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => $id, 'title' => 'x']]]);
    }

    private function whatsapp(string $from, string $text): void
    {
        $this->post_($from, ['type' => 'text', 'text' => ['body' => $text]]);
    }

    private function post_(string $from, array $message): void
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
