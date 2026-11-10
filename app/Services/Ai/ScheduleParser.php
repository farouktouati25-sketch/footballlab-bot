<?php

namespace App\Services\Ai;

use Carbon\Carbon;

/** Turns a free-text group announcement into structured games, using a forced tool call. */
class ScheduleParser
{
    public function __construct(private readonly ClaudeClient $claude) {}

    /**
     * @return array<int, array{starts_at:Carbon, venue:string, format:?string, price:float, capacity:int, payment_link:?string, notes:?string, players:string[]}>
     */
    public function parse(string $post): array
    {
        $tz = config('fbl.timezone');
        $today = now()->setTimezone($tz)->format('l Y-m-d');
        $venues = implode('; ', config('fbl.facts.venues'));
        $defaultCapacity = (int) config('fbl.defaults.capacity');
        $defaultPrice = (int) config('fbl.defaults.price');

        $system = <<<PROMPT
You extract football games from a WhatsApp group announcement for FootballLab DXB.
Today in Dubai is {$today}. Resolve weekday names to the NEXT occurrence (today counts if the time is still ahead).
Known venues (use these exact names when the post clearly refers to one): {$venues}.
If price is missing use {$defaultPrice}.
Capacity: if the post has a numbered player list, capacity = the number of numbered slots in the MAIN list (filled or empty), not counting the waiting list. Otherwise use the number of spots/players stated, or {$defaultCapacity}.
players: the names already filled in the main list, in order, exactly as written (not the waiting list).
Times are Dubai local, 24h HH:MM. One entry per game. Ignore anything that is not a game.
Always answer by calling the save_games tool exactly once (with an empty list if there are no games). Never answer in plain text.
PROMPT;

        $tool = [
            'name' => 'save_games',
            'description' => 'Save the games found in the announcement.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'games' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                                'start_time' => ['type' => 'string', 'description' => 'HH:MM 24h, Dubai time'],
                                'venue' => ['type' => 'string'],
                                'format' => ['type' => ['string', 'null'], 'description' => 'e.g. 7-a-side'],
                                'price' => ['type' => 'number'],
                                'capacity' => ['type' => 'integer'],
                                'payment_link' => ['type' => ['string', 'null']],
                                'notes' => ['type' => ['string', 'null']],
                                'players' => [
                                    'type' => 'array',
                                    'items' => ['type' => 'string'],
                                    'description' => 'Names already written in the main numbered player list, in order, exactly as written. Exclude the waiting list and empty slots.',
                                ],
                            ],
                            'required' => ['date', 'start_time', 'venue', 'price', 'capacity'],
                        ],
                    ],
                ],
                'required' => ['games'],
            ],
        ];

        $response = $this->claude->messages(
            $system,
            [['role' => 'user', 'content' => $post]],
            [$tool],
            null, // forced tool_choice isn't supported on every model; the prompt requires the tool call
            2000,
        );

        $games = ClaudeClient::toolUses($response)[0]['input']['games'] ?? [];
        $result = [];

        foreach ($games as $g) {
            try {
                $startsAt = Carbon::createFromFormat('!Y-m-d H:i', trim($g['date']).' '.trim($g['start_time']), $tz)->utc();
            } catch (\Throwable) {
                continue;
            }
            if ($startsAt->isPast() || empty($g['venue'])) {
                continue;
            }
            $link = isset($g['payment_link']) && filter_var($g['payment_link'], FILTER_VALIDATE_URL) ? $g['payment_link'] : null;

            $result[] = [
                'starts_at' => $startsAt,
                'venue' => mb_substr(trim($g['venue']), 0, 150),
                'format' => \App\Models\Game::normalizeFormat($g['format'] ?? null),
                'price_stated' => isset($g['price']) && (float) $g['price'] !== (float) $defaultPrice,
                'price' => max(0, (float) ($g['price'] ?? $defaultPrice)),
                'capacity' => max(1, min(100, (int) ($g['capacity'] ?? $defaultCapacity))),
                'payment_link' => $link,
                'notes' => isset($g['notes']) ? mb_substr((string) $g['notes'], 0, 250) : null,
                'players' => array_values(array_filter(array_map(
                    fn ($n) => mb_substr(trim((string) $n), 0, 120),
                    is_array($g['players'] ?? null) ? $g['players'] : []
                ), fn ($n) => $n !== '')),
            ];
        }

        return $result;
    }
}
