<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class ClaudeClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
    ) {}

    /**
     * Calls the Messages API and returns the decoded response.
     *
     * @throws RuntimeException
     */
    public function messages(string $system, array $messages, array $tools = [], ?array $toolChoice = null, ?int $maxTokens = null): array
    {
        $body = [
            'model' => $this->model,
            'max_tokens' => $maxTokens ?? (int) config('claude.max_tokens', 700),
            'system' => $system,
            'messages' => $messages,
        ];
        if ($tools) {
            $body['tools'] = $tools;
        }
        if ($toolChoice) {
            $body['tool_choice'] = $toolChoice;
        }

        $response = Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'anthropic-version' => '2023-06-01',
        ])
            ->acceptJson()
            ->timeout(60)
            ->retry(2, 1000, fn ($e) => $e instanceof \Illuminate\Http\Client\ConnectionException
                || ($e instanceof \Illuminate\Http\Client\RequestException && in_array($e->response->status(), [429, 500, 529], true)), throw: false)
            ->post('https://api.anthropic.com/v1/messages', $body);

        if ($response->failed()) {
            throw new RuntimeException('Claude API error '.$response->status().': '.$response->body());
        }

        return $response->json();
    }

    /** Concatenated text blocks of a response. */
    public static function text(array $response): string
    {
        $parts = [];
        foreach ($response['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'text') {
                $parts[] = $block['text'];
            }
        }

        return trim(implode("\n", $parts));
    }

    /** @return array<int, array{id:string,name:string,input:array}> */
    public static function toolUses(array $response): array
    {
        return array_values(array_filter(
            $response['content'] ?? [],
            fn ($block) => ($block['type'] ?? null) === 'tool_use'
        ));
    }
}
