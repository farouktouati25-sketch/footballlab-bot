<?php

namespace Tests\Unit;

use App\Services\Ai\ConversationHistory;
use App\Services\WhatsApp\InboundMessage;
use App\Services\WhatsApp\Payloads;
use PHPUnit\Framework\TestCase;

class PayloadsAndParsingTest extends TestCase
{
    public function test_parses_text_with_ad_referral_and_profile_name(): void
    {
        $m = InboundMessage::fromWebhook(
            ['id' => 'w1', 'from' => '971500000001', 'type' => 'text', 'text' => ['body' => ' hi '],
                'referral' => ['source_type' => 'ad', 'source_id' => '123']],
            ['contacts' => [['wa_id' => '971500000001', 'profile' => ['name' => 'Ali']]]]
        );

        $this->assertSame('hi', $m->text);
        $this->assertSame('Ali', $m->profileName);
        $this->assertSame('123', $m->referral['source_id']);
    }

    public function test_parses_interactive_and_template_buttons(): void
    {
        $a = InboundMessage::fromWebhook(['id' => 'a', 'from' => '1', 'type' => 'interactive',
            'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'approve:5', 'title' => 'Confirm']]]);
        $b = InboundMessage::fromWebhook(['id' => 'b', 'from' => '1', 'type' => 'button',
            'button' => ['payload' => 'reject:5', 'text' => 'Reject']]);

        $this->assertSame('approve:5', $a->buttonId);
        $this->assertSame('reject:5', $b->buttonId);
    }

    public function test_image_is_payment_proof_candidate(): void
    {
        $m = InboundMessage::fromWebhook(['id' => 'c', 'from' => '1', 'type' => 'image',
            'image' => ['id' => 'M1', 'mime_type' => 'image/jpeg', 'caption' => 'paid'], 'context' => ['forwarded' => true]]);

        $this->assertTrue($m->isPaymentProofCandidate());
        $this->assertTrue($m->forwarded);
        $this->assertSame('[sent an image] paid', $m->summary());
    }

    public function test_button_limits_are_enforced(): void
    {
        $p = Payloads::buttons('971', str_repeat('x', 2000), ['a' => 'A very long button title here', 'b' => 'B', 'c' => 'C', 'd' => 'D']);

        $this->assertCount(3, $p['interactive']['action']['buttons']);
        $this->assertLessThanOrEqual(20, mb_strlen($p['interactive']['action']['buttons'][0]['reply']['title']));
        $this->assertLessThanOrEqual(1024, mb_strlen($p['interactive']['body']['text']));
    }

    public function test_template_params_are_sanitised_and_payloads_indexed(): void
    {
        $t = Payloads::template('971', 'booking_approval_request', 'en', ["Line1\nLine2", '  a    b '], ['approve:1', 'reject:1']);
        $c = $t['template']['components'];

        $this->assertSame('Line1 · Line2', $c[0]['parameters'][0]['text']);
        $this->assertSame('a b', $c[0]['parameters'][1]['text']);
        $this->assertSame('0', $c[1]['index']);
        $this->assertSame('reject:1', $c[2]['parameters'][0]['payload']);
    }

    public function test_history_alternates_and_starts_and_ends_with_user(): void
    {
        $h = ConversationHistory::build([
            ['author' => 'bot', 'body' => 'old'],
            ['author' => 'client', 'body' => 'hi'],
            ['author' => 'client', 'body' => 'price?'],
            ['author' => 'admin', 'body' => 'ignored'],
            ['author' => 'bot', 'body' => '50'],
            ['author' => 'human', 'body' => 'call me'],
            ['author' => 'client', 'body' => 'ok'],
            ['author' => 'bot', 'body' => 'trailing'],
        ]);

        $this->assertCount(3, $h);
        $this->assertSame(['role' => 'user', 'content' => "hi\nprice?"], $h[0]);
        $this->assertStringContainsString('team member] call me', $h[1]['content']);
        $this->assertSame('ok', $h[2]['content']);
    }
}
