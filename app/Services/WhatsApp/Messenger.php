<?php

namespace App\Services\WhatsApp;

use App\Models\Contact;
use App\Models\Message;

/**
 * All outbound messages go through here so that:
 *  - every message is logged (the AI uses this as conversation history),
 *  - the 24h window is respected (templates are used when it's closed).
 */
class Messenger
{
    public function __construct(private readonly WhatsAppClient $client) {}

    /** Free-form text. Only valid inside the 24h window. */
    public function text(Contact $to, string $body, string $author = 'bot', array $meta = []): bool
    {
        if (! $to->windowIsOpen()) {
            return false;
        }

        return $this->dispatch($to, Payloads::text($to->wa_id, $body), 'text', $body, $author, $meta);
    }

    /** @param array<string,string> $buttons id => title */
    public function buttons(Contact $to, string $body, array $buttons, ?string $headerImageId = null, ?string $footer = null): bool
    {
        if (! $to->windowIsOpen()) {
            return false;
        }

        return $this->dispatch(
            $to,
            Payloads::buttons($to->wa_id, $body, $buttons, $headerImageId, $footer),
            'interactive',
            $body,
            'bot',
            ['buttons' => $buttons]
        );
    }

    public function media(Contact $to, string $mediaId, string $mime, ?string $caption = null): bool
    {
        if (! $to->windowIsOpen()) {
            return false;
        }

        $payload = str_starts_with($mime, 'image/')
            ? Payloads::image($to->wa_id, $mediaId, $caption)
            : Payloads::document($to->wa_id, $mediaId, 'payment-proof.pdf', $caption);

        return $this->dispatch($to, $payload, $payload['type'], $caption, 'bot', ['media_id' => $mediaId]);
    }

    /** Send a generated file (CSV etc.) as a document. Only inside the 24h window. */
    public function file(Contact $to, string $bytes, string $filename, string $mime, ?string $caption = null): bool
    {
        if (! $to->windowIsOpen()) {
            return false;
        }
        $mediaId = $this->client->uploadBytes($bytes, $filename, $mime);
        if (! $mediaId) {
            return false;
        }

        return $this->dispatch($to, Payloads::document($to->wa_id, $mediaId, $filename, $caption), 'document', $caption, 'bot', ['file' => $filename]);
    }

    /** Templates work at any time (they must be approved in WhatsApp Manager first). */
    public function template(Contact $to, string $name, array $bodyParams = [], array $quickReplyPayloads = [], array $meta = []): bool
    {
        $payload = Payloads::template(
            $to->wa_id,
            $name,
            (string) config('whatsapp.templates.language', 'en'),
            $bodyParams,
            $quickReplyPayloads
        );

        return $this->dispatch(
            $to,
            $payload,
            'template',
            $name.': '.implode(' | ', array_map('strval', $bodyParams)),
            'bot',
            $meta + ['template' => $name, 'payloads' => $quickReplyPayloads]
        );
    }

    /** Text if the window is open, otherwise the generic admin_alert template. */
    public function alertAdmin(Contact $admin, string $text, array $meta = []): bool
    {
        return $admin->windowIsOpen()
            ? $this->text($admin, $text, 'bot', $meta)
            : $this->template($admin, (string) config('whatsapp.templates.admin_alert'), [$text], [], $meta);
    }

    /** Text if the window is open, otherwise the booking_update template. */
    public function updateClient(Contact $client, string $reference, string $text): bool
    {
        if ($client->windowIsOpen()) {
            return $this->text($client, $text);
        }

        return $this->template($client, (string) config('whatsapp.templates.client_update'), [
            $client->name ?: 'there',
            $reference,
            $text,
        ]);
    }

    /** @return Contact[] */
    public function adminContacts(): array
    {
        return array_map(
            fn (string $waId) => Contact::firstOrCreate(['wa_id' => $waId]),
            config('whatsapp.admins', [])
        );
    }

    private function dispatch(Contact $to, array $payload, string $type, ?string $body, string $author, array $extra = []): bool
    {
        $id = $this->client->send($payload);

        Message::create([
            'contact_id' => $to->id,
            'direction' => 'out',
            'author' => $author,
            'type' => $type,
            'body' => $body,
            'wa_message_id' => $id,
            'payload' => $extra + ['sent' => $id !== null],
        ]);

        return $id !== null;
    }
}
