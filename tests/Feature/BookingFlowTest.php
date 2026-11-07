<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Contact;
use App\Models\Game;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BookingFlowTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT = '971500000001';
    private const ADMIN_1 = '971500000091';
    private const ADMIN_2 = '971500000092';

    /** Queued fake Claude responses, consumed in order. */
    private array $claude = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, 'api.anthropic.com')) {
                return Http::response(array_shift($this->claude) ?? $this->claudeText('fallback'));
            }
            if ($request->method() === 'POST' && str_ends_with($url, '/PHONE_ID/media')) {
                return Http::response(['id' => 'NEW_MEDIA']);
            }
            if (str_ends_with($url, '/v23.0/IN_MEDIA')) {
                return Http::response(['url' => 'https://lookaside.fbsbx.com/whatsapp/IN_MEDIA', 'mime_type' => 'image/jpeg']);
            }
            if (str_contains($url, 'lookaside.fbsbx.com')) {
                return Http::response('fake-image-bytes');
            }

            return Http::response(['messages' => [['id' => 'wamid.out.'.uniqid()]]]);
        });
    }

    public function test_webhook_verification(): void
    {
        $this->get('/api/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=verify-me&hub.challenge=42')
            ->assertOk()->assertSee('42');

        $this->get('/api/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=42')
            ->assertForbidden();
    }

    public function test_rejects_bad_signature(): void
    {
        $this->postJson('/api/webhooks/whatsapp', ['entry' => []], ['X-Hub-Signature-256' => 'sha256=nope'])
            ->assertStatus(401);
    }

    public function test_client_books_through_the_agent(): void
    {
        $game = $this->publishedGame();

        $this->claude = [
            $this->claudeTool('t1', 'list_games', []),
            $this->claudeTool('t2', 'create_booking', ['game_id' => $game->id, 'player_name' => 'Ali', 'players' => 2]),
            $this->claudeText('Booked! Pay 100 AED here: https://pay.example.com/fbl'),
        ];

        $this->inbound(['id' => 'wamid.in.1', 'from' => self::CLIENT, 'type' => 'text', 'text' => ['body' => 'I want to play Thursday, 2 of us']]);

        $booking = Booking::sole();
        $this->assertSame('pending_payment', $booking->status);
        $this->assertSame(2, $booking->players);
        $this->assertEquals(100, (float) $booking->amount);

        Http::assertSent(fn (Request $r) => ($r->data()['to'] ?? null) === self::CLIENT
            && ($r->data()['text']['body'] ?? null) === 'Booked! Pay 100 AED here: https://pay.example.com/fbl');

        // The tool_use input sent back to Claude must stay a JSON object, not an array.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'anthropic')
            && str_contains($r->body(), '"name":"list_games","input":{}'));
    }

    public function test_duplicate_webhook_is_processed_once(): void
    {
        $this->claude = [$this->claudeText('Welcome to Football Lab ⚽'), $this->claudeText('second')];
        $msg = ['id' => 'wamid.dup', 'from' => self::CLIENT, 'type' => 'text', 'text' => ['body' => 'hi']];

        $this->inbound($msg);
        $this->inbound($msg);

        $this->assertSame(1, Contact::where('wa_id', self::CLIENT)->first()->messages()->where('direction', 'in')->count());
        Http::assertSentCount(3); // first delivery: markRead + Claude + reply. The retry is dropped before any call.
    }

    public function test_screenshot_sends_booking_to_both_admins(): void
    {
        [$booking] = $this->pendingBooking();
        Contact::create(['wa_id' => self::ADMIN_1, 'last_inbound_at' => now()]);          // window open
        Contact::create(['wa_id' => self::ADMIN_2, 'last_inbound_at' => now()->subDays(2)]); // window closed

        $this->inbound(['id' => 'wamid.in.img', 'from' => self::CLIENT, 'type' => 'image',
            'image' => ['id' => 'IN_MEDIA', 'mime_type' => 'image/jpeg']]);

        $booking->refresh();
        $this->assertSame('awaiting_approval', $booking->status);
        $this->assertSame('NEW_MEDIA', $booking->payment_proof_media_id);

        // Admin 1: interactive message with the screenshot as header and Confirm/Reject buttons.
        Http::assertSent(fn (Request $r) => ($r->data()['to'] ?? null) === self::ADMIN_1
            && ($r->data()['type'] ?? null) === 'interactive'
            && $r->data()['interactive']['header']['image']['id'] === 'NEW_MEDIA'
            && $r->data()['interactive']['action']['buttons'][0]['reply']['id'] === 'approve:'.$booking->id);

        // Admin 2: approved template with the same payloads (window closed).
        Http::assertSent(fn (Request $r) => ($r->data()['to'] ?? null) === self::ADMIN_2
            && ($r->data()['type'] ?? null) === 'template'
            && $r->data()['template']['name'] === 'booking_approval_request'
            && $r->data()['template']['components'][1]['parameters'][0]['payload'] === 'approve:'.$booking->id);

        // Client is told we're checking.
        Http::assertSent(fn (Request $r) => ($r->data()['to'] ?? null) === self::CLIENT
            && str_contains($r->data()['text']['body'] ?? '', "Received ✅"));
    }

    public function test_first_admin_tap_wins_and_client_is_confirmed(): void
    {
        [$booking] = $this->pendingBooking();
        $booking->update(['status' => 'awaiting_approval', 'payment_reported_at' => now()]);
        Contact::create(['wa_id' => self::ADMIN_2, 'last_inbound_at' => now()->subDays(2)]);

        $this->inbound(['id' => 'wamid.tap.1', 'from' => self::ADMIN_1, 'type' => 'interactive',
            'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'approve:'.$booking->id, 'title' => '✅ Confirm']]]);

        $booking->refresh();
        $this->assertSame('confirmed', $booking->status);
        $this->assertSame(self::ADMIN_1, $booking->decided_by);

        Http::assertSent(fn (Request $r) => ($r->data()['to'] ?? null) === self::CLIENT
            && str_contains($r->data()['text']['body'] ?? '', "You're in"));
        Http::assertSent(fn (Request $r) => ($r->data()['to'] ?? null) === self::ADMIN_2
            && ($r->data()['template']['name'] ?? null) === 'admin_alert'
            && str_contains($r->data()['template']['components'][0]['parameters'][0]['text'], 'confirmed by Farouk'));

        // Partner taps Reject late on the template button: nothing changes.
        $this->inbound(['id' => 'wamid.tap.2', 'from' => self::ADMIN_2, 'type' => 'button',
            'button' => ['payload' => 'reject:'.$booking->id, 'text' => 'Reject']]);

        $this->assertSame('confirmed', $booking->fresh()->status);
        Http::assertSent(fn (Request $r) => ($r->data()['to'] ?? null) === self::ADMIN_2
            && str_contains($r->data()['text']['body'] ?? '', 'already confirmed by Farouk'));
    }

    public function test_human_reply_from_phone_pauses_the_bot(): void
    {
        $this->signedPost(['entry' => [['changes' => [[
            'field' => 'smb_message_echoes',
            'value' => ['message_echoes' => [[
                'from' => '971503741554', 'to' => self::CLIENT, 'id' => 'wamid.echo.1', 'type' => 'text', 'text' => ['body' => 'Hi, Farouk here'],
            ]]],
        ]]]]])->assertOk();

        $this->assertTrue(Contact::where('wa_id', self::CLIENT)->first()->botIsPaused());

        $this->inbound(['id' => 'wamid.in.2', 'from' => self::CLIENT, 'type' => 'text', 'text' => ['body' => 'thanks']]);

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'anthropic'));
    }

    public function test_admin_forwards_group_post_and_publishes_games(): void
    {
        $date = now()->setTimezone('Asia/Dubai')->addDays(2)->format('Y-m-d');
        $this->claude = [$this->claudeTool('s1', 'save_games', ['games' => [
            ['date' => $date, 'start_time' => '21:00', 'venue' => 'Al Safa Sports Ground, Nad Al Sheba', 'price' => 50, 'capacity' => 14, 'format' => '7-a-side'],
            ['date' => $date, 'start_time' => '22:30', 'venue' => 'Dubai Schools Al Barsha', 'price' => 60, 'capacity' => 12],
        ]])];

        $this->inbound(['id' => 'wamid.fwd', 'from' => self::ADMIN_1, 'type' => 'text', 'context' => ['forwarded' => true],
            'text' => ['body' => "This week ⚽\nThursday 9pm Al Safa 7v7 50 AED\nThursday 10:30pm Barsha 60 AED"]]);

        $this->assertSame(2, Game::where('status', 'draft')->count());
        $batch = Game::first()->batch_id;
        Http::assertSent(fn (Request $r) => ($r->data()['to'] ?? null) === self::ADMIN_1
            && ($r->data()['interactive']['action']['buttons'][0]['reply']['id'] ?? null) === 'publish:'.$batch);

        $this->inbound(['id' => 'wamid.pub', 'from' => self::ADMIN_1, 'type' => 'interactive',
            'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'publish:'.$batch, 'title' => '✅ Publish']]]);

        $this->assertSame(2, Game::where('status', 'published')->count());
        $this->assertSame('21:00', Game::orderBy('starts_at')->first()->localStart()->format('H:i'));
    }

    public function test_full_game_cannot_be_overbooked(): void
    {
        $game = $this->publishedGame(['capacity' => 2]);
        $other = Contact::create(['wa_id' => '971500000002']);
        Booking::create(['contact_id' => $other->id, 'game_id' => $game->id, 'player_name' => 'X', 'players' => 2, 'amount' => 100, 'status' => 'confirmed']);

        $result = app(\App\Services\Booking\BookingService::class)
            ->create(Contact::create(['wa_id' => self::CLIENT]), $game->id, 'Ali', 1);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('full', $result['error']);
    }

    public function test_360dialog_webhook_requires_shared_secret(): void
    {
        config(['whatsapp.provider' => '360dialog', 'whatsapp.webhook_secret' => 'a-very-long-shared-secret-123']);
        $payload = ['entry' => []];

        $this->postJson('/api/webhooks/whatsapp', $payload, ['X-Webhook-Secret' => 'wrong'])->assertStatus(401);
        $this->postJson('/api/webhooks/whatsapp', $payload, ['X-Webhook-Secret' => 'a-very-long-shared-secret-123'])->assertOk();
    }

    public function test_failed_rebooking_keeps_the_existing_hold(): void
    {
        $game = $this->publishedGame(['capacity' => 3]);
        $client = Contact::create(['wa_id' => self::CLIENT]);
        $service = app(\App\Services\Booking\BookingService::class);

        $first = $service->create($client, $game->id, 'Ali', 2);
        $this->assertTrue($first['ok']);

        $second = $service->create($client, $game->id, 'Ali', 4); // only 3 seats in total
        $this->assertFalse($second['ok']);
        $this->assertSame('pending_payment', $first['booking']->fresh()->status);

        $third = $service->create($client, $game->id, 'Ali', 3); // own hold counts as available
        $this->assertTrue($third['ok']);
        $this->assertSame('cancelled', $first['booking']->fresh()->status);
    }

    public function test_admin_cannot_confirm_past_capacity(): void
    {
        [$booking] = $this->pendingBooking();
        $booking->game->update(['capacity' => 1]);
        $other = Contact::create(['wa_id' => '971500000002']);
        Booking::create(['contact_id' => $other->id, 'game_id' => $booking->game_id, 'player_name' => 'X', 'players' => 1, 'amount' => 50, 'status' => 'confirmed']);
        $booking->update(['status' => 'awaiting_approval']);

        $this->inbound(['id' => 'wamid.tap.full', 'from' => self::ADMIN_1, 'type' => 'interactive',
            'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'approve:'.$booking->id, 'title' => '✅ Confirm']]]);

        $this->assertSame('awaiting_approval', $booking->fresh()->status);
        Http::assertSent(fn (Request $r) => ($r->data()['to'] ?? null) === self::ADMIN_1
            && str_contains($r->data()['text']['body'] ?? '', "Can't confirm"));
    }

    // ---------------------------------------------------------------- helpers

    private function publishedGame(array $overrides = []): Game
    {
        return Game::create($overrides + [
            'starts_at' => now()->addDays(2),
            'venue' => 'Al Safa Sports Ground, Nad Al Sheba',
            'price' => 50,
            'capacity' => 14,
            'status' => 'published',
        ]);
    }

    /** @return array{0:Booking,1:Contact} */
    private function pendingBooking(): array
    {
        $game = $this->publishedGame();
        $client = Contact::create(['wa_id' => self::CLIENT, 'name' => 'Ali', 'last_inbound_at' => now()]);
        $booking = Booking::create(['contact_id' => $client->id, 'game_id' => $game->id, 'player_name' => 'Ali',
            'players' => 1, 'amount' => 50, 'status' => 'pending_payment']);

        return [$booking, $client];
    }

    private function inbound(array $message): void
    {
        $this->signedPost(['object' => 'whatsapp_business_account', 'entry' => [['changes' => [[
            'field' => 'messages',
            'value' => [
                'messaging_product' => 'whatsapp',
                'contacts' => [['wa_id' => $message['from'], 'profile' => ['name' => 'Test']]],
                'messages' => [$message + ['timestamp' => (string) time()]],
            ],
        ]]]]])->assertOk();
    }

    private function signedPost(array $payload)
    {
        $json = json_encode($payload);

        return $this->call('POST', '/api/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $json, 'test-secret'),
        ], $json);
    }

    private function claudeText(string $text): array
    {
        return ['id' => 'msg', 'type' => 'message', 'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => $text]], 'stop_reason' => 'end_turn'];
    }

    private function claudeTool(string $id, string $name, array $input): array
    {
        return ['id' => 'msg', 'type' => 'message', 'role' => 'assistant',
            'content' => [['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => $input]], 'stop_reason' => 'tool_use'];
    }
}
