<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper over the WhatsApp Cloud API. Returns the sent message id, or null on failure.
 *
 * provider "meta":      https://graph.facebook.com/{version}/{phone_id}/messages, Bearer token
 * provider "360dialog": https://waba-v2.360dialog.io/messages, D360-API-KEY header (same JSON bodies)
 * provider "ycloud":    https://api.ycloud.com/v2/whatsapp/messages/sendDirectly, X-API-Key header,
 *                       same message objects plus "from" (our number) and E.164 "to"
 */
class WhatsAppClient
{
    public function __construct(
        private readonly string $token,
        private readonly string $phoneNumberId,
        private readonly string $baseUrl,
        private readonly string $graphVersion,
        private readonly string $provider = 'meta',
        private readonly string $businessNumber = '',
    ) {}

    public function isYCloud(): bool
    {
        return $this->provider === 'ycloud';
    }

    public function is360(): bool
    {
        return $this->provider === '360dialog';
    }

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        return match (true) {
            $this->is360() => Http::withHeaders(['D360-API-KEY' => $this->token]),
            $this->isYCloud() => Http::withHeaders(['X-API-Key' => $this->token]),
            default => Http::withToken($this->token),
        };
    }

    public function send(array $payload): ?string
    {
        try {
            $response = $this->http()
                ->acceptJson()
                ->timeout(20)
                ->retry(2, 500, fn ($e) => $e instanceof \Illuminate\Http\Client\ConnectionException, throw: false)
                ->post($this->messagesUrl(), $this->isYCloud() ? $this->toYCloud($payload) : $payload);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            // Never let one network blip abort a loop (e.g. the second admin missing an approval request).
            Log::error('WhatsApp send failed: connection', ['error' => $e->getMessage(), 'to' => $payload['to'] ?? null]);

            return null;
        }

        if ($response->failed() || ($this->isYCloud() && $response->json('status') === 'failed')) {
            Log::error('WhatsApp send failed', [
                'status' => $response->status(),
                'error' => $response->json('error') ?? $response->json('errorMessage') ?? $response->json('whatsappApiError'),
                'to' => $payload['to'] ?? null,
                'type' => $payload['type'] ?? null,
            ]);

            return null;
        }

        return $this->isYCloud()
            ? ($response->json('wamid') ?? $response->json('id'))
            : $response->json('messages.0.id');
    }

    /** YCloud takes the Cloud API message object, minus envelope fields, plus our "from" number. */
    private function toYCloud(array $payload): array
    {
        unset($payload['messaging_product'], $payload['recipient_type']);
        $payload['from'] = $this->businessNumber;
        $payload['to'] = '+'.ltrim((string) ($payload['to'] ?? ''), '+');

        return $payload;
    }

    public function markRead(string $messageId): void
    {
        if ($this->isYCloud()) {
            $this->http()->timeout(10)->post('https://api.ycloud.com/v2/whatsapp/inboundMessages/'.rawurlencode($messageId).'/markAsRead');

            return;
        }

        $this->http()->timeout(10)->post($this->messagesUrl(), Payloads::markRead($messageId));
    }

    /**
     * Inbound media ids can't be forwarded directly: download the file, then upload it
     * to our own number so it can be sent to admins.
     *
     * @return array{id:string,mime:string}|null
     */
    public function reuploadMedia(string $inboundMediaId, ?string $downloadLink = null): ?array
    {
        if ($this->isYCloud()) {
            return $this->reuploadYCloud($downloadLink);
        }

        try {
            $meta = $this->http()->timeout(15)->get($this->path($inboundMediaId))->throw();
            $downloadUrl = $meta->json('url');
            $mime = $meta->json('mime_type') ?: 'image/jpeg';
            if (! $downloadUrl) {
                return null;
            }

            $bytes = $this->http()->timeout(30)->get($this->downloadUrl($downloadUrl))->throw()->body();
            $extension = match ($mime) {
                'image/png' => 'png',
                'application/pdf' => 'pdf',
                'image/webp' => 'webp',
                default => 'jpg',
            };

            $upload = $this->http()->timeout(30)
                ->attach('file', $bytes, 'payment-proof.'.$extension, ['Content-Type' => $mime])
                ->post($this->is360() ? $this->path('media') : $this->path($this->phoneNumberId.'/media'), [
                    'messaging_product' => 'whatsapp',
                    'type' => $mime,
                ])->throw();

            $id = $upload->json('id');

            return $id ? ['id' => (string) $id, 'mime' => (string) $mime] : null;
        } catch (RequestException|\Throwable $e) {
            Log::warning('Media re-upload failed', ['media_id' => $inboundMediaId, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** YCloud: inbound webhooks carry a short-lived download link; fetch it, then upload to our number. */
    private function reuploadYCloud(?string $link): ?array
    {
        if (! $link) {
            return null;
        }
        try {
            $file = $this->http()->timeout(30)->get($link)->throw();
            $mime = strtok((string) $file->header('Content-Type'), ';') ?: 'image/jpeg';
            $ext = match ($mime) { 'image/png' => 'png', 'application/pdf' => 'pdf', 'image/webp' => 'webp', default => 'jpg' };

            $upload = $this->http()->timeout(30)
                ->attach('file', $file->body(), 'payment-proof.'.$ext, ['Content-Type' => $mime])
                ->post('https://api.ycloud.com/v2/whatsapp/media/'.rawurlencode($this->businessNumber).'/upload')
                ->throw();

            return $upload->json('id') ? ['id' => (string) $upload->json('id'), 'mime' => $mime] : null;
        } catch (\Throwable $e) {
            Log::warning('YCloud media re-upload failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * YCloud: register our webhook. Returns the signing secret YCloud generates (null on failure).
     */
    public function createYCloudWebhook(string $url): ?string
    {
        $response = $this->http()->acceptJson()->timeout(20)->post('https://api.ycloud.com/v2/webhookEndpoints', [
            'url' => $url,
            'enabledEvents' => ['whatsapp.inbound_message.received', 'whatsapp.smb.message.echoes', 'whatsapp.message.updated'],
            'description' => 'FootballLab WhatsApp bot',
            'status' => 'active',
        ]);

        if ($response->failed()) {
            Log::error('YCloud webhook config failed', ['status' => $response->status(), 'body' => $response->body()]);

            return null;
        }

        return $response->json('secret');
    }

    /** 360dialog only: point our webhook at this app, with a shared secret header. */
    public function configureWebhook(string $url, string $secret): bool
    {
        $response = $this->http()->timeout(20)->post($this->path('v1/configs/webhook'), [
            'url' => $url,
            'headers' => ['X-Webhook-Secret' => $secret],
        ]);

        if ($response->failed()) {
            Log::error('Webhook config failed', ['status' => $response->status(), 'body' => $response->body()]);
        }

        return $response->successful();
    }

    private function messagesUrl(): string
    {
        if ($this->isYCloud()) {
            return 'https://api.ycloud.com/v2/whatsapp/messages/sendDirectly';
        }

        return $this->is360() ? $this->path('messages') : $this->path($this->phoneNumberId.'/messages');
    }

    private function path(string $path): string
    {
        $base = rtrim($this->baseUrl, '/');

        return $this->is360()
            ? $base.'/'.ltrim($path, '/')
            : $base.'/'.$this->graphVersion.'/'.ltrim($path, '/');
    }

    /** 360dialog proxies Meta's CDN: swap the lookaside host for theirs. */
    private function downloadUrl(string $url): string
    {
        if (! $this->is360()) {
            return $url;
        }
        $url = str_replace('\\', '', $url);

        return preg_replace('#^https://lookaside\.fbsbx\.com#', rtrim($this->baseUrl, '/'), $url) ?? $url;
    }
}
