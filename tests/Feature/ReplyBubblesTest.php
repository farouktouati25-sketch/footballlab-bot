<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReplyBubblesTest extends TestCase
{
    use RefreshDatabase;

    public function test_blank_lines_become_separate_whatsapp_messages(): void
    {
        Http::fake(function (Request $r) {
            if (str_contains($r->url(), 'anthropic')) {
                $this->assertStringContainsString('honestly first time we cant do this', $r->data()['system']);

                return Http::response(['content' => [['type' => 'text', 'text' => "hey bro\n\nwhich day suits you?"]], 'stop_reason' => 'end_turn']);
            }

            return Http::response(['messages' => [['id' => 'wamid.out.'.uniqid()]]]);
        });

        $json = json_encode(['entry' => [['changes' => [[
            'field' => 'messages',
            'value' => [
                'contacts' => [['wa_id' => '971500000001', 'profile' => ['name' => 'Ali']]],
                'messages' => [['id' => 'wamid.in.1', 'from' => '971500000001', 'timestamp' => (string) time(), 'type' => 'text', 'text' => ['body' => 'hi']]],
            ],
        ]]]]]);
        $this->call('POST', '/api/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $json, 'test-secret'),
        ], $json)->assertOk();

        $bodies = Http::recorded(fn (Request $r) => ($r->data()['to'] ?? null) === '971500000001' && isset($r->data()['text']))
            ->map(fn ($pair) => $pair[0]->data()['text']['body'])->values()->all();
        $this->assertSame(['hey bro', 'which day suits you?'], $bodies);
    }

    public function test_silent_means_no_message(): void
    {
        Http::fake(function (Request $r) {
            if (str_contains($r->url(), 'anthropic')) {
                $this->assertStringContainsString('READ THE ROOM', $r->data()['system']);

                return Http::response(['content' => [['type' => 'text', 'text' => '[SILENT]']], 'stop_reason' => 'end_turn']);
            }

            return Http::response(['messages' => [['id' => 'wamid.out.'.uniqid()]]]);
        });

        $json = json_encode(['entry' => [['changes' => [[
            'field' => 'messages',
            'value' => [
                'contacts' => [['wa_id' => '971500000002', 'profile' => ['name' => 'Sam']]],
                'messages' => [['id' => 'wamid.in.2', 'from' => '971500000002', 'timestamp' => (string) time(), 'type' => 'text', 'text' => ['body' => 'ok thanks']]],
            ],
        ]]]]]);
        $this->call('POST', '/api/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $json, 'test-secret'),
        ], $json)->assertOk();

        Http::assertNotSent(fn (Request $r) => ($r->data()['to'] ?? null) === '971500000002' && isset($r->data()['text']));
    }
}
