<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessInboundMessage;
use App\Models\Contact;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController extends Controller
{
    /** Meta calls this once when you save the webhook URL. */
    public function verify(Request $request)
    {
        $token = (string) config('whatsapp.verify_token');
        if ($token === '') {
            return response('Forbidden', 403);
        }

        if ($request->query('hub_mode') === 'subscribe'
            && hash_equals($token, (string) $request->query('hub_verify_token'))) {
            return response((string) $request->query('hub_challenge'), 200)->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', 403);
    }

    public function receive(Request $request)
    {
        if (! $this->signatureIsValid($request)) {
            Log::warning('WhatsApp webhook rejected: bad signature');

            return response('Invalid signature', 401);
        }

        if (config('whatsapp.provider') === 'ycloud') {
            $this->receiveYCloud($request);

            return response('OK', 200);
        }

        foreach ($request->input('entry', []) as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $field = $change['field'] ?? null;
                $value = $change['value'] ?? [];

                if ($field === 'messages') {
                    foreach ($value['messages'] ?? [] as $message) {
                        ProcessInboundMessage::dispatch($message, ['contacts' => $value['contacts'] ?? []]);
                    }
                    foreach ($value['statuses'] ?? [] as $status) {
                        if (($status['status'] ?? null) === 'failed') {
                            Log::warning('WhatsApp delivery failed', [
                                'to' => $status['recipient_id'] ?? null,
                                'errors' => $status['errors'] ?? null,
                            ]);
                        }
                    }
                }

                // Coexistence: a human replied from the WhatsApp Business app on the phone.
                if ($field === 'smb_message_echoes') {
                    foreach ($value['message_echoes'] ?? [] as $echo) {
                        $this->recordHumanReply($echo);
                    }
                }
            }
        }

        // Always 200 fast, or Meta retries and eventually disables the webhook.
        return response('OK', 200);
    }

    /** YCloud wraps Cloud API message objects in its own event envelope. */
    private function receiveYCloud(Request $request): void
    {
        $type = $request->input('type');

        if ($type === 'whatsapp.smb.history') {
            $this->storeHistory($request);

            return;
        }

        if ($type === 'whatsapp.inbound_message.received') {
            $m = (array) $request->input('whatsappInboundMessage', []);
            $from = ltrim((string) ($m['from'] ?? ''), '+');
            // Reshape to the Cloud API message the rest of the app understands; the wamid is the message id.
            $message = $m;
            $message['id'] = (string) ($m['wamid'] ?? $m['id'] ?? '');
            $message['from'] = $from;
            if (! isset($message['timestamp']) && isset($m['sendTime'])) {
                $message['timestamp'] = (string) strtotime((string) $m['sendTime']);
            }
            ProcessInboundMessage::dispatch($message, ['contacts' => [[
                'wa_id' => $from,
                'profile' => ['name' => $m['customerProfile']['name'] ?? null],
            ]]]);
        }

        if ($type === 'whatsapp.smb.message.echoes') {
            $m = (array) $request->input('whatsappMessage', []);
            $this->recordHumanReply([
                'to' => ltrim((string) ($m['to'] ?? ''), '+'),
                'id' => (string) ($m['wamid'] ?? $m['id'] ?? ''),
                'type' => $m['type'] ?? 'text',
                'text' => $m['text'] ?? null,
            ]);
        }

        if ($type === 'whatsapp.message.updated' && $request->input('whatsappMessage.status') === 'failed') {
            Log::warning('WhatsApp delivery failed', [
                'to' => $request->input('whatsappMessage.to'),
                'error' => $request->input('whatsappMessage.errorMessage'),
            ]);
        }
    }

    /**
     * Past WhatsApp Business app chats (coexistence history sync). Stored as conversation history only:
     * no reply, no 24h window, no bot pause. Lets the bot recognise people we've talked to before.
     */
    private function storeHistory(Request $request): void
    {
        $in = $request->input('whatsappInboundMessage');
        $out = $request->input('whatsappMessage');
        $m = is_array($in) ? $in : (is_array($out) ? $out : null);
        if (! $m) {
            return; // progress-only chunk
        }

        $wamid = (string) ($m['wamid'] ?? '');
        if ($wamid === '' || Message::where('wa_message_id', $wamid)->exists()) {
            return;
        }

        $waId = ltrim((string) (is_array($in) ? ($m['from'] ?? '') : ($m['to'] ?? '')), '+');
        if ($waId === '' || $waId === ltrim((string) config('whatsapp.business_number'), '+')) {
            return;
        }

        $contact = Contact::firstOrCreate(['wa_id' => $waId]);
        if (is_array($in) && ! $contact->name && ! empty($m['customerProfile']['name'])) {
            $contact->update(['name' => $m['customerProfile']['name']]);
        }

        $type = (string) ($m['type'] ?? 'text');
        $body = $m['text']['body'] ?? $m[$type]['caption'] ?? '['.$type.']';
        $sentAt = rescue(fn () => \Carbon\Carbon::parse($m['sendTime'] ?? $m['createTime'] ?? 'now'), now(), false);

        $msg = new Message([
            'contact_id' => $contact->id,
            'direction' => is_array($in) ? 'in' : 'out',
            'author' => is_array($in) ? 'client' : 'human',
            'type' => $type,
            'body' => $body,
            'wa_message_id' => $wamid,
            'payload' => ['history' => true],
            'processed_at' => now(),
        ]);
        $msg->created_at = $sentAt;
        $msg->updated_at = now();
        try {
            $msg->save();
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // delivered twice in parallel
        }
    }

    private function recordHumanReply(array $echo): void
    {
        $to = (string) ($echo['to'] ?? '');
        $id = (string) ($echo['id'] ?? '');
        if ($to === '' || ($id !== '' && Message::where('wa_message_id', $id)->exists())) {
            return;
        }

        $contact = Contact::firstOrCreate(['wa_id' => $to]);
        $type = (string) ($echo['type'] ?? 'text');
        $body = $echo['text']['body'] ?? null;
        // The admin's invite from the list (checked first: the same copied text goes to many people).
        $invite = app(\App\Services\Booking\ReminderService::class)->isInviteEcho($contact);
        $automatic = ! $invite && $this->isAutoReply($contact, $body);

        Message::create([
            'contact_id' => $contact->id,
            'direction' => 'out',
            'author' => 'human',
            'type' => $type,
            'body' => $body ?? '['.$type.' sent by team]',
            'wa_message_id' => $id ?: null,
            'payload' => ['echo' => true, 'auto' => $automatic, 'invite' => $invite],
        ]);

        if ($automatic || $invite) {
            // Greeting/away message, or the admin's first invite from the list: the bot answers their reply.
            return;
        }

        // The team took over this chat: keep the bot quiet for a while, and stop the hand-off reminders.
        $contact->update(['bot_paused_until' => now()->addHours((int) config('fbl.human_takeover_hours', 12)), 'handoff_at' => null]);
    }

    /**
     * Greeting/away messages from the WhatsApp Business app come back as echoes too.
     * Treat an echo as automatic when it matches a configured auto-reply text, or when it is a
     * canned text already sent word-for-word to another contact AND this chat has no earlier
     * outgoing message in 14 days (that's when the greeting fires). Quick replies in an active chat
     * still count as the team taking over.
     */
    private function isAutoReply(Contact $contact, ?string $body): bool
    {
        $body = trim((string) $body);
        if ($body === '') {
            return false;
        }
        foreach (config('fbl.auto_reply_texts', []) as $prefix) {
            if (str_starts_with(mb_strtolower($body), mb_strtolower(mb_substr($prefix, 0, 60)))) {
                return true;
            }
        }

        $hadOutbound = Message::where('contact_id', $contact->id)->where('direction', 'out')
            ->where('created_at', '>', now()->subDays(14))->exists();
        if ($hadOutbound) {
            return false;
        }

        return Message::where('direction', 'out')->where('author', 'human')->where('contact_id', '!=', $contact->id)
            ->where('body', $body)->where('created_at', '>', now()->subDays(60))->exists();
    }

    private function signatureIsValid(Request $request): bool
    {
        if (config('whatsapp.provider') === 'ycloud') {
            // YCloud-Signature: t=<unix seconds>,s=<hex hmac_sha256(secret, "t.<raw body>")>
            $secret = (string) config('whatsapp.webhook_secret');
            $parts = [];
            foreach (explode(',', (string) $request->header('YCloud-Signature', '')) as $kv) {
                [$k, $v] = array_pad(explode('=', trim($kv), 2), 2, '');
                $parts[$k] = $v;
            }
            $t = $parts['t'] ?? '';
            $s = $parts['s'] ?? '';
            if ($secret === '' || $t === '' || $s === '' || abs(time() - (int) $t) > 300) {
                return false;
            }

            return hash_equals(hash_hmac('sha256', $t.'.'.$request->getContent(), $secret), strtolower($s));
        }

        if (config('whatsapp.provider') === '360dialog') {
            $shared = (string) config('whatsapp.webhook_secret');

            return $shared !== '' && hash_equals($shared, (string) $request->header('X-Webhook-Secret', ''));
        }

        $secret = (string) config('whatsapp.app_secret');

        if ($secret === '') {
            // Only tolerated locally. In production a missing secret means anyone could post fake messages.
            return app()->environment(['local', 'testing']);
        }

        $header = (string) $request->header('X-Hub-Signature-256', '');
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        return $header !== '' && hash_equals($expected, $header);
    }
}
