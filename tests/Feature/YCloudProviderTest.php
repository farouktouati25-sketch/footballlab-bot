<?php

namespace Tests\Feature;

use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class YCloudProviderTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'ycloud-signing-secret-1234567890';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'whatsapp.provider' => 'ycloud',
            'whatsapp.webhook_secret' => self::SECRET,
            'whatsapp.token' => 'ycloud-key',
            'whatsapp.business_number' => '+971503741554',
        ]);
        // The client singleton reads config when first resolved, so drop any instance built before.
        $this->app->forgetInstance(\App\Services\WhatsApp\WhatsAppClient::class);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'api.anthropic.com')) {
                return Http::response(['content' => [['type' => 'text', 'text' => 'Welcome to Football Lab ⚽']], 'stop_reason' => 'end_turn']);
            }
            if (str_contains($request->url(), 'sendDirectly')) {
                return Http::response(['id' => 'yc_1', 'wamid' => 'wamid.out.'.uniqid(), 'status' => 'accepted']);
            }

            return Http::response([], 200);
        });
    }

    public function test_inbound_message_is_answered_through_ycloud(): void
    {
        $this->post_([
            'id' => 'evt_1',
            'type' => 'whatsapp.inbound_message.received',
            'apiVersion' => 'v2',
            'createTime' => now()->toIso8601String(),
            'whatsappInboundMessage' => [
                'id' => '63f872f6',
                'wamid' => 'wamid.IN1',
                'from' => '+971500000001',
                'customerProfile' => ['name' => 'Joe'],
                'to' => '+971503741554',
                'sendTime' => now()->toIso8601String(),
                'type' => 'text',
                'text' => ['body' => 'Hi, any games?'],
                'referral' => ['source_type' => 'ad', 'source_id' => 'AD1'],
            ],
        ])->assertOk();

        $contact = Contact::where('wa_id', '971500000001')->sole();
        $this->assertSame('Joe', $contact->name);
        $this->assertSame('meta_ad', $contact->source);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'api.ycloud.com/v2/whatsapp/messages/sendDirectly')
            && $r->header('X-API-Key')[0] === 'ycloud-key'
            && $r->data()['from'] === '+971503741554'
            && $r->data()['to'] === '+971500000001'
            && $r->data()['text']['body'] === 'Welcome to Football Lab ⚽'
            && ! isset($r->data()['messaging_product']));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'inboundMessages/wamid.IN1/markAsRead'));
    }

    public function test_business_app_echo_pauses_the_bot(): void
    {
        $this->post_([
            'id' => 'evt_2',
            'type' => 'whatsapp.smb.message.echoes',
            'whatsappMessage' => ['wamid' => 'wamid.ECHO1', 'from' => '+971503741554', 'to' => '+971500000001', 'type' => 'text', 'text' => ['body' => 'Farouk here']],
        ])->assertOk();

        $this->assertTrue(Contact::where('wa_id', '971500000001')->sole()->botIsPaused());
    }

    public function test_rejects_bad_or_stale_signatures(): void
    {
        $body = json_encode(['id' => 'evt_3', 'type' => 'whatsapp.inbound_message.received']);

        $this->call('POST', '/api/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_YCLOUD_SIGNATURE' => 't='.time().',s=deadbeef',
        ], $body)->assertStatus(401);

        $old = time() - 3600;
        $this->call('POST', '/api/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_YCLOUD_SIGNATURE' => 't='.$old.',s='.hash_hmac('sha256', $old.'.'.$body, self::SECRET),
        ], $body)->assertStatus(401);
    }

    private function post_(array $payload)
    {
        $json = json_encode($payload);
        $t = time();

        return $this->call('POST', '/api/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_YCLOUD_SIGNATURE' => "t={$t},s=".hash_hmac('sha256', $t.'.'.$json, self::SECRET),
        ], $json);
    }
}
