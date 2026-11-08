<?php

namespace Tests\Feature;

use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HandoffReminderTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT = '971500000001';
    private const ADMIN_1 = '971500000091';

    private array $claude = [];

    public function test_team_request_is_handed_off_and_admins_are_reminded_until_someone_replies(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'api.anthropic.com')) {
                return Http::response(array_shift($this->claude) ?? ['content' => [['type' => 'text', 'text' => '']], 'stop_reason' => 'end_turn']);
            }

            return Http::response(['messages' => [['id' => 'wamid.out.'.uniqid()]]]);
        });
        Contact::create(['wa_id' => self::ADMIN_1, 'last_inbound_at' => now()]);

        $this->claude = [['content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'handoff_to_human',
            'input' => ['reason' => 'wants to bring his own team of 10 to play against us']]], 'stop_reason' => 'tool_use']];
        $this->inbound(['type' => 'text', 'text' => ['body' => 'Can I bring my team of 10 to play vs you guys?']]);

        $client = Contact::where('wa_id', self::CLIENT)->sole();
        $this->assertNotNull($client->handoff_at);
        $this->assertTrue($client->botIsPaused());
        Http::assertSent(fn (Request $r) => ($r->data()['to'] ?? null) === self::ADMIN_1
            && str_contains($r->data()['text']['body'] ?? '', 'own team of 10'));
        Http::assertNotSent(fn (Request $r) => ($r->data()['to'] ?? null) === self::CLIENT && isset($r->data()['text']));

        $reminders = fn () => Http::recorded(fn (Request $r) => str_contains($r->data()['text']['body'] ?? '', 'Still waiting for you'))->count();

        $this->travel(10)->minutes();
        $this->artisan('bookings:housekeeping');
        $this->assertSame(0, $reminders());

        Contact::where('wa_id', self::ADMIN_1)->update(['last_inbound_at' => now()]);
        $this->travel(6)->minutes();
        $this->artisan('bookings:housekeeping');
        $this->assertSame(1, $reminders());

        $this->travel(45)->minutes();
        $this->artisan('bookings:housekeeping');
        $this->assertSame(2, $reminders());

        $this->travel(3)->hours();
        $this->artisan('bookings:housekeeping');
        $this->assertSame(2, $reminders());

        // `chats` lists it; a reply from the phone clears it.
        $this->inbound(['type' => 'text', 'text' => ['body' => 'chats']], self::ADMIN_1);
        Http::assertSent(fn (Request $r) => str_contains($r->data()['text']['body'] ?? '', 'Chats waiting for you'));

        $this->post_(['entry' => [['changes' => [['field' => 'smb_message_echoes', 'value' => ['message_echoes' => [[
            'from' => '971503741554', 'to' => self::CLIENT, 'id' => 'wamid.echo.1', 'type' => 'text', 'text' => ['body' => 'Hey bro, sure, call me'],
        ]]]]]]]]);
        $this->assertNull($client->fresh()->handoff_at);
    }

    private function inbound(array $message, string $from = self::CLIENT): void
    {
        $this->post_(['entry' => [['changes' => [[
            'field' => 'messages',
            'value' => [
                'contacts' => [['wa_id' => $from, 'profile' => ['name' => 'Karim']]],
                'messages' => [$message + ['id' => 'wamid.in.'.uniqid(), 'from' => $from, 'timestamp' => (string) time()]],
            ],
        ]]]]]);
    }

    private function post_(array $payload): void
    {
        $json = json_encode($payload);
        $this->call('POST', '/api/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $json, 'test-secret'),
        ], $json)->assertOk();
    }
}
