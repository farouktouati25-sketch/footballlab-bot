<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Message;
use App\Services\Ai\BookingAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class YCloudHistoryTest extends TestCase
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
        $this->app->forgetInstance(\App\Services\WhatsApp\WhatsAppClient::class);
        Http::fake(fn () => Http::response(['id' => 'x', 'wamid' => 'wamid.out', 'status' => 'accepted']));
    }

    public function test_history_is_stored_silently_and_marks_the_contact_as_known(): void
    {
        $old = now()->subMonths(2)->toIso8601String();

        $this->event(['id' => 'evt_h1', 'type' => 'whatsapp.smb.history', 'phase' => 1, 'progress' => 40,
            'whatsappInboundMessage' => ['wamid' => 'wamid.H1', 'from' => '+971500000001', 'to' => '+971503741554',
                'sendTime' => $old, 'type' => 'text', 'text' => ['body' => 'Is there a game Friday?'],
                'customerProfile' => ['name' => 'Joe']]])->assertOk();

        $this->event(['id' => 'evt_h2', 'type' => 'whatsapp.smb.history',
            'whatsappMessage' => ['wamid' => 'wamid.H2', 'from' => '+971503741554', 'to' => '+971500000001',
                'sendTime' => $old, 'type' => 'text', 'text' => ['body' => 'Yes bro, 9pm Al Safa']]])->assertOk();

        // duplicate and progress-only chunks are ignored
        $this->event(['id' => 'evt_h1b', 'type' => 'whatsapp.smb.history',
            'whatsappInboundMessage' => ['wamid' => 'wamid.H1', 'from' => '+971500000001', 'type' => 'text', 'text' => ['body' => 'dup']]])->assertOk();
        $this->event(['id' => 'evt_h3', 'type' => 'whatsapp.smb.history', 'phase' => 2, 'progress' => 100])->assertOk();

        $contact = Contact::where('wa_id', '971500000001')->sole();
        $this->assertSame('Joe', $contact->name);
        $this->assertSame(2, $contact->messages()->count());
        $this->assertTrue(Message::where('wa_message_id', 'wamid.H1')->sole()->created_at->lt(now()->subMonth()));
        $this->assertNull($contact->last_inbound_at);
        $this->assertFalse($contact->botIsPaused());
        $this->assertTrue(app(BookingAgent::class)->isReturning($contact));

        Http::assertNothingSent();
    }

    private function event(array $payload)
    {
        $json = json_encode($payload);
        $t = time();

        return $this->call('POST', '/api/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_YCLOUD_SIGNATURE' => "t={$t},s=".hash_hmac('sha256', $t.'.'.$json, self::SECRET),
        ], $json);
    }
}
