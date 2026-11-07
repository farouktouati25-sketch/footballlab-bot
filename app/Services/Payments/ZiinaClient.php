<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Ziina REST API: one payment intent (hosted pay page) per booking, exact amount. */
class ZiinaClient
{
    private const BASE = 'https://api-v2.ziina.com/api';

    public function __construct(
        private readonly string $token,
        private readonly bool $testMode = false,
    ) {}

    public function enabled(): bool
    {
        return $this->token !== '';
    }

    /**
     * @param  int  $amountFils  amount in fils (AED × 100)
     * @return array{id:string,url:string}|null
     */
    public function createIntent(int $amountFils, string $message, ?int $expiresAtMs = null, ?string $successUrl = null): ?array
    {
        $body = array_filter([
            'amount' => $amountFils,
            'currency_code' => 'AED',
            'message' => mb_substr($message, 0, 120),
            'success_url' => $successUrl,
            'cancel_url' => $successUrl,
            'expiry' => $expiresAtMs ? (string) $expiresAtMs : null,
            'test' => $this->testMode ?: null,
        ], fn ($v) => $v !== null);

        try {
            $response = Http::withToken($this->token)->acceptJson()->timeout(20)
                ->post(self::BASE.'/payment_intent', $body);
        } catch (\Throwable $e) {
            Log::error('Ziina create intent failed: connection', ['error' => $e->getMessage()]);

            return null;
        }

        if ($response->failed() || ! $response->json('id') || ! $response->json('redirect_url')) {
            Log::error('Ziina create intent failed', ['status' => $response->status(), 'body' => $response->body()]);

            return null;
        }

        return ['id' => (string) $response->json('id'), 'url' => (string) $response->json('redirect_url')];
    }

    /** Current status: requires_payment_instrument | requires_user_action | pending | completed | failed | canceled */
    public function status(string $intentId): ?string
    {
        try {
            $response = Http::withToken($this->token)->acceptJson()->timeout(15)
                ->get(self::BASE.'/payment_intent/'.rawurlencode($intentId));
        } catch (\Throwable) {
            return null;
        }

        return $response->successful() ? $response->json('status') : null;
    }

    public function registerWebhook(string $url, string $secret): bool
    {
        $response = Http::withToken($this->token)->acceptJson()->timeout(20)
            ->post(self::BASE.'/webhook', ['url' => $url, 'secret' => $secret]);

        if (! $response->successful() || $response->json('success') === false) {
            Log::error('Ziina webhook registration failed', ['status' => $response->status(), 'body' => $response->body()]);

            return false;
        }

        return true;
    }
}
