<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Services\Booking\AdminHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SavedContactsTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_1 = '971500000091';

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(function (Request $r) {
            if (str_contains($r->url(), 'anthropic')) {
                return Http::response(['content' => [['type' => 'text', 'text' => 'Hey! Welcome to Football Lab ⚽']], 'stop_reason' => 'end_turn']);
            }
            if (str_contains($r->url(), 'files.example')) {
                return Http::response("BEGIN:VCARD\r\nFN:Isa\r\nTEL;TYPE=CELL:+971 50 111 2222\r\nEND:VCARD\r\nBEGIN:VCARD\r\nFN:Rami\r\nitem1.TEL:050 333 4444\r\nEND:VCARD\r\n");
            }
            if (str_contains($r->url(), 'graph.facebook.com') && str_contains($r->url(), 'media-vcf')) {
                return Http::response(['url' => 'https://files.example/contacts.vcf', 'mime_type' => 'text/vcard']);
            }

            return Http::response(['messages' => [['id' => 'wamid.out.'.uniqid()]]]);
        });
        Contact::create(['wa_id' => self::ADMIN_1, 'last_inbound_at' => now()]);
    }

    public function test_contact_card_from_admin_makes_them_an_old_client(): void
    {
        $this->whatsapp(self::ADMIN_1, ['type' => 'contacts', 'contacts' => [
            ['name' => ['formatted_name' => 'Isa'], 'phones' => [['phone' => '+971 50 111 2222', 'wa_id' => '971501112222', 'type' => 'CELL']]],
        ]]);
        $isa = Contact::where('wa_id', '971501112222')->sole();
        $this->assertSame('old', $isa->client_type);
        $this->assertTrue($isa->canPayOnSpot());
        Http::assertSent(fn (Request $r) => ($r->data()['to'] ?? null) === self::ADMIN_1
            && str_contains($r->data()['text']['body'] ?? '', '1 old client'));

        $this->whatsapp('971501112222', ['type' => 'text', 'text' => ['body' => 'Hey bro, any game tonight?']]);

        // The bot answers as with a regular: returning stage, cash allowed, no "who is this?" question to admins.
        Http::assertSent(fn (Request $r) => ($r->data()['to'] ?? null) === '971501112222');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'anthropic')
            && str_contains(json_encode($r->data()), 'RETURNING player')
            && str_contains(json_encode($r->data()), 'this client may pay on the spot'));
        Http::assertNotSent(fn (Request $r) => str_contains(json_encode($r->data(), JSON_UNESCAPED_UNICODE), 'Do you know +971501112222'));
    }

    public function test_vcf_file_and_commands(): void
    {
        $this->whatsapp(self::ADMIN_1, ['type' => 'document', 'document' => ['id' => 'media-vcf', 'filename' => 'Contacts.vcf', 'mime_type' => 'text/x-vcard']]);
        $this->assertSame(['971501112222', '971503334444'], Contact::whereNotNull('saved_at')->orderBy('wa_id')->pluck('wa_id')->all());

        $this->whatsapp(self::ADMIN_1, ['type' => 'text', 'text' => ['body' => 'unsave 0503334444']]);
        $this->assertNull(Contact::where('wa_id', '971503334444')->sole()->saved_at);

        $this->whatsapp(self::ADMIN_1, ['type' => 'text', 'text' => ['body' => 'saved +971 50 555 6666']]);
        $this->assertNotNull(Contact::where('wa_id', '971505556666')->sole()->saved_at);
    }

    public function test_unsaved_new_number_still_gets_the_bot(): void
    {
        $this->whatsapp('971509998888', ['type' => 'text', 'text' => ['body' => 'Hi']]);
        Http::assertSent(fn (Request $r) => ($r->data()['to'] ?? null) === '971509998888'
            && str_contains($r->data()['text']['body'] ?? '', 'Welcome'));
    }

    public function test_vcf_parser(): void
    {
        $this->assertSame(['+971 50 1', '0502'], AdminHandler::vcfNumbers("TEL;TYPE=CELL:+971 50 1\nitem2.TEL:0502\nEMAIL:x@y.z"));
    }

    private function whatsapp(string $from, array $message): void
    {
        $json = json_encode(['entry' => [['changes' => [[
            'field' => 'messages',
            'value' => [
                'contacts' => [['wa_id' => $from, 'profile' => ['name' => 'Someone']]],
                'messages' => [$message + ['id' => 'wamid.in.'.uniqid(), 'from' => $from, 'timestamp' => (string) time()]],
            ],
        ]]]]]);

        $this->call('POST', '/api/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $json, 'test-secret'),
        ], $json)->assertOk();
    }
}
