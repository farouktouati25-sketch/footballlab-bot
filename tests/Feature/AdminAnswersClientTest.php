<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminAnswersClientTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT = '971500000001';
    private const ADMIN_1 = '971500000091';

    private array $claude = [];

    public function test_admin_replies_to_the_question_and_the_client_gets_it(): void
    {
        Http::fake(function (Request $r) {
            if (str_contains($r->url(), 'anthropic')) {
                return Http::response(array_shift($this->claude) ?? ['content' => [['type' => 'text', 'text' => 'Let me check with the team and come back to you 👊']], 'stop_reason' => 'end_turn']);
            }

            return Http::response(['messages' => [['id' => 'wamid.out.'.uniqid()]]]);
        });
        Contact::create(['wa_id' => self::ADMIN_1, 'last_inbound_at' => now()]);
        $date = now('Asia/Dubai')->addDays(2)->format('Y-m-d');
        $this->claude = [
            ['content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'remember_availability', 'input' => ['date' => $date]]], 'stop_reason' => 'tool_use'],
            ['content' => [['type' => 'text', 'text' => 'Let me check with the team and come back to you 👊']], 'stop_reason' => 'end_turn'],
        ];

        $this->post_(self::CLIENT, ['type' => 'text', 'text' => ['body' => 'Any game Monday?']]);

        $alert = Message::where('payload->kind', 'client_question')->where('contact_id', Contact::where('wa_id', self::ADMIN_1)->value('id'))->sole();
        $this->assertStringContainsString('Reply to THIS message', (string) $alert->body);

        $this->post_(self::ADMIN_1, ['type' => 'text', 'text' => ['body' => 'Yes we play Monday 9 PM, format confirmed tomorrow'],
            'context' => ['id' => $alert->wa_message_id]]);

        Http::assertSent(fn (Request $r) => ($r->data()['to'] ?? null) === self::CLIENT
            && ($r->data()['text']['body'] ?? '') === 'Yes we play Monday 9 PM, format confirmed tomorrow');
        Http::assertSent(fn (Request $r) => ($r->data()['to'] ?? null) === self::ADMIN_1
            && str_contains($r->data()['text']['body'] ?? '', 'Sent to'));
    }

    public function test_admin_can_chat_with_the_bot(): void
    {
        $game = \App\Models\Game::create(['starts_at' => now()->addHours(5), 'venue' => 'Al Safa Sports Ground', 'format' => '7v7', 'price' => 53, 'capacity' => 14, 'status' => 'published']);
        \App\Models\Booking::create(['game_id' => $game->id, 'player_name' => 'Isah', 'players' => 3, 'amount' => 159, 'status' => 'confirmed', 'source' => 'post']);
        $seen = [];
        Http::fake(function (Request $r) use (&$seen, $game) {
            if (str_contains($r->url(), 'anthropic')) {
                $seen[] = $r->data();
                $this->assertStringContainsString('private assistant of the FootballLab DXB team', $r->data()['system']);

                return Http::response(count($seen) === 1
                    ? ['content' => [['type' => 'tool_use', 'id' => 'a1', 'name' => 'game_players', 'input' => ['game_id' => $game->id]]], 'stop_reason' => 'tool_use']
                    : ['content' => [['type' => 'text', 'text' => 'Isah is in with 3 players ✅']], 'stop_reason' => 'end_turn']);
            }

            return Http::response(['messages' => [['id' => 'wamid.out.'.uniqid()]]]);
        });
        Contact::create(['wa_id' => self::ADMIN_1, 'last_inbound_at' => now()]);

        $this->post_(self::ADMIN_1, ['type' => 'text', 'text' => ['body' => 'Is Isah in tonight?']]);

        $this->assertStringContainsString('Isah', json_encode($seen[1]['messages']));
        Http::assertSent(fn (Request $r) => ($r->data()['to'] ?? null) === self::ADMIN_1
            && ($r->data()['text']['body'] ?? '') === 'Isah is in with 3 players ✅');
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
