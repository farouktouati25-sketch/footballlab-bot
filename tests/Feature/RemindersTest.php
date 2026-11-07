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
        $this->assertSame($friday, substr((string) $req->play_date, 0, 10));
        Http::assertSent(fn (Request $r) => str_contains($r->data()['text']['body'] ?? '', 'Saved ✅'));
    }

    public function test_publishing_gives_admins_an_invite_list_and_bot_takes_over_after_admin_texts(): void
    {
        $friday = $this->nextFriday();
        Contact::create(['wa_id' => '971500000091', 'last_inbound_at' => now()]); // admin

        $asker = Contact::create(['wa_id' => '971500000001', 'name' => 'Ali Hassan', 'last_inbound_at' => now()]);
        AvailabilityRequest::create(['contact_id' => $asker->id, 'play_date' => $friday->toDateString()]);

        $usual = Contact::create(['wa_id' => '971500000004', 'name' => 'K', 'last_inbound_at' => now()]);
        app(ReminderService::class)->saveProfile($usual, 'Karim', ['Friday']);

        // Played 5 days ago, nothing since → lapsed.
        $lapsed = Contact::create(['wa_id' => '971500000002', 'name' => 'Omar']);
        $old = Game::create(['starts_at' => now()->subDays(5), 'venue' => 'Al Safa', 'price' => 50, 'capacity' => 14, 'status' => 'published']);
        Booking::create(['contact_id' => $lapsed->id, 'game_id' => $old->id, 'player_name' => 'Omar', 'players' => 1, 'amount' => 50, 'status' => 'confirmed']);

        // Played yesterday → too recent, not listed.
        $recent = Contact::create(['wa_id' => '971500000003', 'name' => 'Sami']);
        $y = Game::create(['starts_at' => now()->subDay(), 'venue' => 'Al Safa', 'price' => 50, 'capacity' => 14, 'status' => 'published']);
        Booking::create(['contact_id' => $recent->id, 'game_id' => $y->id, 'player_name' => 'Sami', 'players' => 1, 'amount' => 50, 'status' => 'confirmed']);

        $game = Game::create(['starts_at' => $friday->copy()->utc(), 'venue' => 'Al Safa', 'price' => 50, 'capacity' => 14, 'status' => 'published']);
        $this->assertSame(3, app(ReminderService::class)->notifyForGame($game));

        // The bot never messages players itself.
        Http::assertNotSent(fn (Request $r) => in_array($r->data()['to'] ?? null, ['971500000001', '971500000002', '971500000003', '971500000004'], true));
        Http::assertSent(fn (Request $r) => ($r->data()['to'] ?? null) === '971500000091'
            && str_contains($r->data()['text']['body'] ?? '', "1. Ali Hassan")
            && str_contains($r->data()['text']['body'] ?? '', "2. Karim")
            && str_contains($r->data()['text']['body'] ?? '', "3. Omar")
            && ! str_contains($r->data()['text']['body'] ?? '', 'Sami'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->data()['text']['body'] ?? '', 'You in? 👊'));

        // Admin texts Omar from the phone: the bot is NOT paused, so it answers Omar's reply.
        $this->echo('971500000002', 'Hey bro ⚽ Friday game is on!');
        $this->assertFalse($lapsed->fresh()->botIsPaused());

        // A second message from the phone to Omar = the team really talking → pause.
        $this->echo('971500000002', 'Call me when you can');
        $this->assertTrue($lapsed->fresh()->botIsPaused());

        // Same day again doesn't list them twice.
        $second = Game::create(['starts_at' => $friday->copy()->addHour()->utc(), 'venue' => 'Al Safa', 'price' => 50, 'capacity' => 14, 'status' => 'published']);
        $this->assertSame(0, app(ReminderService::class)->notifyForGame($second));
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

    public function test_players_sheet_lists_saved_profiles(): void
    {
        $c = Contact::create(['wa_id' => '971500000001', 'name' => 'Faro', 'last_inbound_at' => now()]);
        app(ReminderService::class)->saveProfile($c, 'Farouk', ['Sunday', 'fri']);
        $csv = app(ReminderService::class)->playersCsv();
        $this->assertStringContainsString('Farouk,Faro,+971500000001,"Sun Fri"', $csv);
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

    public function test_old_client_paying_after_the_game_goes_silently_to_admins(): void
    {
        Contact::create(['wa_id' => '971500000091', 'last_inbound_at' => now()]);
        Contact::create(['wa_id' => '971500000001', 'name' => 'Omar', 'last_inbound_at' => now()]);
        $game = Game::create(['starts_at' => now()->addDays(2), 'venue' => 'Al Safa', 'price' => 50, 'capacity' => 14, 'status' => 'published']);

        $this->claude = [
            ['content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'pay_later', 'input' => [
                'game_id' => $game->id, 'player_name' => 'Omar', 'players' => 1, 'what_they_said' => 'I always pay after the game']]], 'stop_reason' => 'tool_use'],
            ['content' => [['type' => 'text', 'text' => 'I will check with the team']], 'stop_reason' => 'end_turn'],
        ];
        $this->inbound('971500000001', 'Add me Friday, I pay after the game as usual');

        $booking = Booking::sole();
        $this->assertSame('awaiting_approval', $booking->status);
        $this->assertTrue($booking->wantsCash());
        // The client gets nothing; no payment link was asked for.
        Http::assertNotSent(fn (Request $r) => ($r->data()['to'] ?? null) === '971500000001');
        $this->assertNull($booking->link_requested_at);
        Http::assertSent(fn (Request $r) => ($r->data()['to'] ?? null) === '971500000091'
            && str_contains($r->data()['interactive']['body']['text'] ?? '', 'PAY LATER')
            && str_contains($r->data()['interactive']['body']['text'] ?? '', 'I always pay after the game')
            && str_contains(json_encode($r->data(), JSON_UNESCAPED_UNICODE), 'Book him'));

        // Admin taps "Send link" → not rejected, back to pay-first, admins asked for the link.
        $this->tap('reject:'.$booking->id);
        $this->assertSame('pending_payment', $booking->fresh()->status);
        $this->assertNotNull($booking->fresh()->link_requested_at);
        Http::assertSent(fn (Request $r) => ($r->data()['to'] ?? null) === '971500000091'
            && str_contains($r->data()['text']['body'] ?? '', 'needs a payment link'));
    }

    public function test_pay_later_approved_confirms_with_pay_after_game_note(): void
    {
        Contact::create(['wa_id' => '971500000091', 'last_inbound_at' => now()]);
        $client = Contact::create(['wa_id' => '971500000001', 'name' => 'Omar', 'last_inbound_at' => now()]);
        $game = Game::create(['starts_at' => now()->addDays(2), 'venue' => 'Al Safa', 'price' => 50, 'capacity' => 14, 'status' => 'published']);
        $booking = Booking::create(['contact_id' => $client->id, 'game_id' => $game->id, 'player_name' => 'Omar', 'players' => 1,
            'amount' => 50, 'status' => 'awaiting_approval', 'payment_note' => Booking::PAY_LATER_NOTE]);

        $this->tap('approve:'.$booking->id);

        $this->assertSame('confirmed', $booking->fresh()->status);
        Http::assertSent(fn (Request $r) => ($r->data()['to'] ?? null) === '971500000001'
            && str_contains($r->data()['text']['body'] ?? '', 'pay after the game'));
    }

    public function test_handoff_sends_nothing_to_the_client(): void
    {
        Contact::create(['wa_id' => '971500000091', 'last_inbound_at' => now()]);
        $this->claude = [
            ['content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'handoff_to_human', 'input' => ['reason' => 'Asks about a corporate event']]], 'stop_reason' => 'tool_use'],
            ['content' => [['type' => 'text', 'text' => 'A team member will reply shortly']], 'stop_reason' => 'end_turn'],
        ];
        $this->inbound('971500000001', 'Can you host a company tournament for 40 people?');

        Http::assertNotSent(fn (Request $r) => ($r->data()['to'] ?? null) === '971500000001');
        Http::assertSent(fn (Request $r) => ($r->data()['to'] ?? null) === '971500000091'
            && str_contains($r->data()['text']['body'] ?? '', 'company tournament'));
    }

    private function tap(string $buttonId): void
    {
        $this->webhook(['field' => 'messages', 'value' => [
            'contacts' => [['wa_id' => '971500000091', 'profile' => ['name' => 'Farouk']]],
            'messages' => [['type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => $buttonId, 'title' => 'x']],
                'id' => 'wamid.in.'.uniqid(), 'from' => '971500000091', 'timestamp' => (string) time()]],
        ]]);
    }

    public function test_freed_spot_asks_admin_then_offers_to_next_waiting_client(): void
    {
        Contact::create(['wa_id' => '971500000091', 'last_inbound_at' => now()]);
        $game = Game::create(['starts_at' => now()->addDays(2), 'venue' => 'Al Safa', 'price' => 50, 'capacity' => 1, 'status' => 'published']);
        Booking::create(['contact_id' => null, 'source' => 'manual', 'game_id' => $game->id, 'player_name' => 'Omar', 'players' => 1, 'amount' => 50, 'status' => 'confirmed']);
        $waiting = Contact::create(['wa_id' => '971500000001', 'name' => 'Ali', 'last_inbound_at' => now()]);
        $entry = \App\Models\WaitlistEntry::create(['contact_id' => $waiting->id, 'game_id' => $game->id, 'players' => 1]);

        // Omar drops out → admin is asked.
        $this->inbound('971500000091', "remove {$game->id} omar");
        $this->assertNotNull($entry->fresh()->admin_asked_at);
        Http::assertSent(fn (Request $r) => ($r->data()['to'] ?? null) === '971500000091'
            && str_contains($r->data()['interactive']['body']['text'] ?? '', 'Next on the waiting list: Ali'));

        // Asking again doesn't spam.
        $this->assertSame(0, app(\App\Services\Booking\RosterService::class)->offerFreedSpots($game));

        // Admin taps "Send offer" → client gets it.
        $this->webhook(['field' => 'messages', 'value' => [
            'contacts' => [['wa_id' => '971500000091', 'profile' => ['name' => 'Farouk']]],
            'messages' => [['type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'wl_send:'.$entry->id, 'title' => 'Send offer']],
                'id' => 'wamid.in.'.uniqid(), 'from' => '971500000091', 'timestamp' => (string) time()]],
        ]]);
        $this->assertNotNull($entry->fresh()->offer_sent_at);
        Http::assertSent(fn (Request $r) => ($r->data()['to'] ?? null) === '971500000001'
            && str_contains($r->data()['text']['body'] ?? '', 'A place just opened'));
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
