<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Services\BotSwitch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BotSwitchTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT = '971500000001';
    private const ADMIN_1 = '971500000091';
    private const ADMIN_2 = '971500000092';

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'api.anthropic.com')) {
                return Http::response(['content' => [['type' => 'text', 'text' => 'Welcome to Football Lab ⚽']], 'stop_reason' => 'end_turn']);
            }

            return Http::response(['messages' => [['id' => 'wamid.out.'.uniqid()]]]);
        });
    }

    public function test_bot_off_silences_clients_and_bot_on_restores(): void
    {
        Contact::create(['wa_id' => self::ADMIN_2, 'last_inbound_at' => now()]);

        $this->whatsapp(self::ADMIN_1, 'bot off');
        $this->assertTrue(BotSwitch::isOff());
        Http::assertSent(fn (Request $r) => ($r->data()['to'] ?? null) === self::ADMIN_2
            && str_contains($r->data()['text']['body'] ?? '', 'Bot turned OFF'));

        $readsBefore = $this->readCount();
        $this->whatsapp(self::CLIENT, 'Hi, any games tonight?');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'anthropic'));
        Http::assertNotSent(fn (Request $r) => ($r->data()['to'] ?? null) === self::CLIENT);
        // no blue ticks for the client while off (admin messages may still be marked read)
        $this->assertSame($readsBefore, $this->readCount());

        $this->artisan('bookings:housekeeping')->assertSuccessful();

        $this->whatsapp(self::ADMIN_1, 'bot status');
        Http::assertSent(fn (Request $r) => ($r->data()['to'] ?? null) === self::ADMIN_1
            && str_contains($r->data()['text']['body'] ?? '', 'Bot is OFF since'));

        $this->whatsapp(self::ADMIN_1, 'bot on');
        $this->assertFalse(BotSwitch::isOff());

        $readsBefore = $this->readCount();
        $this->whatsapp(self::CLIENT, 'Hello again');
        $this->assertSame($readsBefore + 1, $this->readCount());
        Http::assertSent(fn (Request $r) => ($r->data()['to'] ?? null) === self::CLIENT
            && ($r->data()['text']['body'] ?? null) === 'Welcome to Football Lab ⚽');
    }

    private function readCount(): int
    {
        return Http::recorded(fn (Request $r) => ($r->data()['status'] ?? null) === 'read')->count();
    }

    private function whatsapp(string $from, string $text): void
    {
        $json = json_encode(['entry' => [['changes' => [[
            'field' => 'messages',
            'value' => [
                'contacts' => [['wa_id' => $from, 'profile' => ['name' => 'Test']]],
                'messages' => [['id' => 'wamid.in.'.uniqid(), 'from' => $from, 'timestamp' => (string) time(),
                    'type' => 'text', 'text' => ['body' => $text]]],
            ],
        ]]]]]);

        $this->call('POST', '/api/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $json, 'test-secret'),
        ], $json)->assertOk();
    }
}
