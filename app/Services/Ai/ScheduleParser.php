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
    /** @param bool $past the post is about games already played (history import) */
    public function parse(string $post, bool $past = false): array
    {
        $tz = config('fbl.timezone');
        $today = now()->setTimezone($tz)->format('l Y-m-d');
        $venues = implode('; ', config('fbl.facts.venues'));
        $defaultCapacity = (int) config('fbl.defaults.capacity');
        $defaultPrice = (int) config('fbl.defaults.price');

        $system = <<<PROMPT
You extract football games from a WhatsApp group announcement for FootballLab DXB.
Today in Dubai is {$today}. {$this->dateRule($past)}
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
                                'format' => ['type' => ['string', 'null'], 'description' => 'e.g. 7-a-side, 8v8, 11v11. If the post says it is a tournament / cup / league day, write "tournament" (even if teams play 8v8).'],
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
            if (empty($g['venue']) || ($past
                ? ($startsAt->isFuture() || $startsAt->lt(now()->subDays((int) config('fbl.history_import_days', 31))))
                : $startsAt->isPast())) {
                continue;
            }
            $link = isset($g['payment_link']) && filter_var($g['payment_link'], FILTER_VALIDATE_URL) ? $g['payment_link'] : null;

            $result[] = [
                'starts_at' => $startsAt,
                'venue' => mb_substr(trim($g['venue']), 0, 150),
                // A tournament post stays a tournament even if the teams play 8v8.
                'format' => self::mentionsTournament(($g['format'] ?? '').' '.($g['notes'] ?? '')) || (count($games) === 1 && self::mentionsTournament($post))
                    ? 'tournament'
                    : \App\Models\Game::normalizeFormat($g['format'] ?? null),
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

    public static function mentionsTournament(string $text): bool
    {
        return (bool) preg_match('/tourn|torneo|tournoi|بطولة|\bcup\b|كأس/iu', $text);
    }

    private function dateRule(bool $past): string
    {
        return $past
            ? 'These games were ALREADY PLAYED in the last few weeks. Resolve weekday names to the MOST RECENT PAST occurrence (today counts only if the time has already passed). If the post has a date, use it.'
            : 'Resolve weekday names to the NEXT occurrence (today counts if the time is still ahead).';
    }
}
