<?php

namespace App\Services\WhatsApp;

/**
 * Normalised view of one inbound WhatsApp Cloud API message.
 * Pure PHP (no framework calls) so it can be unit-tested in isolation.
 */
final class InboundMessage
{
    public function __construct(
        public readonly string $id,
        public readonly string $from,
        public readonly ?string $profileName,
        public readonly string $type,
        public readonly ?string $text,
        public readonly ?string $buttonId,
        public readonly ?string $buttonTitle,
        public readonly ?string $mediaId,
        public readonly ?string $mediaMime,
        public readonly bool $forwarded,
        public readonly ?array $referral,
        public readonly int $timestamp,
        public readonly array $raw,
    ) {}

    /**
     * @param  array  $message  one item of value.messages[]
     * @param  array  $value    the change value (for value.contacts[])
     */
    public static function fromWebhook(array $message, array $value = []): self
    {
        $type = (string) ($message['type'] ?? 'unknown');
        $from = (string) ($message['from'] ?? '');

        $profileName = null;
        foreach ($value['contacts'] ?? [] as $contact) {
            if (($contact['wa_id'] ?? null) === $from) {
                $profileName = $contact['profile']['name'] ?? null;
            }
        }

        $text = null;
        $buttonId = null;
        $buttonTitle = null;
        $mediaId = null;
        $mediaMime = null;

        switch ($type) {
            case 'text':
                $text = $message['text']['body'] ?? null;
                break;
            case 'image':
            case 'document':
            case 'video':
            case 'sticker':
                $mediaId = $message[$type]['id'] ?? null;
                $mediaMime = $message[$type]['mime_type'] ?? null;
                $text = $message[$type]['caption'] ?? null;
                break;
            case 'audio':
                $mediaId = $message['audio']['id'] ?? null;
                $mediaMime = $message['audio']['mime_type'] ?? null;
                break;
            case 'interactive':
                $reply = $message['interactive']['button_reply'] ?? $message['interactive']['list_reply'] ?? [];
                $buttonId = $reply['id'] ?? null;
                $buttonTitle = $reply['title'] ?? null;
                $text = $buttonTitle;
                break;
            case 'button': // quick-reply button on a template
                $buttonId = $message['button']['payload'] ?? null;
                $buttonTitle = $message['button']['text'] ?? null;
                $text = $buttonTitle;
                break;
            case 'location':
                $loc = $message['location'] ?? [];
                $text = trim(($loc['name'] ?? '').' '.($loc['address'] ?? '')) ?: '[shared a location]';
                break;
            case 'reaction':
                $text = $message['reaction']['emoji'] ?? null;
                break;
        }

        return new self(
            id: (string) ($message['id'] ?? ''),
            from: $from,
            profileName: $profileName,
            type: $type,
            text: is_string($text) ? trim($text) : null,
            buttonId: $buttonId,
            buttonTitle: $buttonTitle,
            mediaId: $mediaId,
            mediaMime: $mediaMime,
            forwarded: (bool) (($message['context']['forwarded'] ?? false) || ($message['context']['frequently_forwarded'] ?? false)),
            referral: isset($message['referral']) && is_array($message['referral']) ? $message['referral'] : null,
            timestamp: (int) ($message['timestamp'] ?? time()),
            raw: $message,
        );
    }

    public function isButton(): bool
    {
        return $this->buttonId !== null && $this->buttonId !== '';
    }

    public function isPaymentProofCandidate(): bool
    {
        if ($this->type === 'image') {
            return true;
        }

        return $this->type === 'document' && in_array($this->mediaMime, ['application/pdf', 'image/jpeg', 'image/png'], true);
    }

    /** Short text used for conversation history and logs. */
    public function summary(): string
    {
        return match ($this->type) {
            'text', 'interactive', 'button', 'location' => (string) $this->text,
            'image' => '[sent an image]'.($this->text ? ' '.$this->text : ''),
            'document' => '[sent a document]'.($this->text ? ' '.$this->text : ''),
            'audio' => '[sent a voice note]',
            'video' => '[sent a video]'.($this->text ? ' '.$this->text : ''),
            'sticker' => '[sent a sticker]',
            'reaction' => '[reacted '.($this->text ?? '').']',
            default => '['.$this->type.' message]',
        };
    }
}
