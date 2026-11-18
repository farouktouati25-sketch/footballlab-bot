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

class RemindPaymentTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_1 = '971500000091';

    private function setUpBooking(): Booking
    {
        Http::fake(fn () => Http::response(['messages' => [['id' => 'wamid.out.'.uniqid()]]]));
        Contact::create(['wa_id' => self::ADMIN_1, 'last_inbound_at' => now()]);
        $harith = Contact::create(['wa_id' => '971501234567', 'name' => 'Harith', 'last_inbound_at' => now()]);
        $game = Game::create(['starts_at' => now()->addHours(8), 'venue' => 'Al Safa Sports Ground', 'format' => '7v7', 'price' => 53, 'capacity' => 14, 'status' => 'published']);

        return Booking::create(['contact_id' => $harith->id, 'game_id' => $game->id, 'player_name' => 'Harith Ali', 'players' => 1, 'amount' => 53,
            'status' => 'pending_payment', 'payment_url' => 'https://pay.ziina.com/h1']);
    }

    public function test_remind_by_name(): void
    {
        $this->setUpBooking();
        $this->post_(self::ADMIN_1, ['type' => 'text', 'text' => ['body' => 'remind harith']]);

        Http::assertSent(fn (Request $r) => ($r->data()['to'] ?? null) === '971501234567'
            && str_contains($r->data()['text']['body'] ?? '', 'kind reminder for the payment')
            && str_contains($r->data()['text']['body'] ?? '', 'https://pay.ziina.com/h1'));
        Http::assertSent(fn (Request $r) => ($r->data()['to'] ?? null) === self::ADMIN_1
            && str_contains($r->data()['text']['body'] ?? '', 'Reminder sent to Harith Ali'));
    }

    public function test_remind_by_replying_to_my_message(): void
    {
        $booking = $this->setUpBooking();
        $admin = Contact::where('wa_id', self::ADMIN_1)->sole();
        $alert = Message::create(['contact_id' => $admin->id, 'direction' => 'out', 'author' => 'bot', 'type' => 'text',
            'wa_message_id' => 'wamid.alert.1', 'body' => "✅ Link sent to Harith Ali for {$booking->reference}."]);

        $this->post_(self::ADMIN_1, ['type' => 'text', 'text' => ['body' => 'Remind'], 'context' => ['id' => $alert->wa_message_id]]);

        Http::assertSent(fn (Request $r) => ($r->data()['to'] ?? null) === '971501234567'
            && str_contains($r->data()['text']['body'] ?? '', 'kind reminder'));
    }

    private function post_(string $from, array $message): void
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
