<?php

namespace App\Services\WhatsApp;

/**
 * Builds Cloud API /messages request bodies. Pure PHP, unit-testable.
 * Limits enforced here: reply-button title <= 20 chars, max 3 buttons, body <= 1024 chars.
 */
final class Payloads
{
    public static function text(string $to, string $body): array
    {
        return [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'text',
            'text' => ['preview_url' => true, 'body' => self::limit($body, 4096)],
        ];
    }

    /**
     * @param  array<string,string>  $buttons  id => title (max 3)
     */
    public static function buttons(string $to, string $body, array $buttons, ?string $headerImageId = null, ?string $footer = null): array
    {
        $rows = [];
        foreach (array_slice($buttons, 0, 3, true) as $id => $title) {
            $rows[] = ['type' => 'reply', 'reply' => ['id' => self::limit((string) $id, 256), 'title' => self::limit($title, 20)]];
        }

        $interactive = [
            'type' => 'button',
            'body' => ['text' => self::limit($body, 1024)],
            'action' => ['buttons' => $rows],
        ];

        if ($headerImageId) {
            $interactive['header'] = ['type' => 'image', 'image' => ['id' => $headerImageId]];
        }
        if ($footer) {
            $interactive['footer'] = ['text' => self::limit($footer, 60)];
        }

        return [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'interactive',
            'interactive' => $interactive,
        ];
    }

    public static function image(string $to, string $mediaId, ?string $caption = null): array
    {
        $image = ['id' => $mediaId];
        if ($caption) {
            $image['caption'] = self::limit($caption, 1024);
        }

        return [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'image',
            'image' => $image,
        ];
    }

    public static function document(string $to, string $mediaId, string $filename, ?string $caption = null): array
    {
        $document = ['id' => $mediaId, 'filename' => $filename];
        if ($caption) {
            $document['caption'] = self::limit($caption, 1024);
        }

        return [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'document',
            'document' => $document,
        ];
    }

    /**
     * @param  string[]  $bodyParams       values for {{1}}, {{2}}, ...
     * @param  string[]  $quickReplyPayloads payload per quick-reply button, by index
     */
    public static function template(string $to, string $name, string $language, array $bodyParams = [], array $quickReplyPayloads = []): array
    {
        $components = [];

        if ($bodyParams) {
            $components[] = [
                'type' => 'body',
                'parameters' => array_map(
                    fn ($v) => ['type' => 'text', 'text' => self::templateParam((string) $v)],
                    array_values($bodyParams)
                ),
            ];
        }

        foreach (array_values($quickReplyPayloads) as $index => $payload) {
            $components[] = [
                'type' => 'button',
                'sub_type' => 'quick_reply',
                'index' => (string) $index,
                'parameters' => [['type' => 'payload', 'payload' => (string) $payload]],
            ];
        }

        $template = ['name' => $name, 'language' => ['code' => $language]];
        if ($components) {
            $template['components'] = $components;
        }

        return [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'template',
            'template' => $template,
        ];
    }

    public static function markRead(string $messageId): array
    {
        return ['messaging_product' => 'whatsapp', 'status' => 'read', 'message_id' => $messageId];
    }

    /** Template params may not contain newlines, tabs or more than 4 consecutive spaces. */
    public static function templateParam(string $value): string
    {
        $value = preg_replace('/[\r\n\t]+/u', ' · ', $value) ?? $value;
        $value = preg_replace('/ {2,}/u', ' ', $value) ?? $value;
        $value = trim($value, " ·");

        return $value === '' ? '-' : self::limit($value, 1000);
    }

    public static function limit(string $value, int $max): string
    {
        return mb_strlen($value) <= $max ? $value : rtrim(mb_substr($value, 0, $max - 1)).'…';
    }
}
