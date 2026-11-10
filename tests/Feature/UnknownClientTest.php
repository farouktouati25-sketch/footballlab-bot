<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Contact;
use App\Models\Game;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UnknownClientTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_1 = '971500000091';
    private const CLIENT = '971501112222';

    private array $claude = [];
    private array $prompts = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['fbl.ask_admin_unknown' => true]);
        Http::fake(function (Request $r) {
            if (str_contains($r->url(), 'anthropic')) {
                $this->prompts[] = (string) ($r->data()['system'] ?? json_encode($r->data()['system'] ?? ''));

                return Http::response(array_shift($this->claude) ?? ['content' => [['type' => 'text', 'text' => 'Okay bro, see you later 👊 Pay on the spot or by link?']], 'stop_reason' => 'end_turn']);
            }

            return Http::response(['messages' => [['id' => 'wamid.out.'.uniqid()]]]);
        });
        Contact::create(['wa_id' => self::ADMIN_1, 'last_inbound_at' => now()]);
    }

    public function test_unknown_number_waits_for_the_admin_then_is_treated_as_old_client(): void
    {
        $this->whatsapp(self::CLIENT, ['type' => 'text', 'text' => ['body' => 'Add me for today']]);

        // Silent to the client, admins asked.
        Http::assertNotSent(fn (Request $r) => ($r->data()['to'] ?? null) === self::CLIENT);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'anthropic'));
        $client = Contact::where('wa_id', self::CLIENT)->sole();
        Http::assertSent(fn (Request $r) => ($r->data()['to'] ?? null) === self::ADMIN_1
            && str_contains($r->data()['interactive']['body']['text'] ?? '', 'Do you know +'.self::CLIENT)
            && str_contains(json_encode($r->data()), "who:{$client->id}:old"));

        // A second message doesn't ask again.
        $this->whatsapp(self::CLIENT, ['type' => 'text', 'text' => ['body' => 'Bro?']]);
        $this->assertSame(1, Http::recorded(fn (Request $r) => ($r->data()['to'] ?? null) === self::ADMIN_1
            && str_contains(json_encode($r->data(), JSON_UNESCAPED_UNICODE), 'Do you know +'))->count());

        // Admin taps "Old client": the bot answers right away as with a regular, offering cash or link.
        $this->whatsapp(self::ADMIN_1, ['type' => 'interactive', 'interactive' => ['type' => 'button_reply',
            'button_reply' => ['id' => "who:{$client->id}:old", 'title' => '✅ Old client']]]);

        $this->assertSame('old', $client->fresh()->client_type);
        $this->assertTrue($client->fresh()->canPayOnSpot());
        Http::assertSent(fn (Request $r) => ($r->data()['to'] ?? null) === self::CLIENT
            && str_contains($r->data()['text']['body'] ?? '', 'Pay on the spot or by link'));
        $prompt = end($this->prompts);
        $this->assertStringContainsString('RETURNING player', $prompt);
        $this->assertStringContainsString('this client may pay on the spot', $prompt);
    }

    public function test_old_client_paying_on_the_spot_is_booked_straight_away(): void
    {
        $client = Contact::create(['wa_id' => self::CLIENT, 'name' => 'Isa', 'client_type' => 'old', 'last_inbound_at' => now()]);
        $game = Game::create(['starts_at' => now()->addHours(5), 'venue' => 'Dubai Schools Al Barsha', 'format' => '8v8', 'price' => 53, 'capacity' => 16, 'status' => 'published']);
        $this->claude = [
            ['content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'pay_later', 'input' => ['game_id' => $game->id, 'player_name' => 'Isa', 'players' => 1, 'what_they_said' => 'on the spot']]], 'stop_reason' => 'tool_use'],
            ['content' => [['type' => 'text', 'text' => 'Done bro, see you there ⚽']], 'stop_reason' => 'end_turn'],
        ];
        $this->whatsapp(self::CLIENT, ['type' => 'text', 'text' => ['body' => 'On the spot']]);

        $booking = Booking::sole();
        $this->assertSame('confirmed', $booking->status);
        $this->assertTrue($booking->wantsCash());
        Http::assertSent(fn (Request $r) => ($r->data()['to'] ?? null) === self::CLIENT
            && ($r->data()['text']['body'] ?? '') === 'Done bro, see you there ⚽');
        Http::assertSent(fn (Request $r) => ($r->data()['to'] ?? null) === self::ADMIN_1
            && str_contains($r->data()['text']['body'] ?? '', 'pays on the spot'));
    }

    public function test_nobody_answers_in_15_minutes_so_the_bot_replies_anyway(): void
    {
        $this->whatsapp(self::CLIENT, ['type' => 'text', 'text' => ['body' => 'Any game tonight?']]);
        Http::assertNotSent(fn (Request $r) => ($r->data()['to'] ?? null) === self::CLIENT);

        $this->travel(16)->minutes();
        Contact::where('wa_id', self::ADMIN_1)->update(['last_inbound_at' => now()]);
        $this->artisan('bookings:housekeeping')->assertSuccessful();

        $this->assertSame('guess', Contact::where('wa_id', self::CLIENT)->sole()->client_type);
        Http::assertSent(fn (Request $r) => ($r->data()['to'] ?? null) === self::CLIENT);
        $this->assertStringContainsString('FIRST, CHECK WHO THIS REALLY IS', end($this->prompts));
    }

    public function test_ad_leads_and_new_answer_skip_or_get_the_lead_flow(): void
    {
        // From an ad: no question to the admin.
        $this->whatsapp('971503334444', ['type' => 'text', 'text' => ['body' => 'Hi'], 'referral' => ['source_type' => 'ad', 'source_id' => 'AD1']]);
        Http::assertNotSent(fn (Request $r) => str_contains(json_encode($r->data(), JSON_UNESCAPED_UNICODE), 'Do you know +971503334444'));
        Http::assertSent(fn (Request $r) => ($r->data()['to'] ?? null) === '971503334444');

        // Admin says "new" by text.
        $this->whatsapp(self::CLIENT, ['type' => 'text', 'text' => ['body' => 'Hello']]);
        $this->whatsapp(self::ADMIN_1, ['type' => 'text', 'text' => ['body' => 'new '.self::CLIENT]]);
        $this->assertSame('new', Contact::where('wa_id', self::CLIENT)->sole()->client_type);
        $this->assertStringContainsString('NEW LEAD', end($this->prompts));
        $this->assertStringNotContainsString('this client may pay on the spot', end($this->prompts));
    }

    private function whatsapp(string $from, array $message): void
    {
        $json = json_encode(['entry' => [['changes' => [[
            'field' => 'messages',
            'value' => [
                'contacts' => [['wa_id' => $from, 'profile' => ['name' => 'Isa']]],
                'messages' => [$message + ['id' => 'wamid.in.'.uniqid(), 'from' => $from, 'timestamp' => (string) time()]],
            ],
        ]]]]]);

        $this->call('POST', '/api/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $json, 'test-secret'),
        ], $json)->assertOk();
    }
}
