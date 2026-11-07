<?php

namespace Tests\Feature;

use App\Models\AvailabilityRequest;
use App\Models\Booking;
use App\Models\Contact;
use App\Models\Game;
use App\Services\Booking\ReminderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RemindersTest extends TestCase
{
    use RefreshDatabase;

    private array $claude = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'api.anthropic.com')) {
                return Http::response(array_shift($this->claude) ?? [
                    'content' => [['type' => 'text', 'text' => 'ok']], 'stop_reason' => 'end_turn',
                ]);
            }

            return Http::response(['messages' => [['id' => 'wamid.out.'.uniqid()]]]);
        });
    }

    private function nextFriday(): Carbon
    {
        return Carbon::now(config('fbl.timezone'))->next(Carbon::FRIDAY)->setTime(21, 0);
    }

    public function test_new_joiner_says_next_friday_and_it_is_saved(): void
    {
        $friday = $this->nextFriday()->toDateString();
        $this->claude = [
            ['content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'remember_availability', 'input' => ['date' => $friday]]], 'stop_reason' => 'tool_use'],
            ['content' => [['type' => 'text', 'text' => "Saved ✅ We'll message you when Friday's game is posted."]], 'stop_reason' => 'end_turn'],
        ];

        $this->inbound('971500000001', "I'll play next Friday");

        $req = AvailabilityRequest::sole();
        $this->assertSame($friday, $req->play_date->toDateString());
        Http::assertSent(fn (Request $r) => str_contains($r->data()['text']['body'] ?? '', 'Saved ✅'));
    }

    public function test_publishing_reminds_people_who_asked_and_last_weeks_players_once(): void
    {
        $friday = $this->nextFriday();
        $asker = Contact::create(['wa_id' => '971500000001', 'name' => 'Ali Hassan', 'last_inbound_at' => now()]);
        AvailabilityRequest::create(['contact_id' => $asker->id, 'play_date' => $friday->toDateString()]);

        // Played last Friday; chat window closed → template.
        $regular = Contact::create(['wa_id' => '971500000002', 'name' => 'Omar', 'last_inbound_at' => now()->subDays(6)]);
        $old = Game::create(['starts_at' => $friday->copy()->subWeek()->utc(), 'venue' => 'Al Safa', 'price' => 50, 'capacity' => 14, 'status' => 'published']);
        Booking::create(['contact_id' => $regular->id, 'game_id' => $old->id, 'player_name' => 'Omar', 'players' => 1, 'amount' => 50, 'status' => 'confirmed']);

        // Already booked for this Friday: no reminder.
        $booked = Contact::create(['wa_id' => '971500000003', 'name' => 'Sami', 'last_inbound_at' => now()]);
        Booking::create(['contact_id' => $booked->id, 'game_id' => $old->id, 'player_name' => 'Sami', 'players' => 1, 'amount' => 50, 'status' => 'confirmed']);
        $game = Game::create(['starts_at' => $friday->copy()->utc(), 'venue' => 'Al Safa', 'price' => 50, 'capacity' => 14, 'status' => 'published']);
        Booking::create(['contact_id' => $booked->id, 'game_id' => $game->id, 'player_name' => 'Sami', 'players' => 1, 'amount' => 50, 'status' => 'confirmed']);

        $service = app(ReminderService::class);
        $this->assertSame(2, $service->notifyForGame($game));

        Http::assertSent(fn (Request $r) => ($r->data()['to'] ?? null) === '971500000001'
            && str_contains($r->data()['text']['body'] ?? '', 'the game is on')
            && str_contains($r->data()['text']['body'] ?? '', 'STOP'));
        Http::assertSent(fn (Request $r) => ($r->data()['to'] ?? null) === '971500000002'
            && ($r->data()['template']['name'] ?? null) === 'game_reminder');
        Http::assertNotSent(fn (Request $r) => ($r->data()['to'] ?? null) === '971500000003' && str_contains(json_encode($r->data()), 'game'));

        // A second game the same day doesn't message them again.
        $second = Game::create(['starts_at' => $friday->copy()->addHour()->utc(), 'venue' => 'Al Safa', 'price' => 50, 'capacity' => 14, 'status' => 'published']);
        $this->assertSame(0, $service->notifyForGame($second));
    }

    public function test_stop_opts_out_of_reminders(): void
    {
        $this->inbound('971500000001', 'STOP');

        $contact = Contact::where('wa_id', '971500000001')->sole();
        $this->assertNotNull($contact->reminders_opted_out_at);

        $friday = $this->nextFriday();
        AvailabilityRequest::create(['contact_id' => $contact->id, 'play_date' => $friday->toDateString()]);
        $game = Game::create(['starts_at' => $friday->copy()->utc(), 'venue' => 'Al Safa', 'price' => 50, 'capacity' => 14, 'status' => 'published']);
        $this->assertSame(0, app(ReminderService::class)->notifyForGame($game));
    }

    public function test_business_app_greeting_does_not_pause_the_bot(): void
    {
        $greeting = 'Hi! Thanks for contacting Football Lab DXB ⚽';
        // Seen once before on another chat (that lead got paused, which is why FBL_AUTO_REPLY_TEXTS exists).
        $this->echo('971500000005', $greeting);

        $this->echo('971500000006', $greeting);
        $this->assertFalse(Contact::where('wa_id', '971500000006')->sole()->botIsPaused());

        // With the text configured, even the very first lead isn't paused.
        config(['fbl.auto_reply_texts' => [$greeting]]);
        $this->echo('971500000007', $greeting);
        $this->assertFalse(Contact::where('wa_id', '971500000007')->sole()->botIsPaused());

        // A real reply typed by the team still pauses the bot.
        $this->echo('971500000008', 'Hey bro, Farouk here');
        $this->assertTrue(Contact::where('wa_id', '971500000008')->sole()->botIsPaused());
    }

    public function test_client_wanting_to_pay_cash_goes_to_admins(): void
    {
        Contact::create(['wa_id' => '971500000091', 'last_inbound_at' => now()]);
        $client = Contact::create(['wa_id' => '971500000001', 'name' => 'Ali', 'last_inbound_at' => now()]);
        $game = Game::create(['starts_at' => now()->addDays(2), 'venue' => 'Al Safa', 'price' => 50, 'capacity' => 14, 'status' => 'published']);
        $booking = Booking::create(['contact_id' => $client->id, 'game_id' => $game->id, 'player_name' => 'Ali', 'players' => 1, 'amount' => 50, 'status' => 'pending_payment']);

        $this->claude = [
            ['content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'pay_cash_on_spot', 'input' => ['booking_reference' => $booking->reference]]], 'stop_reason' => 'tool_use'],
            ['content' => [['type' => 'text', 'text' => 'Sure bro, asking the team 👊']], 'stop_reason' => 'end_turn'],
        ];
        $this->inbound('971500000001', "I'll pay cash on the spot");

        $this->assertSame('awaiting_approval', $booking->fresh()->status);
        $this->assertTrue($booking->fresh()->wantsCash());
        Http::assertSent(fn (Request $r) => ($r->data()['to'] ?? null) === '971500000091'
            && str_contains($r->data()['interactive']['body']['text'] ?? '', 'PAY CASH ON THE SPOT')
            && str_contains(json_encode($r->data(), JSON_UNESCAPED_UNICODE), 'Accept cash'));
    }

    private function inbound(string $from, string $text): void
    {
        $this->webhook(['field' => 'messages', 'value' => [
            'contacts' => [['wa_id' => $from, 'profile' => ['name' => 'Ali']]],
            'messages' => [['type' => 'text', 'text' => ['body' => $text], 'id' => 'wamid.in.'.uniqid(), 'from' => $from, 'timestamp' => (string) time()]],
        ]]);
    }

    private function echo(string $to, string $text): void
    {
        $this->webhook(['field' => 'smb_message_echoes', 'value' => [
            'message_echoes' => [['to' => $to, 'id' => 'wamid.echo.'.uniqid(), 'type' => 'text', 'text' => ['body' => $text]]],
        ]]);
    }

    private function webhook(array $change): void
    {
        $json = json_encode(['entry' => [['changes' => [$change]]]]);
        $this->call('POST', '/api/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $json, 'test-secret'),
        ], $json)->assertOk();
    }
}
