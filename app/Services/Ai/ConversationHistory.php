<?php

namespace App\Services\Ai;

/**
 * Turns logged WhatsApp messages into a valid Claude `messages` array:
 * alternating roles, starting and ending with the user. Pure PHP, unit-testable.
 */
final class ConversationHistory
{
    /**
     * @param  array<int, array{author:string, body:?string}>  $rows  oldest first
     */
    public static function build(array $rows): array
    {
        $messages = [];

        foreach ($rows as $row) {
            $body = trim((string) ($row['body'] ?? ''));
            if ($body === '') {
                continue;
            }

            $role = match ($row['author'] ?? '') {
                'client' => 'user',
                'bot' => 'assistant',
                'human' => 'assistant',
                default => null,
            };
            if ($role === null) {
                continue;
            }

            if (($row['author'] ?? '') === 'human') {
                $body = '[Reply sent by a FootballLab team member] '.$body;
            }

            $last = array_key_last($messages);
            if ($last !== null && $messages[$last]['role'] === $role) {
                $messages[$last]['content'] .= "\n".$body;
            } else {
                $messages[] = ['role' => $role, 'content' => $body];
            }
        }

        while ($messages && $messages[0]['role'] !== 'user') {
            array_shift($messages);
        }
        while ($messages && end($messages)['role'] !== 'user') {
            array_pop($messages);
        }

        return $messages;
    }
}
