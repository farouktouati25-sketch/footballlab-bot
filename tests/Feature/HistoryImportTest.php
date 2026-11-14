<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Contact;
use App\Models\Game;
use App\Services\Booking\AdminHandler;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HistoryImportTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_1 = '971500000091';

    public function test_past_lists_are_saved_and_names_matched_to_saved_contacts(): void
    {
        $this->travelTo(Carbon::parse('2026-11-14 15:00', 'Asia/Dubai'));
        Http::fake(function (Request $r) {
            if (str_contains($r->url(), 'anthropic')) {
                // the history prompt asks for past dates
                $this->assertStringContainsString('ALREADY PLAYED', json_encode($r->data()));

                return Http::response(['content' => [['type' => 'tool_use', 'id' => 's1', 'name' => 'save_games', 'input' => ['games' => [
                    ['date' => '2026-11-10', 'start_time' => '21:00', 'venue' => 'Al Safa Sports Ground', 'price' => 53, 'capacity' => 14, 'format' => '7v7',
                        'players' => ['Ali Hassan', 'Omar', 'Khalid']],
                    ['date' => '2026-12-01', 'start_time' => '21:00', 'venue' => 'Al Safa Sports Ground', 'price' => 53, 'capacity' => 14, 'players' => ['Future']],
                ]]]], 'stop_reason' => 'tool_use']);
            }

            return Http::response(['messages' => [['id' => 'wamid.out.'.uniqid()]]]);
        });

        Contact::create(['wa_id' => self::ADMIN_1, 'last_inbound_at' => now()]);
        $ali = Contact::create(['wa_id' => '971501112222', 'name' => 'Ali Hassan']);
        $omar = Contact::create(['wa_id' => '971503334444', 'name' => 'Omar Football']);

        $this->whatsapp(self::ADMIN_1, ['type' => 'text', 'text' => ['body' => 'past']]);
        $this->whatsapp(self::ADMIN_1, ['type' => 'text', 'text' => ['body' => "TUESDAY 7v7 AL SAFA\n1. Ali Hassan\n2. Omar\n3. Khalid"], 'context' => ['forwarded' => true]]);
        // Forwarding the same list twice doesn't double it.
        $this->whatsapp(self::ADMIN_1, ['type' => 'text', 'text' => ['body' => "TUESDAY 7v7 AL SAFA\n1. Ali Hassan\n2. Omar\n3. Khalid"], 'context' => ['forwarded' => true]]);

        $game = Game::sole(); // the future one was ignored
        $this->assertTrue($game->starts_at->isPast());
        $this->assertSame(3, Booking::where('game_id', $game->id)->where('status', 'confirmed')->count());
        $this->assertSame($ali->id, Booking::where('player_name', 'Ali Hassan')->sole()->contact_id);
        $this->assertSame($omar->id, Booking::where('player_name', 'Omar')->sole()->contact_id); // unique first name
        $this->assertNull(Booking::where('player_name', 'Khalid')->sole()->contact_id);
        Http::assertSent(fn (Request $r) => ($r->data()['to'] ?? null) === self::ADMIN_1
            && str_contains($r->data()['text']['body'] ?? '', '3 player(s) saved, 2 matched'));

        $this->whatsapp(self::ADMIN_1, ['type' => 'text', 'text' => ['body' => 'done']]);
        Http::assertSent(fn (Request $r) => str_contains($r->data()['text']['body'] ?? '', 'History mode OFF'));
    }

    public function test_name_matching_refuses_ambiguous_names(): void
    {
        Contact::create(['wa_id' => '971501', 'name' => 'Ali One']);
        Contact::create(['wa_id' => '971502', 'name' => 'Ali Two']);
        $this->assertNull(AdminHandler::contactIdForName('Ali'));
        $this->assertNotNull(AdminHandler::contactIdForName('ali two'));
    }

    private function whatsapp(string $from, array $message): void
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
